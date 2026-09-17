<?php

require_once 'ArticleProcessor.php';

/**
 * ArticleImporter — importazione di articoli da una cartella.
 *
 * Consente di pubblicare articoli depositando file Markdown in una
 * cartella di import (default: fp-content/content/import-in/ o un
 * percorso configurabile).
 *
 * Flusso:
 * 1. Scansiona la cartella di import per file .md / .markdown / .txt
 * 2. Per ogni file:
 *    a. legge il contenuto Markdown (frontmatter + body)
 *    b. importa tutte le immagini della cartella nella cartella immagini di FlatPress
 *    c. pubblica l'articolo tramite ArticleProcessor::process()
 *    d. sposta il file in una sottocartella "done" (o in errore in "failed");
 *       gli articoli con data futura restano nella cartella di import e
 *       vengono pubblicati alla prima scansione dopo la scadenza
 *
 * In questo modo gli articoli possono essere caricati via FTP/SFTP/scp
 * nella cartella di import, e il plugin li pubblica automaticamente
 * all'avvio (hook init).
 */
class ArticleImporter {

    /** @var ArticleProcessor */
    private $processor;

    /** @var string Cartella di import */
    private $importDir;

    /** @var string Sottocartella per i file elaborati */
    private $doneSubdir = 'done';

    /** @var string Sottocartella per i file che hanno generato errori */
    private $failedSubdir = 'failed';

    /** @var string Default category ID */
    private $defaultCategory = '';

    /** @var string Default article status */
    private $defaultStatus = 'publish';

    /** Estensioni Markdown riconosciute */
    private $markdownExtensions = ['md', 'markdown', 'mdown', 'txt'];

    /**
     * @param ArticleProcessor|null $processor Processore da usare (creato se null)
     * @param string|null $importDir Cartella di import (default: CONTENT_DIR . 'import-in/')
     * @param array $options Plugin options
     */
    public function __construct($processor = null, $importDir = null, $options = []) {
        $this->processor = $processor !== null ? $processor : new ArticleProcessor();
        $this->importDir = (!empty($importDir)) ? rtrim($importDir, '/\\') . '/' : $this->getDefaultImportDir();

        if (isset($options['default_category'])) {
            $this->defaultCategory = $options['default_category'];
        }
        if (isset($options['default_status'])) {
            $this->defaultStatus = $options['default_status'];
        }
        if (isset($options['done_subdir'])) {
            $this->doneSubdir = $options['done_subdir'];
        }
        if (isset($options['failed_subdir'])) {
            $this->failedSubdir = $options['failed_subdir'];
        }
    }

    /**
     * Returns the default import folder.
     * Path: <content_root>/import-in/
     *
     * @return string Import directory
     */
    public function getDefaultImportDir() {
        $root = defined('CONTENT_DIR') ? CONTENT_DIR : 'fp-content/content/';
        return rtrim($root, '/\\') . '/import-in/';
    }

    /**
     * Returns the import directory.
     *
     * @return string
     */
    public function getImportDir() {
        return $this->importDir;
    }

    /**
     * Sets a custom import directory.
     *
     * @param string $dir Import directory
     */
    public function setImportDir($dir) {
        $this->importDir = (!empty($dir)) ? rtrim($dir, '/\\') . '/' : $this->getDefaultImportDir();
    }

    /**
     * Ensures the import directory exists (creates it if missing)
     * and deploys server-appropriate protection for its contents.
     *
     * @return bool True if the directory exists/was created and is protected
     */
    public function ensureImportDir() {
        if (!is_dir($this->importDir)) {
            if (!mkdir($this->importDir, 0755, true)) {
                error_log(__METHOD__ . ': cannot create import dir: ' . $this->importDir);
                return false;
            }
        }
        if (!is_writable($this->importDir)) {
            error_log(__METHOD__ . ': import dir not writable: ' . $this->importDir
                . ' — check ownership and permissions');
            return false;
        }
        $this->protectImportDir();
        $this->deployCaddySnippet();
        return true;
    }

    /**
     * Detects the web server software.
     *
     * @return string 'apache'|'caddy'|'nginx'|'liteSpeed'|'unknown'
     */
    public function detectServer() {
        $sw = isset($_SERVER['SERVER_SOFTWARE']) ? strtolower($_SERVER['SERVER_SOFTWARE']) : '';
        if (strpos($sw, 'caddy') !== false) {
            return 'caddy';
        }
        if (strpos($sw, 'apache') !== false) {
            return 'apache';
        }
        if (strpos($sw, 'nginx') !== false) {
            return 'nginx';
        }
        if (strpos($sw, 'litespeed') !== false || strpos($sw, 'lsapi') !== false) {
            return 'liteSpeed';
        }

        // Fallback: check for known server files
        if (is_dir('/etc/apache2') || is_dir('/etc/httpd')) {
            return 'apache';
        }

        return 'unknown';
    }

    /**
     * Whether the server supports .htaccess files.
     *
     * @return bool
     */
    public function supportsHtaccess() {
        $server = $this->detectServer();
        return in_array($server, ['apache', 'liteSpeed']);
    }

    /**
     * Checks whether the import folder is properly protected for the
     * current web server.
     *
     * @return array ['protected' => bool, 'server' => string, 'message' => string]
     */
    public function checkProtection() {
        $server = $this->detectServer();
        $dir = $this->importDir;

        // Apache / LiteSpeed: .htaccess is the native protection
        if ($this->supportsHtaccess()) {
            $ok = file_exists($dir . '.htaccess');
            return [
                'protected' => $ok,
                'server' => $server,
                'message' => $ok
                    ? 'Apache .htaccess è presente.'
                    : 'ATTENZIONE: manca il file .htaccess — la cartella NON è protetta!',
            ];
        }

        // Caddy: check for the deployed snippet marker
        if ($server === 'caddy') {
            $ok = file_exists($dir . '.caddy');
            return [
                'protected' => $ok,
                'server' => 'caddy',
                'message' => $ok
                    ? 'Snippet Caddyfile estratto (assicurati di averlo incluso nel tuo Caddyfile).'
                    : 'ATTENZIONE: lo snippet Caddy non è stato estratto — segui le istruzioni in caddy_import_protect.conf.',
            ];
        }

        // Nginx / unknown
        return [
            'protected' => false,
            'server' => $server,
            'message' => 'Server ' . $server . ' rilevato. Protezione manuale richiesta — vedi README per istruzioni.',
        ];
    }

    /**
     * Writes a .htaccess inside the import folder that prevents
     * direct web access to the pending Markdown files.
     *
     * @return bool True on success
     */
    public function protectImportDir() {
        $ht = $this->importDir . '.htaccess';
        if (file_exists($ht)) {
            return true; // already protected/customized
        }

        if (!$this->supportsHtaccess()) {
            return false;
        }

        $content = "# Protect pending Markdown files from direct web access\n"
            . "<IfModule mod_authz_core.c>\n"
            . "    Require all denied\n"
            . "</IfModule>\n"
            . "<IfModule !mod_authz_core.c>\n"
            . "    Order Deny,Allow\n"
            . "    Deny from all\n"
            . "</IfModule>\n";

        return file_put_contents($ht, $content) !== false;
    }

    /**
     * Deploys the Caddy 2 configuration snippet into the import folder.
     *
     * @return bool True on success or if already deployed
     */
    public function deployCaddySnippet() {
        if ($this->detectServer() !== 'caddy') {
            return true; // not a Caddy server, nothing to do
        }

        $dest = $this->importDir . '.caddy';
        if (file_exists($dest)) {
            return true; // already deployed
        }

        // Copy the snippet file from the plugin directory.
        // plugin_getdir() is only available inside FlatPress; fall back to
        // the plugin's own directory relative to this file.
        $source = null;
        if (function_exists('plugin_getdir')) {
            $source = plugin_getdir('publisharticle') . 'caddy_import_protect.conf';
        } else {
            $source = dirname(__FILE__) . '/caddy_import_protect.conf';
        }

        if (!is_file($source)) {
            return false;
        }
        if (!copy($source, $dest)) {
            return false;
        }

        // Write a marker that also serves as a note for the user
        $note = "# Caddy 2 protection snippet has been copied to:\n"
            . "# " . $dest . "\n"
            . "# Please include it in your Caddyfile site block.\n";

        return file_put_contents($dest . '.note', $note) !== false;
    }

    /**
     * Imports a single Markdown file manually (from the publish panel).
     *
     * Unlike importFile(), this does NOT move the source file.
     *
     * @param string $file Absolute path to the .md file
     * @param array  $overrides Optional: status, pubdate, images
     * @return array Result with success, id, message, images
     */
    public function importOne($file, $overrides = []) {
        $base = basename($file);
        $result = [
            'success' => false,
            'file' => $base,
            'id' => false,
            'message' => '',
            'images' => [],
        ];

        if (!is_file($file)) {
            $result['message'] = 'File not found.';
            return $result;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            $result['message'] = 'Unable to read file.';
            return $result;
        }

        // Import selected images if provided
        $images = [];
        if (!empty($overrides['images']) && is_array($overrides['images'])) {
            $dir = dirname($file);
            foreach ($overrides['images'] as $imgName) {
                $imgPath = $dir . '/' . $imgName;
                if (is_file($imgPath)) {
                    $rel = $this->processor
                        ->getImageUploader()
                        ->importLocal($imgPath, pathinfo($base, PATHINFO_FILENAME));
                    if ($rel !== false) {
                        $images[] = $rel;
                    }
                }
            }
        }

        // Inject overrides into frontmatter
        $content = $this->applyDefaults($content, $overrides);

        // Process and publish
        $res = $this->processor->process(
            $content, [], pathinfo($base, PATHINFO_FILENAME)
        );

        if ($res === false) {
            $result['message'] = 'Processing failed.';
            return $result;
        }

        $result['success'] = true;
        $result['id'] = $res['id'];
        $result['images'] = array_merge(
            $images,
            isset($res['images']) ? $res['images'] : []
        );
        $result['message'] = $res['scheduled']
            ? 'Scheduled for '
                . date('Y-m-d H:i:s', $res['scheduled'])
            : 'Published';

        return $result;
    }

    /**
     * Scans the import folder for Markdown files.
     *
     * @return array List of absolute file paths
     */
    public function scan() {
        if (!is_dir($this->importDir)) {
            return [];
        }

        $files = [];
        foreach ($this->markdownExtensions as $ext) {
            foreach (glob($this->importDir . '*.' . $ext) ?: [] as $file) {
                if (is_file($file)) {
                    $files[] = $file;
                }
            }
        }
        sort($files);
        return $files;
    }

    /**
     * Imports a single Markdown file.
     *
     * Steps:
     * - reads the file content
     * - applies default category/status if missing from frontmatter
     * - imports all images found in the folder
     * - publishes via ArticleProcessor::process()
     * - moves the file to done/ or failed/ subfolder (future-dated articles
     *   are left in place and re-scanned until their publish time)
     *
     * @param string $file Absolute path to the Markdown file
     * @return array ['success' => bool, 'id' => string|false, 'message' => string, 'images' => array]
     */
    public function importFile($file) {
        $base = basename($file);
        $result = [
            'success' => false,
            'file' => $base,
            'id' => false,
            'message' => '',
            'images' => [],
        ];

        if (!is_file($file)) {
            $result['message'] = 'File not found.';
            return $result;
        }

        $content = file_get_contents($file);
        if ($content === false) {
            $result['message'] = 'Unable to read file.';
            return $result;
        }

        // Import every image of the folder, KEEPING original names; a name
        // clash with a DIFFERENT existing image fails the article. Images
        // already imported with the same content are treated as done.
        $imgRes = $this->importFolderImages();
        $images = $imgRes['ok'];

        if (!empty($imgRes['errors'])) {
            $result['message'] = 'Image import failed: '
                . implode(', ', array_map(function ($name) use ($imgRes) {
                    return $name . ' (' . $imgRes['errors'][$name] . ')';
                }, array_keys($imgRes['errors'])));
            $result['image_errors'] = $imgRes['errors'];
            if (!$this->archiveSource($file, $this->failedSubdir, $result['message'])) {
                $result['warning'] = 'Cannot move file out of the import folder.';
            }
            return $result;
        }

        // Apply defaults for missing properties by injecting into frontmatter
        $content = $this->applyDefaults($content);

        // Process, deferring scheduling: the source file is kept and re-scanned
        // until its publish time comes, then it is published for real.
        $res = $this->processor->process(
            $content, [], pathinfo($base, PATHINFO_FILENAME), true
        );

        if ($res === false) {
            $result['message'] = 'Processing failed.';
            if (!$this->archiveSource($file, $this->failedSubdir, $result['message'])) {
                $result['warning'] = 'Cannot move file out of the import folder.';
            }
            return $result;
        }

        $result['success'] = true;
        $result['id'] = $res['id'];
        $result['images'] = array_merge($images, isset($res['images']) ? $res['images'] : []);
        $result['message'] = $res['scheduled']
            ? 'Scheduled for ' . date('Y-m-d H:i:s', $res['scheduled'])
            : 'Published';

        if (!empty($res['scheduled'])) {
            // Future-dated article: leave the source file in the import folder
            // (it is re-scanned until its time has come). The images are
            // already imported, move their sources out of the way.
            $this->moveSourceImages($this->doneSubdir);
            if (!$this->markPending($file, $result['message'])) {
                $result['warning'] = 'Cannot keep the scheduled file in the import folder.';
            }
            return $result;
        }

        if (!$this->archiveSource($file, $this->doneSubdir, $result['message'])) {
            $result['warning'] = 'Cannot move file out of the import folder.';
        }
        return $result;
    }

    /**
     * Moves a processed file out of the import folder.
     *
     * Tries moveTo() (done/ or failed/ subfolder) first. If that fails —
     * e.g. the subfolder cannot be created or the directory is not
     * writable — the file is renamed in-place with a ".done"/".failed"
     * suffix so that scan() never picks it up again. This guarantees
     * that a file cannot be published twice.
     *
     * @param string $file Absolute path of the source .md file
     * @param string $subdir done|failed
     * @param string $note Optional note/error description
     * @return bool True if the file no longer matches scan()
     */
    public function archiveSource($file, $subdir, $note = '') {
        if ($this->moveTo($file, $subdir, $note)) {
            return true;
        }
        error_log(__METHOD__ . ': moveTo() failed for ' . $file
            . '; archiving in place instead');
        return $this->archiveInPlace($file, $subdir, $note);
    }

    /**
     * Hook invoked for a future-dated (scheduled) article found in the
     * import folder. Currently a no-op: the source file is simply left
     * where it is and re-scanned until its publish time has come.
     *
     * @param string $file Absolute path of the source .md file
     * @param string $note Optional note (e.g. scheduled date)
     * @return bool True, so the caller never warns
     */
    public function markPending($file, $note = '') {
        //if ($this->archiveInPlace($file, 'pending', $note)) {
        return true;
        //}
        //error_log(__METHOD__ . ': cannot keep ' . $file . ' pending in the import folder');
        //return false;
    }

    /**
     * Renames a file in place with a suffix (".done", ".failed")
     * inside the import folder, so it no longer matches scan()'s glob.
     *
     * @param string $file Absolute path of the source .md file
     * @param string $suffix done|failed
     * @param string $note Optional note file content
     * @return bool True on success
     */
    private function archiveInPlace($file, $suffix, $note = '') {
        $marker = $file . '.' . $suffix;
        if (file_exists($marker)) {
            $marker .= '.' . time();
        }
        if (!@rename($file, $marker)) {
            error_log(__METHOD__ . ': cannot archive ' . $file . ' in place ('
                . $marker . ') — import dir not writable?');
            return false;
        }
        if ($note !== '') {
            @file_put_contents($marker . '.note', $note);
        }
        return true;
    }

    /**
     * Imports all Markdown files found in the import folder.
     *
     * @return array Results for each file: ['success', 'file', 'id', 'message', 'images']
     */
    public function importAll() {
        $results = [];
        foreach ($this->scan() as $file) {
            $results[] = $this->importFile($file);
        }
        return $results;
    }

    /**
     * Returns every image file found in the import folder, recursively,
     * skipping the done/ and failed/ subfolders and hidden directories.
     *
     * @return array List of absolute image file paths
     */
    public function findFolderImages() {
        $dir = rtrim($this->importDir, '/\\');
        if (!is_dir($dir)) {
            return [];
        }

        $exts = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'];
        $found = [];

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::LEAVES_ONLY
        );

        foreach ($iterator as $f) {
            if (!$f->isFile()) {
                continue;
            }
            $path = $f->getPathname();
            $rel = ltrim(str_replace($dir, '', $path), '/\\');
            $parts = preg_split('#[\\\\/]#', $rel);
            if (count($parts) > 1) {
                $top = $parts[0];
                if ($top === '' || $top[0] === '.'
                    || strcasecmp($top, $this->doneSubdir) === 0
                    || strcasecmp($top, $this->failedSubdir) === 0) {
                    continue;
                }
            }
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if (in_array($ext, $exts)) {
                $found[] = $path;
            }
        }

        sort($found);
        return $found;
    }

    /**
     * Imports EVERY image found in the import folder into the FlatPress
     * images directory, KEEPING the original file names.
     *
     * Nothing is copied when a name is already in use (in the images
     * folder or duplicated inside the import folder): the caller treats
     * that as a failure for the article being imported.
     *
     * @return array ['ok' => [imported relative paths], 'errors' => [name => reason]]
     */
    public function importFolderImages() {
        $result = ['ok' => [], 'errors' => []];
        $images = $this->findFolderImages();
        if (empty($images)) {
            return $result;
        }

        $uploader = $this->processor->getImageUploader();
        $imagesDir = rtrim($uploader->getImagesDir(), '/\\');

        // First pass: fail early without copying anything on a name clash.
        // An already-imported image with the SAME content is not a clash.
        $seen = [];
        foreach ($images as $src) {
            $name = basename($src);
            if (isset($seen[$name])) {
                $result['errors'][$name] = 'duplicate image name in the import folder';
                continue;
            }
            $seen[$name] = true;
            $dest = $imagesDir . '/' . $name;
            if (file_exists($dest) && !$this->sameContent($src, $dest)) {
                $result['errors'][$name] = 'name already in use in the images folder';
            }
        }
        if (!empty($result['errors'])) {
            return $result;
        }

        // Second pass: import everything (names are free or identical)
        foreach ($images as $src) {
            $name = basename($src);
            $rel = 'images/' . $name;
            if (file_exists($imagesDir . '/' . $name)) {
                // Identical image already imported: just report it
                if (!in_array($rel, $result['ok'], true)) {
                    $result['ok'][] = $rel;
                }
                continue;
            }
            $res = $uploader->importLocalKeepName($src);
            if ($res['ok']) {
                if (!in_array($res['rel'], $result['ok'], true)) {
                    $result['ok'][] = $res['rel'];
                }
            } else {
                $result['errors'][$name] = $res['reason'];
            }
        }

        return $result;
    }

    /**
     * Returns true when two files exist and have identical content.
     *
     * @param string $a
     * @param string $b
     * @return bool
     */
    private function sameContent($a, $b) {
        if (!is_file($a) || !is_file($b)) {
            return false;
        }
        if (filesize($a) !== filesize($b)) {
            return false;
        }
        return md5_file($a) === md5_file($b);
    }

    /**
     * Moves every source image of the import folder into a subfolder
     * (done/ or failed/), so imported images do not linger in the import
     * folder. Best-effort: failures are logged, not fatal.
     *
     * @param string $subdir done|failed
     */
    public function moveSourceImages($subdir) {
        $images = $this->findFolderImages();
        if (empty($images)) {
            return;
        }

        $destDir = rtrim($this->importDir, '/\\') . '/' . trim($subdir, '/\\');
        if (!is_dir($destDir)) {
            if (!mkdir($destDir, 0755, true)) {
                error_log(__METHOD__ . ': cannot create ' . $destDir);
                return;
            }
        }

        foreach ($images as $src) {
            $dest = $destDir . '/' . basename($src);
            if (file_exists($dest)) {
                $dest = $destDir . '/' . time() . '-' . basename($src);
            }
            if (!@rename($src, $dest)) {
                if (@copy($src, $dest)) {
                    @unlink($src);
                } else {
                    error_log(__METHOD__ . ': cannot move image ' . $src . ' to ' . $dest);
                }
            }
        }
    }

    /**
     * Applies default values (category, status) to a Markdown file
     * that lacks them in its frontmatter.
     *
     * The defaults are injected into the YAML frontmatter so that the
     * ArticleProcessor can read them normally.
     *
     * @param string $content Raw markdown content with optional frontmatter
     * @param array  $overrides Optional overrides: status, pubdate
     * @return string Content with defaults applied
     */
    public function applyDefaults($content, $overrides = []) {
        $inject = [];

        // Manual overrides take precedence
        if (!empty($overrides['status'])) {
            $inject[] = 'status: ' . $overrides['status'];
        }

        if (!empty($overrides['pubdate'])) {
            $dt = str_replace('T', ' ', $overrides['pubdate']) . ':00';
            $inject[] = 'date: ' . $dt;
        }

        if ($this->defaultCategory !== '') {
            $inject[] = 'categories: ' . $this->defaultCategory;
        }
        if ($this->defaultStatus !== '' && $this->defaultStatus !== 'publish') {
            $inject[] = 'status: ' . $this->defaultStatus;
        }

        if (empty($inject)) {
            return $content;
        }

        // Inject defaults into the frontmatter: either append to the
        // existing block or create a new one at the top.
        if (preg_match('/^(---\s*\n.*?\n---\s*\n?)/s', $content, $m)) {
            $frontmatter = $m[1];
            foreach ($inject as $line) {
                list($key) = explode(':', $line, 2);
                if (!preg_match('/^' . preg_quote($key, '/') . '\s*:/mi', $frontmatter)) {
                    $frontmatter = preg_replace('/\n---\s*$/s', "\n" . $line . "\n---", $frontmatter);
                }
            }
            return $frontmatter . substr($content, strlen($m[1]));
        }

        // No frontmatter: prepend a new block
        return "---\n" . implode("\n", $inject) . "\n---\n\n" . $content;
    }

    /**
     * Moves a processed file into a subfolder of the import directory.
     * Also moves associated sibling images and saves a .note file if given.
     *
     * @param string $file Absolute path of the file to move
     * @param string $subdir done|failed
     * @param string $note Optional note/error description
     * @return bool True on success
     */
    public function moveTo($file, $subdir, $note = '') {
        $targetDir = rtrim($this->importDir, '/\\') . '/' . trim($subdir, '/\\') . '/';
        if (!is_dir($targetDir)) {
            if (!mkdir($targetDir, 0755, true)) {
                error_log(__METHOD__ . ': cannot create target dir: ' . $targetDir);
                return false;
            }
        }

        $target = $targetDir . basename($file);
        if (file_exists($target)) {
            $target = $targetDir . time() . '-' . basename($file);
        }

        $success = false;
        if (@rename($file, $target)) {
            $success = true;
        } elseif (@copy($file, $target)) {
            if (@unlink($file)) {
                $success = true;
            } else {
                @unlink($target);
            }
        }

        if (!$success) {
            error_log(__METHOD__ . ': failed to move ' . $file . ' to ' . $target
                . ' — ' . (error_get_last() ? error_get_last()['message'] : 'unknown reason')
                . ' — check that both ' . dirname($file) . ' and ' . $targetDir . ' are writable');
            return false;
        }

        if ($note !== '') {
            @file_put_contents($target . '.note', $note);
            @chmod($target, 0644);
            @chmod($target . '.note', 0644);
        }

        // Also move every source image of the folder out of the import dir
        if ($success) {
            $this->moveSourceImages($subdir);
        }

        return $success;
    }
}
