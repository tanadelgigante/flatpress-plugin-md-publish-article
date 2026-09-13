<?php

require_once 'ArticleParser.php';

/**
 * ArticleComposer — logica di COMPOSIZIONE.
 *
 * Si occupa di costruire le strutture dati FlatPress:
 * - generazione dell'ID entry (entryYYMMDD-HHMMSS)
 * - costruzione della stringa serializzata KEY|value|
 * - conversione Markdown -> BBCode
 */
class ArticleComposer {

    const VERSION = 'fp-1.4.1';

    /**
     * Generates a FlatPress entry ID from a UNIX timestamp.
     * Format: entryYYMMDD-HHMMSS
     *
     * @param int $timestamp UNIX timestamp
     * @return string Entry ID
     */
    public function generateEntryId($timestamp) {
        return 'entry' . date('ymd-His', $timestamp);
    }

    /**
     * Builds the serialized entry string in FlatPress format.
     * Uses pipe-delimited key|value pairs with UPPERCASE keys.
     *
     * Format example:
     * VERSION|fp-1.4.1|SUBJECT|Title|CONTENT|...|AUTHOR|admin|DATE|1769020790|CATEGORIES|5,15|
     *
     * @param array $entry Associative array with keys: subject, content, author, date, categories
     * @return string Serialized entry string
     */
    public function buildEntryString($entry) {
        $pairs = [];

        // VERSION
        $version = isset($entry['version']) ? $entry['version'] : self::VERSION;
        $pairs[] = 'VERSION|' . $version;

        // SUBJECT
        $pairs[] = 'SUBJECT|' . (isset($entry['subject']) ? $entry['subject'] : '');

        // CONTENT
        $pairs[] = 'CONTENT|' . (isset($entry['content']) ? $entry['content'] : '');

        // AUTHOR
        $pairs[] = 'AUTHOR|' . (isset($entry['author']) ? $entry['author'] : '');

        // DATE
        $pairs[] = 'DATE|' . (isset($entry['date']) ? $entry['date'] : '');

        // CATEGORIES
        if (isset($entry['categories']) && $entry['categories'] !== '' && $entry['categories'] !== null) {
            $pairs[] = 'CATEGORIES|' . $entry['categories'];
        }

        return implode('|', $pairs) . '|';
    }

    /**
     * Converts Markdown content to FlatPress/BBCode format.
     * Handles common Markdown patterns and maps them to BBCode tags.
     *
     * @param string $markdown Markdown content
     * @return string BBCode content for FlatPress
     */
    public function markdownToBBCode($markdown) {
        $text = $markdown;

        // Headers: ## Title -> [h2]Title[/h2]
        $text = preg_replace('/^######\s+(.+)$/m', '[h6]$1[/h6]', $text);
        $text = preg_replace('/^#####\s+(.+)$/m', '[h5]$1[/h5]', $text);
        $text = preg_replace('/^####\s+(.+)$/m', '[h4]$1[/h4]', $text);
        $text = preg_replace('/^###\s+(.+)$/m', '[h3]$1[/h3]', $text);
        $text = preg_replace('/^##\s+(.+)$/m', '[h2]$1[/h2]', $text);

        // Bold: **text** or __text__ -> [b]text[/b]
        $text = preg_replace('/\*\*(.+?)\*\*/', '[b]$1[/b]', $text);
        $text = preg_replace('/__(.+?)__/', '[b]$1[/b]', $text);

        // Italic: *text* or _text_ -> [i]text[/i]
        $text = preg_replace('/\*(.+?)\*/', '[i]$1[/i]', $text);
        $text = preg_replace('/(?<!\w)_(.+?)_(?!\w)/', '[i]$1[/i]', $text);

        // Strikethrough: ~~text~~ -> [del]text[/del]
        $text = preg_replace('/~~(.+?)~~/', '[del]$1[/del]', $text);

        // Inline code: `code` -> [code]code[/code]
        $text = preg_replace('/`(.+?)`/', '[code]$1[/code]', $text);

        // Images: ![alt](path width=N) -> [img="path" alt="alt" width="N"]
        $text = preg_replace_callback('/!\[([^\]]*)\]\(([^)]+?)(?:\s+width=(\d+))?\)/', function ($m) {
            // Prepend images/ to plain filenames without a directory prefix
            $path = trim($m[2]);

            // Keep absolute URLs (http://, https://, protocol-relative //, any scheme ftp://,
            // data: URIs, mailto:, and absolute paths /uploads/...) untouched:
            // they point to images hosted on other sites.
            if (preg_match('#^(?:[a-z][a-z0-9+.-]*:|//|/)#i', $path)) {
                // leave $path as-is (remote or absolute)
            } elseif (!preg_match('#^(images/|attachs/)#i', $path)) {
                $path = 'images/' . ltrim($path, '/');
            }

            $attrs = 'alt="' . $m[1] . '"';
            if (!empty($m[3])) {
                $attrs .= ' width="' . $m[3] . '"';
            }
            return '[img="' . $path . '" ' . $attrs . ']';
        }, $text);

        // Links: [text](url) -> [url="url"]text[/url]
        $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '[url="$2"]$1[/url]', $text);

        return $text;
    }

    /**
     * Builds a complete entry array ready for serialization.
     * Combines parsed properties with converted content.
     *
     * Supported frontmatter keys:
     * - version     (default fp-1.4.1)
     * - subject     (alias: title)
     * - author      (default admin)
     * - date        (entry date)
     * - categories  (comma-separated IDs)
     *
     * @param array $properties Associative array from frontmatter
     * @param string $markdownContent Markdown content body
     * @param int $defaultTimestamp Fallback timestamp if no date property
     * @return array Entry array with keys: version, subject, content, author, date, categories
     */
    public function buildEntry($properties, $markdownContent, $defaultTimestamp) {
        return [
            'version'    => isset($properties['version']) ? $properties['version'] : self::VERSION,
            'subject'    => isset($properties['subject'])
                ? $properties['subject']
                : (isset($properties['title']) ? $properties['title'] : ''),
            'content'    => $this->markdownToBBCode($markdownContent),
            'author'     => isset($properties['author']) ? $properties['author'] : 'admin',
            'date'       => $defaultTimestamp,
            'categories' => isset($properties['categories']) ? $properties['categories'] : '',
        ];
    }

    /**
     * Extracts the optional scheduled publish date from the frontmatter.
     * Supported keys: publish_date, scheduled, scheduled_date
     * Returns null when no future scheduling is requested.
     *
     * @param array $properties Frontmatter properties
     * @param int $now Current timestamp
     * @return int|null UNIX timestamp if scheduling is requested, null otherwise
     */
    public function extractScheduleDate($properties, $now) {
        $key = null;
        foreach (['publish_date', 'scheduled', 'scheduled_date'] as $candidate) {
            if (isset($properties[$candidate]) && $properties[$candidate] !== '') {
                $key = $candidate;
                break;
            }
        }
        if ($key === null) {
            return null;
        }

        $ts = (new ArticleParser())->parseDate($properties[$key]);

        // Schedule only makes sense for future dates
        if ($ts > $now) {
            return $ts;
        }
        return null;
    }
}