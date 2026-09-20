<?php

require_once 'PublishArticleLogger.php';

/**
 * ImageUploader — gestione upload delle immagini degli articoli.
 *
 * Si occupa di:
 * - validare i file caricati (estensione, dimensione, MIME)
 * - spostarli nella cartella immagini di FlatPress (fp-content/images/)
 * - generare un nome file univoco (prefisso entryID + timestamp)
 *
 * La cartella delle immagini in FlatPress è definita dalla costante
 * IMAGES_DIR. Se non disponibile, si usa il percorso relativo
 * fp-content/images/.
 *
 * ---------------------------------------------------------------------------
 * MAINTENANCE NOTES (WORKPLAN Phase 5 / R18):
 *
 * FlatPress API: IMAGES_DIR constant only; no other global calls, which keeps
 * this class unit-testable without a running FlatPress instance.
 *
 * Edge cases:
 * - upload() checks early failures (mkdir of IMAGES_DIR, move_uploaded_file);
 * - importLocal()/importLocalKeepName() fall back to plain copy() and verify
 *   the destination directory first;
 * - the folder importer treats an identical name already imported as a FAILURE
 *   only when the content differs (ArticleImporter::sameContent() decides);
 * - filenames are sanitized by using whitelisted extensions only.
 *
 * Logging (PublishArticleLogger, Task 5.2):
 *   - ERROR: filesystem failures (images dir creation, move/copy failures);
 *   - WARN : "name already in use" in importLocalKeepName() (folder importer
 *            surfaces it as an article-level error);
 *   - DEBUG: failed validation attempts (no upload performed).
 * ---------------------------------------------------------------------------
 */
class ImageUploader {

    /** Estensioni immagine consentite */
    private $allowedExtensions = ['jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'bmp'];

    /** Dimensione massima (default 5 MB) */
    private $maxSize = 5242880;

    /**
     * Returns the FlatPress image directory.
     *
     * @return string Path to images directory (with trailing slash removed)
     */
    public function getImagesDir() {
        if (defined('IMAGES_DIR')) {
            return rtrim(IMAGES_DIR, '/\\');
        }
        return 'fp-content/images';
    }

    /**
     * Validates an uploaded image file and returns an error message ('' if valid).
     *
     * @param array $file An entry from $_FILES (with keys: error, size, name, tmp_name, type)
     * @return string Empty string if valid, otherwise an error description
     */
    public function validate($file) {
        if (!is_array($file) || !isset($file['error'])) {
            publisharticle_log('debug', __METHOD__ . ': invalid file data (missing error key)');
            return 'Invalid file data.';
        }

        if ($file['error'] !== UPLOAD_ERR_OK) {
            $errors = [
                UPLOAD_ERR_INI_SIZE => 'File exceeds upload_max_filesize.',
                UPLOAD_ERR_FORM_SIZE => 'File exceeds form MAX_FILE_SIZE.',
                UPLOAD_ERR_PARTIAL => 'File was only partially uploaded.',
                UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
                UPLOAD_ERR_NO_TMP_DIR => 'Missing a temporary folder.',
                UPLOAD_ERR_CANT_WRITE => 'Failed to write file to disk.',
                UPLOAD_ERR_EXTENSION => 'A PHP extension stopped the file upload.',
            ];
            $msg = isset($errors[$file['error']]) ? $errors[$file['error']] : 'Unknown upload error.';
            publisharticle_log('debug', __METHOD__ . ': upload rejected', ['reason' => $msg, 'file' => isset($file['name']) ? $file['name'] : '']);
            return $msg;
        }

        if ($file['size'] > $this->maxSize) {
            publisharticle_log('debug', __METHOD__ . ': file too large', ['name' => $file['name'], 'size' => $file['size']]);
            return 'File too large (max ' . round($this->maxSize / 1048576, 1) . ' MB).';
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, $this->allowedExtensions)) {
            publisharticle_log('debug', __METHOD__ . ': extension not allowed', ['ext' => $ext, 'file' => $file['name']]);
            return 'Extension "' . $ext . '" not allowed. Allowed: ' . implode(', ', $this->allowedExtensions) . '.';
        }

        return '';
    }

    /**
     * Saves an uploaded image to the FlatPress images directory.
     * Generates a unique name: <entryID>_<timestamp>.<ext>
     *
     * @param array $file An entry from $_FILES
     * @param string $prefix Optional prefix (e.g. the entry ID)
     * @return string|false The relative URL path (images/...) on success, false on failure
     */
    public function upload($file, $prefix = '') {
        $error = $this->validate($file);
        if ($error !== '') {
            return false;
        }

        $dir = $this->getImagesDir();
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                publisharticle_log('error', __METHOD__ . ': cannot create images dir', ['dir' => $dir]);
                return false;
            }
        }

        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        $base = $prefix !== '' ? $prefix . '_' : '';
        $name = $base . date('Ymd-His') . '_' . mt_rand(1000, 9999) . '.' . $ext;

        $dest = $dir . '/' . $name;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            publisharticle_log('error', __METHOD__ . ': move_uploaded_file failed', ['name' => $file['name'], 'dest' => $dest]);
            return false;
        }

        // Return the relative path as used in BBCode: images/<name>
        return 'images/' . $name;
    }

    /**
     * Imports a LOCAL image file (not an HTTP upload) into the FlatPress
     * images directory. Used by the folder importer for images that sit
     * next to the Markdown article on the filesystem.
     *
     * @param string $sourcePath Absolute/relative filesystem path of the image
     * @param string $prefix Optional prefix (e.g. the article base name)
     * @return string|false The relative URL path (images/...) on success, false on failure
     */
    public function importLocal($sourcePath, $prefix = '') {
        if (!is_file($sourcePath)) {
            return false;
        }

        $ext = strtolower(pathinfo($sourcePath, PATHINFO_EXTENSION));
        if (!in_array($ext, $this->allowedExtensions)) {
            return false;
        }

        $dir = $this->getImagesDir();
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                publisharticle_log('error', __METHOD__ . ': cannot create images dir', ['dir' => $dir]);
                return false;
            }
        }

        $base = $prefix !== '' ? $prefix . '_' : '';
        $name = $base . date('Ymd-His') . '_' . mt_rand(1000, 9999) . '.' . $ext;

        $dest = $dir . '/' . $name;

        if (!copy($sourcePath, $dest)) {
            publisharticle_log('error', __METHOD__ . ': copy failed', ['src' => $sourcePath, 'dest' => $dest]);
            return false;
        }

        return 'images/' . $name;
    }

    /**
     * Imports a LOCAL image file into the FlatPress images directory
     * KEEPING its original file name, so references in the Markdown keep
     * working. Reserved for the folder importer.
     *
     * @param string $sourcePath Absolute/relative filesystem path of the image
     * @return array ['ok' => bool, 'rel' => string, 'reason' => string]
     *               'rel' = 'images/<name>' when ok; 'reason' explains failures
     */
    public function importLocalKeepName($sourcePath) {
        if (!is_file($sourcePath)) {
            return ['ok' => false, 'rel' => '', 'reason' => 'file not found'];
        }

        $name = basename($sourcePath);
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        if (!in_array($ext, $this->allowedExtensions)) {
            return ['ok' => false, 'rel' => '', 'reason' => 'extension not allowed'];
        }

        $dir = $this->getImagesDir();
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                publisharticle_log('error', __METHOD__ . ': cannot create images dir', ['dir' => $dir]);
                return ['ok' => false, 'rel' => '', 'reason' => 'images dir not creatable'];
            }
        }

        $dest = $dir . '/' . $name;
        if (file_exists($dest)) {
            publisharticle_log('warn', __METHOD__ . ': image name already in use', ['name' => $name]);
            return ['ok' => false, 'rel' => '', 'reason' => 'name already in use'];
        }

        if (!copy($sourcePath, $dest)) {
            publisharticle_log('error', __METHOD__ . ': copy failed', ['src' => $sourcePath, 'dest' => $dest]);
            return ['ok' => false, 'rel' => '', 'reason' => 'copy failed'];
        }

        return ['ok' => true, 'rel' => 'images/' . $name, 'reason' => ''];
    }

    /**
     * Scans the Markdown body for image references (![...](path ...)) and
     * reports which referenced paths are NOT already inside the images dir.
     * Used to detect local images that need uploading.
     *
     * @param string $markdown Markdown content
     * @return array List of image paths found in the markdown
     */
    public function scanMarkdownImages($markdown) {
        $paths = [];
        if (preg_match_all('/!\[[^\]]*\]\(([^)\s]+)/', $markdown, $matches)) {
            foreach ($matches[1] as $path) {
                $path = trim($path);
                // Skip remote URLs (http, https, protocol-relative //, any scheme,
                // data: URI, absolute path /...) — they are hosted elsewhere
                if (preg_match('#^(?:[a-z][a-z0-9+.-]*:|//|/)#i', $path)) {
                    continue;
                }
                $paths[] = $path;
            }
        }
        return array_unique($paths);
    }

    /**
     * Sets the maximum allowed upload size (in bytes).
     *
     * @param int $bytes Maximum size
     */
    public function setMaxSize($bytes) {
        $this->maxSize = (int) $bytes;
    }
}