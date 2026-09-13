<?php

/**
 * ArticleWriter — logica di SALVATAGGIO.
 *
 * Si occupa esclusivamente di interagire con il filesystem:
 * - risoluzione dei percorsi (content root, entry path)
 * - creazione delle directory
 * - scrittura del file entry .txt
 * - creazione della cartella laterale con view_counter.txt
 */
class ArticleWriter {

    /**
     * Returns the content root directory for FlatPress entries.
     * Path: fp-content/content/
     *
     * @return string Absolute or relative path to content root
     */
    public function getContentRoot() {
        // FlatPress constant CONTENT_DIR, fallback to relative path
        if (defined('CONTENT_DIR')) {
            return CONTENT_DIR;
        }
        return 'fp-content/content/';
    }

    /**
     * Returns the base filesystem path (without .txt extension) for an entry ID.
     * Path: <content_root>/<yy>/<MM>/entryYYMMDD-HHMMSS
     *
     * @param string $id Entry ID (e.g. entry260121-183950)
     * @return string Base path to the entry file (without extension)
     */
    public function getEntryBasePath($id) {
        // Use FlatPress native function when available
        if (function_exists('entry_dir')) {
            return entry_dir($id);
        }
        // Manual path construction from the ID
        if (preg_match('/^entry(\d{2})(\d{2})(\d{2})-(\d{2})(\d{2})(\d{2})$/', $id, $m)) {
            return $this->getContentRoot() . $m[1] . '/' . $m[2] . '/' . $id;
        }
        return $this->getContentRoot() . $id;
    }

    /**
     * Creates the directory for a month: <content_root>/<yy>/<MM>/
     * No-op if the directory already exists.
     *
     * @param string $id Entry ID used to resolve the month directory
     * @return string|false The month directory path on success, false on failure
     */
    public function ensureMonthDir($id) {
        $entryBase = $this->getEntryBasePath($id);
        $dir = dirname($entryBase);

        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                return false;
            }
        }
        return $dir;
    }

    /**
     * Writes the entry .txt file content.
     * Path: <content_root>/<yy>/<MM>/entryYYMMDD-HHMMSS.txt
     *
     * @param string $id Entry ID
     * @param string $content Serialized entry string
     * @return bool True on success, false on failure
     */
    public function writeEntryFile($id, $content) {
        $dir = $this->ensureMonthDir($id);
        if ($dir === false) {
            return false;
        }

        $filePath = $this->getEntryBasePath($id) . '.txt';
        return file_put_contents($filePath, $content) !== false;
    }

    /**
     * Writes the sidecar view counter file.
     * Creates the sidecar directory if missing:
     * <content_root>/<yy>/<MM>/entryYYMMDD-HHMMSS/view_counter.txt
     *
     * @param string $id Entry ID
     * @param string|int $value Initial view counter value (default 0)
     * @return bool True on success, false on failure
     */
    public function writeViewCounter($id, $value = 0) {
        $entryBase = $this->getEntryBasePath($id);
        $sidecarDir = $entryBase; // flatpress stores sidecar as a subfolder with same name

        if (!is_dir($sidecarDir)) {
            if (!mkdir($sidecarDir, 0755, true)) {
                return false;
            }
        }

        return file_put_contents($sidecarDir . '/view_counter.txt', (string) $value) !== false;
    }

    /**
     * Saves a complete entry: writes the .txt file plus the view counter sidecar.
     *
     * @param string $id Entry ID
     * @param string $content Serialized entry string
     * @return bool True on success, false on any failure
     */
    public function saveEntry($id, $content) {
        if (!$this->writeEntryFile($id, $content)) {
            return false;
        }
        return $this->writeViewCounter($id, 0);
    }

    /**
     * Returns the pending (scheduled) directory root.
     * Path: <content_root>pending/
     * Falls back to a relative 'fp-content/content/pending/' if CONTENT_DIR is not defined.
     *
     * @return string Path to the pending directory
     */
    public function getPendingDir() {
        return $this->getContentRoot() . 'pending/';
    }

    /**
     * Writes a scheduled (pending) entry.
     * The file is stored in the pending directory with a suffix carrying
     * the scheduled publish timestamp, so the scheduler can promote it later.
     *
     * Layout:
     * <content_root>/pending/<scheduled-ts>-<entryID>.txt
     *
     * @param string $id Entry ID (e.g. entryYYMMDD-HHMMSS)
     * @param int $scheduledTs Scheduled publish UNIX timestamp
     * @param string $content Serialized entry string
     * @return bool True on success, false on failure
     */
    public function savePendingEntry($id, $scheduledTs, $content) {
        $dir = $this->getPendingDir();
        if (!is_dir($dir)) {
            if (!mkdir($dir, 0755, true)) {
                return false;
            }
        }
        $file = $dir . $scheduledTs . '-' . $id . '.txt';
        return file_put_contents($file, $content) !== false;
    }

    /**
     * Lists pending (scheduled) entries stored by savePendingEntry().
     *
     * @return array List of ['file' => name, 'scheduled' => int ts, 'id' => entryID]
     */
    public function listPendingEntries() {
        $dir = $this->getPendingDir();
        if (!is_dir($dir)) {
            return [];
        }

        $entries = [];
        foreach (glob($dir . '*.txt') ?: [] as $file) {
            $name = basename($file, '.txt');
            if (preg_match('/^(\d+)-(entry\d{6}-\d{6})$/', $name, $m)) {
                $entries[] = [
                    'file' => $file,
                    'scheduled' => (int) $m[1],
                    'id' => $m[2],
                ];
            }
        }
        usort($entries, function ($a, $b) {
            return $a['scheduled'] - $b['scheduled'];
        });
        return $entries;
    }

    /**
     * Promotes a pending entry: moves it from the pending directory
     * into the normal content tree, creating view_counter.txt.
     *
     * @param array $pending A single item from listPendingEntries()
     * @return bool True on success, false on failure
     */
    public function promotePendingEntry($pending) {
        $id = $pending['id'];
        $content = file_get_contents($pending['file']);
        if ($content === false) {
            return false;
        }

        if (!$this->writeEntryFile($id, $content)) {
            return false;
        }
        if (!$this->writeViewCounter($id, 0)) {
            return false;
        }

        @unlink($pending['file']);
        return true;
    }
}