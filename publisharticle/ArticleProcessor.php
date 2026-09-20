<?php

require_once 'ArticleParser.php';
require_once 'ArticleComposer.php';
require_once 'ArticleWriter.php';
require_once 'CategoryResolver.php';
require_once 'ImageUploader.php';
require_once 'PublishArticleLogger.php';

/**
 * ArticleProcessor — ORCHESTRATORE.
 *
 * Coordina le tre fasi del flusso di pubblicazione:
 * 1. ArticleParser    -> legge il Markdown (frontmatter + contenuto)
 * 2. CategoryResolver -> traduce i nomi delle categorie in ID numerici
 * 3. ImageUploader    -> salva le immagini caricate in fp-content/images/
 * 4. ArticleComposer  -> compone l'entry FlatPress (stringa serializzata)
 * 5. ArticleWriter    -> salva sul filesystem (file .txt + view_counter.txt)
 *
 * ---------------------------------------------------------------------------
 * MAINTENANCE NOTES (WORKPLAN Phase 5 / R18):
 *
 * FlatPress APIs used transitively (via the components):
 *   - ArticleWriter::saveEntry()  -> entry_dir(), entry_init(),
 *     entry_index::add(), do_action('publish_post', ...)
 *   - ArticleComposer::buildEntry() -> system_ver() for the entry VERSION tag
 *   - ImageUploader -> IMAGES_DIR
 *
 * This class is deliberately free of FlatPress global calls: everything goes
 * through the injected components, which makes the processor unit-testable
 * (tests/ArticleProcessorSchedulingTest.php) and keeps the public flows
 * (import from folder, admin panel upload, scheduled promotion) identical.
 *
 * Logging (PublishArticleLogger, Task 5.2):
 *   - INFO  : entry/exit of process() and scheduled promotions;
 *   - DEBUG : parsed frontmatter details, scheduling decisions, resolved
 *             categories;
 *   - ERROR : failures writing the entry or the pending file.
 * No log line alters the entry_index flow: index updates are performed by
 * ArticleWriter::updateIndex() and are intentionally NOT logged here.
 * ---------------------------------------------------------------------------
 */
class ArticleProcessor {

    /** @var ArticleParser */
    private $parser;

    /** @var ArticleComposer */
    private $composer;

    /** @var ArticleWriter */
    private $writer;

    /** @var CategoryResolver */
    private $categoryResolver;

    /** @var ImageUploader */
    private $imageUploader;

    /**
     * Constructor: wires the orchestrator components together.
     * FlatPressAPI: none directly; components resolved in their own files.
     */
    public function __construct() {
        $this->parser = new ArticleParser();
        $this->composer = new ArticleComposer();
        $this->writer = new ArticleWriter();
        $this->categoryResolver = new CategoryResolver();
        $this->imageUploader = new ImageUploader();
    }

    /**
     * Main entry point: processes submitted Markdown and writes the entry,
     * optionally uploading images provided in $_FILES.
     *
     * Uploaded images are moved into the FlatPress images dir; the markdown
     * body may reference them with plain filenames (e.g. ![alt](photo.jpg)),
     * which are normalized to images/... paths during conversion.
     *
     * Edge cases:
     * - frontmatter 'version:' overrides the dynamic default (system_ver());
     *   legacy entries written by old plugin versions keep 'fp-1.4.1';
     * - a future-dated article is either written to the pending directory or
     *   deferred to the caller ($deferScheduling, used by the folder importer
     *   so the source file can be re-scanned when due);
     * - the entry ID may be deduplicated (+1 second) by ArticleWriter when the
     *   same second was already used.
     *
     * @param string $rawMarkdown Full markdown text with frontmatter
     * @param array $files Optional $_FILES array (name => file array)
     * @param string $imagesPrefix Prefix for uploaded files (e.g. entry ID)
     * @param bool $deferScheduling When true, a future-dated article is NOT
     *              written to the pending directory: the caller takes care of
     *              keeping the source file (used by the folder importer, which
     *              re-scans the source until its time comes).
     * @return array|false Result array on success:
     *                     ['id' => entryID, 'scheduled' => false|int timestamp,
     *                      'images' => [uploaded relative paths]]
     *                     false on failure
     */
    public function process($rawMarkdown, $files = [], $imagesPrefix = '', $deferScheduling = false) {
        publisharticle_log('info', __METHOD__ . ': processing article', [
            'prefix' => $imagesPrefix !== '' ? $imagesPrefix : '(entry-id)',
            'defer' => $deferScheduling ? 'yes' : 'no',
        ]);

        $parsed = $this->parser->parseMarkdown($rawMarkdown);
        $properties = $parsed['properties'];
        $content = $parsed['content'];

        publisharticle_log('debug', __METHOD__ . ': parsed frontmatter', [
            'subject' => isset($properties['subject']) ? $properties['subject'] : (isset($properties['title']) ? $properties['title'] : ''),
            'has_date' => isset($properties['date']) ? 'yes' : 'no',
        ]);

        // Check for a future scheduled publish date
        $scheduleTs = $this->composer->extractScheduleDate($properties, time());
        if ($scheduleTs !== null) {
            publisharticle_log('debug', __METHOD__ . ': scheduled date detected', ['ts' => $scheduleTs]);
        }

        // Resolve the ENTRY date (used for the entry ID and shown as publication date)
        $timestamp = isset($properties['date'])
            ? $this->parser->parseDate($properties['date'])
            : ($scheduleTs !== null ? $scheduleTs : time());

        // Translate category NAMES to numeric IDs (deferred if not resolvable)
        if (isset($properties['categories']) && $properties['categories'] !== '') {
            $properties['categories'] = $this->categoryResolver->resolve($properties['categories']);
            publisharticle_log('debug', __METHOD__ . ': categories resolved', ['value' => $properties['categories']]);
        }

        $entry = $this->composer->buildEntry($properties, $content, $timestamp);
        $id = $this->composer->generateEntryId($timestamp);

        // Upload any images provided in $files (e.g. multipart form)
        $uploaded = [];
        $prefix = $imagesPrefix !== '' ? $imagesPrefix : $id;
        foreach ($files as $key => $fileData) {
            if (is_array($fileData) && isset($fileData['tmp_name']) && $fileData['tmp_name'] !== '') {
                $rel = $this->imageUploader->upload($fileData, $prefix);
                if ($rel !== false) {
                    $uploaded[] = $rel;
                }
            }
        }

        $serialized = $this->composer->buildEntryString($entry);

        if ($scheduleTs !== null) {
            // SCHEDULED
            if ($deferScheduling) {
                // The caller keeps the source file and re-imports it when due;
                // do NOT write a pending entry (would cause a duplicate).
                publisharticle_log('info', __METHOD__ . ': scheduled publication deferred to caller', ['id' => $id]);
                return ['id' => $id, 'scheduled' => $scheduleTs, 'images' => $uploaded];
            }
            // Store in the pending directory
            $ok = $this->writer->savePendingEntry($id, $scheduleTs, $serialized);
            if ($ok) {
                publisharticle_log('info', __METHOD__ . ': scheduled entry written to pending', ['id' => $id, 'ts' => $scheduleTs]);
            } else {
                publisharticle_log('error', __METHOD__ . ': failed to write pending entry', ['id' => $id, 'ts' => $scheduleTs]);
            }
            return $ok
                ? ['id' => $id, 'scheduled' => $scheduleTs, 'images' => $uploaded]
                : false;
        }

        // IMMEDIATE: write directly into the content tree
        // saveEntry() may deduplicate the ID if the same second was already used
        $finalId = $this->writer->saveEntry($id, $serialized);
        if ($finalId === false) {
            publisharticle_log('error', __METHOD__ . ': failed to save entry', ['id' => $id]);
            return false;
        }
        publisharticle_log('info', __METHOD__ . ': entry published', ['id' => $finalId]);
        return ['id' => $finalId, 'scheduled' => false, 'images' => $uploaded];
    }

    /**
     * Processes scheduled (pending) entries whose time has come.
     * Promotes every pending entry with scheduled timestamp <= now.
     *
     * This should be triggered by a hook on every page load (see plugin.php).
     *
     * @param int|null $now Current timestamp (defaults to time())
     * @return array List of promoted entry IDs
     */
    public function processScheduled($now = null) {
        if ($now === null) {
            $now = time();
        }

        $promoted = [];
        $pending = $this->writer->listPendingEntries();
        publisharticle_log('debug', __METHOD__ . ': checking pending entries', ['count' => count($pending)]);
        foreach ($pending as $p) {
            if ($p['scheduled'] <= $now) {
                if ($this->writer->promotePendingEntry($p)) {
                    $promoted[] = $p['id'];
                    publisharticle_log('info', __METHOD__ . ': promoted pending entry', ['id' => $p['id']]);
                } else {
                    publisharticle_log('error', __METHOD__ . ': failed to promote pending entry', ['id' => $p['id']]);
                }
            }
        }
        return $promoted;
    }

    /**
     * Returns the list of pending (scheduled) entries for UI display.
     *
     * @return array List of ['file', 'scheduled', 'id']
     */
    public function listScheduled() {
        return $this->writer->listPendingEntries();
    }

    // --- Convenience accessors for the individual components ---

    /** @return ArticleParser */
    public function getParser() {
        return $this->parser;
    }

    /** @return ArticleComposer */
    public function getComposer() {
        return $this->composer;
    }

    /** @return ArticleWriter */
    public function getWriter() {
        return $this->writer;
    }

    /** @return CategoryResolver */
    public function getCategoryResolver() {
        return $this->categoryResolver;
    }

    /** @return ImageUploader */
    public function getImageUploader() {
        return $this->imageUploader;
    }

    /**
     * Uploads images only, without publishing an article.
     * Useful for pre-loading images before writing the post.
     *
     * @param array $files $_FILES array (name => file array)
     * @param string $prefix Prefix for file names
     * @return array List of uploaded relative paths (images/...)
     */
    public function uploadImages($files, $prefix = '') {
        $uploaded = [];
        foreach ($files as $fileData) {
            if (is_array($fileData) && isset($fileData['tmp_name']) && $fileData['tmp_name'] !== '') {
                $rel = $this->imageUploader->upload($fileData, $prefix);
                if ($rel !== false) {
                    $uploaded[] = $rel;
                }
            }
        }
        return $uploaded;
    }
}