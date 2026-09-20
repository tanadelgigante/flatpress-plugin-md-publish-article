<?php

/**
 * ArticleParser — logica di LETTURA.
 *
 * Si occupa esclusivamente di interpretare input testuali:
 * - parsing del frontmatter Markdown (title, author, date, categories)
 * - parsing di una stringa entry FlatPress serializzata (KEY|value|...)
 * - conversione di date in timestamp UNIX
 *
 * ---------------------------------------------------------------------------
 * MAINTENANCE NOTES (WORKPLAN Phase 5 / R18):
 *
 * FlatPress API: NONE. This class only performs pure text manipulation, so it
 * is fully unit-testable without a FlatPress runtime (used by the test suite
 * and by ArticleWriter::updateIndex() to normalize an entry string).
 *
 * Edge cases:
 * - the frontmatter block must start at the very beginning (^---); a markdown
 *   article without frontmatter returns an empty property map;
 * - $_FILES-style input is NOT handled here (no true arrays come in): the
 *   plugin normalizes non-array $_FILES entries to [] and only arrays with a
 *   non-empty 'tmp_name' are passed to ImageUploader by ArticleProcessor;
 * - legacy FlatPress entries written by older plugin versions (entry strings
 *   with fields after DATE) parse gracefully: unknown keys are skipped.
 *
 * No logging is performed by this class (pure parsing, no side effects).
 * ---------------------------------------------------------------------------
 */
class ArticleParser {

    /**
     * Parses Markdown text with YAML-like frontmatter.
     *
     * Expected format:
     * ---
     * title: My Title
     * author: admin
     * date: 2026-01-21
     * categories: News,Tech
     * ---
     * Markdown content here...
     *
     * @param string $text Raw markdown text with frontmatter
     * @return array ['properties' => [...], 'content' => '...']
     */
    public function parseMarkdown($text) {
        if (preg_match('/^---(.*?)---/s', $text, $matches)) {
            $frontmatter = $matches[1];
            $content = substr($text, strlen($matches[0]));

            $properties = [];
            foreach (explode("\n", trim($frontmatter)) as $line) {
                if (strpos($line, ':') !== false) {
                    list($key, $value) = explode(':', $line, 2);
                    $properties[trim($key)] = trim($value);
                }
            }
            return ['properties' => $properties, 'content' => trim($content)];
        }
        return ['properties' => [], 'content' => $text];
    }

    /**
     * Parses a FlatPress entry string serialized as KEY|value|KEY|value|...
     * Keys are converted to lowercase (matching FlatPress utils_kexplode behavior).
     *
     * Example input:
     * VERSION|fp-1.4.1|SUBJECT|Nuova versione|CONTENT|...|AUTHOR|ilgigante77|DATE|1769020790|CATEGORIES|5,15|
     *
     * @param string $string Raw serialized entry string
     * @return array Associative array with lowercase keys
     */
    public function parseEntryString($string) {
        $entry = [];
        $parts = explode('|', $string);

        $count = count($parts);
        for ($i = 0; $i < $count; $i += 2) {
            if (!isset($parts[$i + 1])) {
                break;
            }
            $key = strtolower(trim($parts[$i]));
            $value = $parts[$i + 1];
            if ($key === '') {
                continue;
            }
            $entry[$key] = $value;
        }

        return $entry;
    }

    /**
     * Converts a date string to a UNIX timestamp.
     * Supports formats: YYYY-MM-DD, YYYY-MM-DD HH:MM:SS, or raw numeric timestamp.
     *
     * @param string $dateStr Date string from frontmatter
     * @return int UNIX timestamp
     */
    public function parseDate($dateStr) {
        // If it's already a numeric timestamp
        if (ctype_digit($dateStr)) {
            return (int) $dateStr;
        }
        // Try strtotime for common formats
        $ts = strtotime($dateStr);
        return $ts !== false ? $ts : time();
    }
}