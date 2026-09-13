<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ArticleParser.
 */
class ArticleParserTest extends TestCase {

    private ArticleParser $parser;

    protected function setUp(): void {
        $this->parser = new ArticleParser();
    }

    // ── parseMarkdown ──────────────────────────────────────────────

    public function testParseMarkdownWithFrontmatter(): void {
        $text  = "---\nsubject: Hello World\nauthor: bob\n---\nSome content";
        $result = $this->parser->parseMarkdown($text);

        $this->assertSame('Hello World', $result['properties']['subject']);
        $this->assertSame('bob', $result['properties']['author']);
        $this->assertSame('Some content', $result['content']);
    }

    public function testParseMarkdownWithoutFrontmatter(): void {
        $result = $this->parser->parseMarkdown('Plain text only');
        $this->assertSame([], $result['properties']);
        $this->assertSame('Plain text only', $result['content']);
    }

    public function testParseMarkdownEmptyContent(): void {
        $text  = "---\ntitle: X\n---\n";
        $result = $this->parser->parseMarkdown($text);
        $this->assertSame('X', $result['properties']['title']);
        $this->assertSame('', $result['content']);
    }

    public function testParseMarkdownMultilineContent(): void {
        $text  = "---\ntitle: Multi\n---\nLine 1\nLine 2\nLine 3";
        $result = $this->parser->parseMarkdown($text);
        $this->assertStringContainsString('Line 1', $result['content']);
        $this->assertStringContainsString('Line 3', $result['content']);
    }

    // ── parseEntryString ───────────────────────────────────────────

    public function testParseEntryStringStandard(): void {
        $str = "VERSION|fp-1.4.1|SUBJECT|Test Title|CONTENT|Hello|AUTHOR|admin|DATE|1769020790|CATEGORIES|5,15|";
        $entry = $this->parser->parseEntryString($str);

        $this->assertSame('fp-1.4.1', $entry['version']);
        $this->assertSame('Test Title', $entry['subject']);
        $this->assertSame('Hello', $entry['content']);
        $this->assertSame('admin', $entry['author']);
        $this->assertSame('1769020790', $entry['date']);
        $this->assertSame('5,15', $entry['categories']);
    }

    public function testParseEntryStringEmpty(): void {
        $entry = $this->parser->parseEntryString('');
        $this->assertSame([], $entry);
    }

    public function testParseEntryStringKeysAreLowercased(): void {
        $str = "VERSION|fp-1.4.1|SUBJECT|T|CONTENT|C|";
        $entry = $this->parser->parseEntryString($str);
        $this->assertArrayHasKey('version', $entry);
        $this->assertArrayHasKey('subject', $entry);
        $this->assertArrayHasKey('content', $entry);
    }

    // ── parseDate ──────────────────────────────────────────────────

    public function testParseDateNumericTimestamp(): void {
        $this->assertSame(1700000000, $this->parser->parseDate('1700000000'));
    }

    public function testParseDateIsoString(): void {
        $ts = $this->parser->parseDate('2026-01-15');
        $this->assertIsInt($ts);
        $this->assertGreaterThan(0, $ts);
        // Should parse to January 15 2026
        $this->assertSame(2026, (int) date('Y', $ts));
        $this->assertSame(1, (int) date('n', $ts));
        $this->assertSame(15, (int) date('j', $ts));
    }

    public function testParseDateWithTime(): void {
        $ts = $this->parser->parseDate('2026-09-13 14:30:00');
        $this->assertIsInt($ts);
        $this->assertSame(14, (int) date('G', $ts));
        $this->assertSame(30, (int) date('i', $ts));
    }
}
