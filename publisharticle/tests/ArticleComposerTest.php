<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ArticleComposer.
 */
class ArticleComposerTest extends TestCase {

    private ArticleComposer $composer;

    protected function setUp(): void {
        $this->composer = new ArticleComposer();
    }

    // ── generateEntryId ────────────────────────────────────────────

    public function testGenerateEntryIdFormat(): void {
        // 2026-01-15 10:30:45 UTC → entry260115-103045
        $ts = gmmktime(10, 30, 45, 1, 15, 2026);
        $id = $this->composer->generateEntryId($ts);
        $this->assertMatchesRegularExpression('/^entry\d{6}-\d{6}$/', $id);
        $this->assertSame('entry260115-103045', $id);
    }

    // ── buildEntryString ───────────────────────────────────────────

    public function testBuildEntryStringStandard(): void {
        $entry = [
            'version'    => 'fp-1.4.1',
            'subject'    => 'My Title',
            'content'    => 'Hello',
            'author'     => 'admin',
            'date'       => '1769020790',
            'categories' => '5,15',
        ];
        $str = $this->composer->buildEntryString($entry);

        $this->assertStringStartsWith('VERSION|fp-1.4.1|', $str);
        $this->assertStringContainsString('SUBJECT|My Title', $str);
        $this->assertStringContainsString('CONTENT|Hello', $str);
        $this->assertStringContainsString('AUTHOR|admin', $str);
        $this->assertStringContainsString('DATE|1769020790', $str);
        $this->assertStringContainsString('CATEGORIES|5,15', $str);
        $this->assertStringEndsWith('|', $str);
    }

    public function testBuildEntryStringOmitsEmptyCategories(): void {
        $entry = [
            'subject' => 'Test',
            'content' => 'Body',
            'date'    => '1234567890',
        ];
        $str = $this->composer->buildEntryString($entry);
        $this->assertStringNotContainsString('CATEGORIES', $str);
    }

    public function testBuildEntryStringDefaultVersion(): void {
        $entry = [
            'subject' => 'Test',
            'content' => 'Body',
            'date'    => '1234567890',
        ];
        $str = $this->composer->buildEntryString($entry);
        $this->assertStringContainsString('VERSION|fp-1.4.1', $str);
    }

    // ── markdownToBBCode ───────────────────────────────────────────

    public function testMarkdownBold(): void {
        $this->assertSame('[b]strong[/b]', $this->composer->markdownToBBCode('**strong**'));
        $this->assertSame('[b]strong[/b]', $this->composer->markdownToBBCode('__strong__'));
    }

    public function testMarkdownItalic(): void {
        $this->assertSame('[i]em[/i]', $this->composer->markdownToBBCode('*em*'));
    }

    public function testMarkdownStrikethrough(): void {
        $this->assertSame('[del]gone[/del]', $this->composer->markdownToBBCode('~~gone~~'));
    }

    public function testMarkdownInlineCode(): void {
        $this->assertSame('[code]foo[/code]', $this->composer->markdownToBBCode('`foo`'));
    }

    public function testMarkdownHeading2(): void {
        $this->assertSame('[h2]Title[/h2]', $this->composer->markdownToBBCode('## Title'));
    }

    public function testMarkdownHeading3(): void {
        $this->assertSame('[h3]Sub[/h3]', $this->composer->markdownToBBCode('### Sub'));
    }

    public function testMarkdownLink(): void {
        $this->assertSame('[url="https://example.com"]click[/url]',
            $this->composer->markdownToBBCode('[click](https://example.com)'));
    }

    public function testMarkdownImagePlainFilename(): void {
        $result = $this->composer->markdownToBBCode('![alt](photo.jpg)');
        $this->assertStringContainsString('images/photo.jpg', $result);
        $this->assertStringContainsString('alt="alt"', $result);
    }

    public function testMarkdownImageWithWidth(): void {
        $result = $this->composer->markdownToBBCode('![pic](photo.jpg width=500)');
        $this->assertStringContainsString('width="500"', $result);
    }

    public function testMarkdownImageRemoteUrlUntouched(): void {
        $result = $this->composer->markdownToBBCode('![pic](https://example.com/photo.jpg)');
        $this->assertStringContainsString('https://example.com/photo.jpg', $result);
        $this->assertStringNotContainsString('images/https', $result);
    }

    public function testMarkdownImageDataUriUntouched(): void {
        $result = $this->composer->markdownToBBCode('![x](data:image/png;base64,abc)');
        $this->assertStringContainsString('data:image/png;base64,abc', $result);
    }

    // ── buildEntry ─────────────────────────────────────────────────

    public function testBuildEntryDefaults(): void {
        $result = $this->composer->buildEntry([], 'Hello', 1700000000);
        $this->assertSame('fp-1.4.1', $result['version']);
        $this->assertSame('', $result['subject']);
        $this->assertSame('admin', $result['author']);
        $this->assertSame(1700000000, $result['date']);
        $this->assertSame('', $result['categories']);
    }

    public function testBuildEntryWithSubject(): void {
        $result = $this->composer->buildEntry(['subject' => 'Hi'], 'Body', 0);
        $this->assertSame('Hi', $result['subject']);
    }

    public function testBuildEntryWithTitleAlias(): void {
        $result = $this->composer->buildEntry(['title' => 'Alias'], 'Body', 0);
        $this->assertSame('Alias', $result['subject']);
    }

    public function testBuildEntryContentIsBBCode(): void {
        $result = $this->composer->buildEntry([], '**bold** text', 0);
        $this->assertStringContainsString('[b]bold[/b]', $result['content']);
    }

    // ── extractScheduleDate ────────────────────────────────────────

    public function testExtractScheduleDateReturnsFutureTimestamp(): void {
        $future = time() + 86400;
        $ts = $this->composer->extractScheduleDate(['publish_date' => (string) $future], time());
        $this->assertNotNull($ts);
        $this->assertGreaterThan(time(), $ts);
    }

    public function testExtractScheduleDateReturnsNullForPastDate(): void {
        $past = time() - 86400;
        $ts = $this->composer->extractScheduleDate(['publish_date' => (string) $past], time());
        $this->assertNull($ts);
    }

    public function testExtractScheduleDateReturnsNullWhenNoDate(): void {
        $ts = $this->composer->extractScheduleDate(['subject' => 'No date'], time());
        $this->assertNull($ts);
    }

    public function testExtractScheduleDateAcceptsScheduledKey(): void {
        $future = time() + 86400;
        $ts = $this->composer->extractScheduleDate(['scheduled' => (string) $future], time());
        $this->assertNotNull($ts);
        $this->assertGreaterThan(time(), $ts);
    }

    public function testExtractScheduleDateAcceptsScheduledDateKey(): void {
        $future = time() + 86400;
        $ts = $this->composer->extractScheduleDate(['scheduled_date' => (string) $future], time());
        $this->assertNotNull($ts);
    }
}
