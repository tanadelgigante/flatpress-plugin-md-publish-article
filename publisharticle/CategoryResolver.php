<?php

require_once 'PublishArticleLogger.php';

/**
 * CategoryResolver — traduzione NOME categoria -> ID numerico.
 *
 * Nel frontmatter l'utente può indicare le categorie con il NOME
 * (es. "News, Tech") invece degli ID numerici (es. "5,15").
 * Questa classe risolve i nomi in ID leggendo la mappa delle
 * categorie di FlatPress:
 *
 * 1. categories_encoded.dat  (array PHP serializzato: ['defs' => [id => label], ...])
 * 2. categories.txt          (righe "Label:ID", con indentazione per i sottolivelli)
 *
 * Se un nome non viene trovato (o i file non sono disponibili),
 * il valore viene lasciato invariato: la traduzione potrà essere
 * completata in una fase successiva.
 *
 * ---------------------------------------------------------------------------
 * MAINTENANCE NOTES (WORKPLAN Phase 5 / R18):
 *
 * FlatPress API: CONTENT_DIR constant only; the serialized categories map is
 * read with a defensive @unserialize() (the map may be missing, empty or
 * malformed in a fresh install).
 *
 * Logging (PublishArticleLogger, Task 5.2): an unresolvable category NAME is
 * logged at DEBUG level (the value is deferred, not an error); no log line is
 * emitted for the common resolvable cases to keep the noise low.
 * ---------------------------------------------------------------------------
 */
class CategoryResolver {

    /** @var array|null id => label */
    private $defs = null;

    /**
     * Returns the path of the categories map file.
     *
     * @return string Path to categories_encoded.dat
     */
    public function getCategoriesFile() {
        if (defined('CONTENT_DIR')) {
            return CONTENT_DIR . 'categories_encoded.dat';
        }
        return 'fp-content/content/categories_encoded.dat';
    }

    /**
     * Loads the category map (id => label).
     * Tries categories_encoded.dat first, then categories.txt.
     *
     * @return array id => label
     */
    public function loadCategories() {
        if ($this->defs !== null) {
            return $this->defs;
        }
        $this->defs = [];

        // 1) categories_encoded.dat (serialized PHP array)
        $file = $this->getCategoriesFile();
        if (file_exists($file)) {
            $data = @unserialize((string) file_get_contents($file));
            if (is_array($data) && isset($data['defs']) && is_array($data['defs'])) {
                $this->defs = $data['defs'];
                return $this->defs;
            }
        }

        // 2) fallback: categories.txt (lines "Label:ID", indented with dashes)
        $txt = dirname($file) . '/categories.txt';
        if (file_exists($txt)) {
            foreach (file($txt, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
                $line = trim($line);
                if ($line === '' || $line[0] === '#') {
                    continue;
                }
                // Remove indentation markers ("--" dashes) used for nested categories
                $line = ltrim($line, '- ');
                if (strpos($line, ':') !== false) {
                    list($label, $id) = explode(':', $line, 2);
                    $this->defs[trim($id)] = trim($label);
                }
            }
        }

        return $this->defs;
    }

    /**
     * Translates a single category value to its numeric ID.
     * - Numeric values are kept as-is.
     * - Known names are resolved to IDs (case-insensitive).
     * - Unknown names are returned unchanged (deferred translation).
     *
     * @param string $value A single category (name or numeric ID)
     * @return string Numeric ID, or the original value if it cannot be resolved
     */
    public function nameToId($value) {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        // Already a numeric ID
        if (ctype_digit($value)) {
            return $value;
        }

        // Reverse lookup by label (case-insensitive)
        foreach ($this->loadCategories() as $id => $label) {
            if (strcasecmp($label, $value) === 0) {
                return (string) $id;
            }
        }

        // Not resolvable right now: leave the name for a later stage
        publisharticle_log('debug', __METHOD__ . ': category name not resolved yet', ['name' => $value]);
        return $value;
    }

    /**
     * Translates a comma-separated list of categories.
     * Accepts a mix of names and numeric IDs.
     *
     * Example input:  "News, Tech, 5"
     * Example output: "3,7,5"  (if News->3 and Tech->7 in the map)
     *
     * @param string $categories Comma-separated categories (names and/or IDs)
     * @return string Comma-separated translated categories
     */
    public function resolve($categories) {
        if (trim($categories) === '') {
            return '';
        }

        $resolved = [];
        foreach (explode(',', $categories) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $resolved[] = $this->nameToId($part);
        }
        return implode(',', $resolved);
    }

    /**
     * Returns the loaded category map (id => label), if available.
     *
     * @return array id => label
     */
    public function getDefs() {
        return $this->loadCategories();
    }
}