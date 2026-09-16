<?php

require_once 'ArticleParser.php';
require_once 'ArticleComposer.php';
require_once 'ArticleWriter.php';
require_once 'CategoryResolver.php';
require_once 'ImageUploader.php';

/**
 * ArticleProcessor — ORCHESTRATORE.
 *
 * Coordina le tre fasi del flusso di pubblicazione:
 * 1. ArticleParser    -> legge il Markdown (frontmatter + contenuto)
 * 2. CategoryResolver -> traduce i nomi delle categorie in ID numerici
 * 3. ImageUploader    -> salva le immagini caricate in fp-content/images/
 * 4. ArticleComposer  -> compone l'entry FlatPress (stringa serializzata)
 * 5. ArticleWriter    -> salva sul filesystem (file .txt + view_counter.txt)
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
     * @param string $rawMarkdown Full markdown text with frontmatter
     * @param array $files Optional $_FILES array (name => file array)
     * @param string $imagesPrefix Prefix for uploaded files (e.g. entry ID)
     * @return array|false Result array on success:
     *                     ['id' => entryID, 'scheduled' => false|int timestamp,
     *                      'images' => [uploaded relative paths]]
     *                     false on failure
     */
    public function process($rawMarkdown, $files = [], $imagesPrefix = '') {
        $parsed = $this->parser->parseMarkdown($rawMarkdown);
        $properties = $parsed['properties'];
        $content = $parsed['content'];

        // Resolve the ENTRY date (used for the entry ID and shown as publication date)
        $timestamp = isset($properties['date'])
            ? $this->parser->parseDate($properties['date'])
            : time();

        // Check for a future scheduled publish date
        $scheduleTs = $this->composer->extractScheduleDate($properties, time());

        // Translate category NAMES to numeric IDs (deferred if not resolvable)
        if (isset($properties['categories']) && $properties['categories'] !== '') {
            $properties['categories'] = $this->categoryResolver->resolve($properties['categories']);
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
            // SCHEDULED: store in the pending directory
            $ok = $this->writer->savePendingEntry($id, $scheduleTs, $serialized);
            return $ok
                ? ['id' => $id, 'scheduled' => $scheduleTs, 'images' => $uploaded]
                : false;
        }

        // IMMEDIATE: write directly into the content tree
        // saveEntry() may deduplicate the ID if the same second was already used
        $finalId = $this->writer->saveEntry($id, $serialized);
        if ($finalId === false) {
            return false;
        }
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
        foreach ($this->writer->listPendingEntries() as $pending) {
            if ($pending['scheduled'] <= $now) {
                if ($this->writer->promotePendingEntry($pending)) {
                    $promoted[] = $pending['id'];
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