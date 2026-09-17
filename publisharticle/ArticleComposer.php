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

        // Fenced code blocks: ```lang ... ``` -> [code]...[/code]
        $text = preg_replace('/```(?:[a-zA-Z0-9_-]+)?\r?\n(.*?)\r?\n```/s', '[code]$1[/code]', $text);

        // Markdown tables -> HTML <table> (FlatPress allows inline HTML).
        // Robust line-based scanner: collect consecutive lines that look like
        // table rows, then convert a block only when it has a valid header,
        // separator and at least one data row.
        $text = $this->convertTables($text);

        // Task lists: - [x] done / - [ ] todo  -> HTML checkbox lists
        $text = preg_replace('/^- \[x\]\s+(.+)$/mi', '<li style="list-style:none"><input type="checkbox" checked disabled> $1</li>', $text);
        $text = preg_replace('/^- \[ \]\s+(.+)$/mi', '<li style="list-style:none"><input type="checkbox" disabled> $1</li>', $text);

        // Headers: # Title -> [h2]Title[/h2], ## -> [h2], etc.
        $text = preg_replace('/^######\s+(.+)$/m', '[h6]$1[/h6]', $text);
        $text = preg_replace('/^#####\s+(.+)$/m', '[h5]$1[/h5]', $text);
        $text = preg_replace('/^####\s+(.+)$/m', '[h4]$1[/h4]', $text);
        $text = preg_replace('/^###\s+(.+)$/m', '[h3]$1[/h3]', $text);
        $text = preg_replace('/^##\s+(.+)$/m', '[h2]$1[/h2]', $text);
        $text = preg_replace('/^#\s+(.+)$/m', '[h2]$1[/h2]', $text);

        // Blockquotes: > quote -> [quote]...[/quote]
        $text = preg_replace_callback('/(?:^>[ \t]?(.*)$\r?\n?)+/m', function ($matches) {
            $lines = [];
            foreach (explode("\n", trim($matches[0])) as $l) {
                $lines[] = preg_replace('/^>[ \t]?/', '', trim($l, "\r"));
            }
            return "[quote]\n" . implode("\n", $lines) . "\n[/quote]\n";
        }, $text);

        // Horizontal rules: --- or *** on an isolated line
        $text = preg_replace('/^(?:---|\*\*\*)\s*$/m', '[hr]', $text);

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

        // Images: ![alt](path width=N height=M) -> [img="path" alt="alt" width="N" height="M"]
        $text = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)(?:\s+width=(\d+))?(?:\s+height=(\d+))?(?:[^)]*)\)/', function ($m) {
            // Prepend images/ to plain filenames without a directory prefix
            $path = trim($m[2]);

            // Keep absolute URLs (http://, https://, any scheme ftp://,
            // data: URIs, mailto:, and absolute paths /uploads/...) untouched:
            // they point to images hosted on other sites.
            if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $path)) {
                // leave $path as-is (remote or absolute with scheme)
            } elseif (preg_match('#^//#', $path)) {
                // protocol-relative URL — prepend https: so FlatPress
                // bbcode_remap_url() does not mangle it with BLOG_BASEURL
                $path = 'https:' . $path;
            } elseif (preg_match('#^/#', $path)) {
                // absolute path — leave as-is
            } elseif (!preg_match('#^(images/|attachs/)#i', $path)) {
                $path = 'images/' . ltrim($path, '/');
            }

            $attrs = 'alt="' . $m[1] . '"';
            if (!empty($m[3])) {
                $attrs .= ' width="' . $m[3] . '"';
            }
            if (!empty($m[4])) {
                $attrs .= ' height="' . $m[4] . '"';
            }
            return '[img="' . $path . '" ' . $attrs . ']';
        }, $text);

        // Links: [text](url) -> [url="url"]text[/url]
        $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '[url="$2"]$1[/url]', $text);

        return $text;
    }

    /**
     * Converts Markdown tables into HTML tables.
     *
     * Scans the text line by line and groups consecutive lines that start
     * with a pipe (`|`) into candidate table blocks. A block is converted
     * only when it contains a header row, a valid alignment separator row
     * (cells made of `-`, `:` or both) and at least one data row.
     *
     * @param string $text
     * @return string
     */
    private function convertTables($text) {
        $lines = preg_split('/\r?\n/', $text);
        $out = [];
        $count = count($lines);

        for ($i = 0; $i < $count; $i++) {
            $line = $lines[$i];

            // Candidate table row: optional leading spaces then a pipe.
            if (preg_match('/^[ \t]*\|/', $line)) {
                // Collect the run of consecutive table lines.
                $block = [];
                $j = $i;
                while ($j < $count && preg_match('/^[ \t]*\|/', $lines[$j])) {
                    $block[] = $lines[$j];
                    $j++;
                }

                // Need header + separator + at least one data row.
                if (count($block) >= 3 && $this->isTableSeparator($block[1])) {
                    $out[] = $this->renderTable($block);
                    $i = $j - 1;
                    continue;
                }

                // Not a valid table: emit the lines unchanged and move on.
                foreach ($block as $b) {
                    $out[] = $b;
                }
                $i = $j - 1;
                continue;
            }

            $out[] = $line;
        }

        return implode("\n", $out);
    }

    /**
     * Check whether a line is a valid Markdown table separator row.
     *
     * @param string $line
     * @return bool
     */
    private function isTableSeparator($line) {
        $cells = $this->splitTableRow($line);
        if (empty($cells)) {
            return false;
        }
        foreach ($cells as $cell) {
            if (!preg_match('/^:?-+:?$/', $cell)) {
                return false;
            }
        }
        return true;
    }

    /**
     * Split a Markdown table row into its trimmed cell values.
     *
     * @param string $line
     * @return array
     */
    private function splitTableRow($line) {
        $line = trim($line);
        $line = trim($line, '|');
        if ($line === '') {
            return [];
        }
        return array_map('trim', explode('|', $line));
    }

    /**
     * Render a validated Markdown table block as an HTML table.
     *
     * @param array $block Consecutive table lines (header, separator, rows).
     * @return string
     */
    private function renderTable(array $block) {
        $headerCells = $this->splitTableRow($block[0]);
        $sepCells    = $this->splitTableRow($block[1]);

        // Determine per-column alignment from the separator row.
        $aligns = [];
        foreach ($sepCells as $s) {
            $left  = isset($s[0]) && $s[0] === ':';
            $right = substr($s, -1) === ':';
            if ($left && $right) {
                $aligns[] = 'center';
            } elseif ($right) {
                $aligns[] = 'right';
            } else {
                $aligns[] = 'left';
            }
        }

        $html  = '<table>' . "\n";
        $html .= '<thead><tr>';
        foreach ($headerCells as $idx => $cell) {
            $a = isset($aligns[$idx]) ? $aligns[$idx] : 'left';
            $html .= '<th style="text-align:' . $a . '">' . $cell . '</th>';
        }
        $html .= '</tr></thead>' . "\n";

        $html .= '<tbody>';
        for ($r = 2; $r < count($block); $r++) {
            if (trim($block[$r]) === '') {
                continue;
            }
            $cells = $this->splitTableRow($block[$r]);
            $html .= '<tr>';
            foreach ($cells as $idx => $cell) {
                $a = isset($aligns[$idx]) ? $aligns[$idx] : 'left';
                $html .= '<td style="text-align:' . $a . '">' . $cell . '</td>';
            }
            $html .= '</tr>';
        }
        $html .= '</tbody>' . "\n";
        $html .= '</table>';

        return $html;
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
     * Supported keys: publish_date, scheduled, scheduled_date, date
     * Returns null when no future scheduling is requested.
     *
     * @param array $properties Frontmatter properties
     * @param int $now Current timestamp
     * @return int|null UNIX timestamp if scheduling is requested, null otherwise
     */
    public function extractScheduleDate($properties, $now) {
        $parser = new ArticleParser();
        foreach (['publish_date', 'scheduled', 'scheduled_date', 'date'] as $candidate) {
            if (isset($properties[$candidate]) && $properties[$candidate] !== '') {
                $ts = $parser->parseDate($properties[$candidate]);
                if ($ts > $now) {
                    return $ts;
                }
            }
        }
        return null;
    }
}