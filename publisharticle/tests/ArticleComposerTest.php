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
        // No explicit version: the default comes from the global system_ver()
        // stub (tests/bootstrap.php) which simulates FlatPress 1.5.1.
        $entry = [
            'subject' => 'Test',
            'content' => 'Body',
            'date'    => '1234567890',
        ];
        $str = $this->composer->buildEntryString($entry);
        $this->assertStringContainsString('VERSION|fp-1.5.1', $str);
        $this->assertStringStartsWith('VERSION|fp-1.5.1|', $str);
    }

    public function testBuildEntryStringPreservesExplicitVersionOverride(): void {
        // An explicit 'version' key must always win over system_ver()/fallback,
        // whatever the value (not only the legacy fp-1.4.1).
        $str = $this->composer->buildEntryString([
            'version' => 'fp-1.6.0',
            'subject' => 'T',
            'content' => 'C',
            'date'    => '1234567890',
        ]);
        $this->assertStringStartsWith('VERSION|fp-1.6.0|', $str);
    }

    public function testDefaultVersionFallsBackWithoutSystemVer(): void {
        // The fallback path (system_ver() missing → FALLBACK_VERSION) cannot be
        // exercised in-process because PHP cannot remove the bootstrap stub at
        // runtime. It is probed in a fresh PHP process that loads the plugin
        // sources WITHOUT tests/bootstrap.php (see the fixture script).
        $src = dirname(__DIR__);
        $script = $src . '/tests/fixtures/fallback_entry.php';
        $cmd = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($src);
        exec($cmd, $output, $status);

        $this->assertSame(0, $status, 'fallback probe failed: ' . implode("\n", $output));
        $lines = array_values(array_filter($output, function ($l) {
            return trim($l) !== '';
        }));

        $this->assertCount(3, $lines);
        $this->assertSame('fp-1.5.1', ArticleComposer::FALLBACK_VERSION);
        // 1) buildEntryString() default → FALLBACK_VERSION
        $this->assertStringStartsWith('VERSION|' . ArticleComposer::FALLBACK_VERSION . '|', $lines[0]);
        // 2) buildEntry() default → FALLBACK_VERSION
        $this->assertSame(ArticleComposer::FALLBACK_VERSION, $lines[1]);
        // 3) explicit override is still preserved in the fallback runtime
        $this->assertStringStartsWith('VERSION|fp-1.6.0|', $lines[2]);
    }

    public function testLegacyFp141SerializedEntryStillReadable(): void {
        // A legacy entry crafted with VERSION|fp-1.4.1| (the old hardcoded
        // default, still written by FlatPress 1.4.x) keeps its version when
        // re-serialized with an explicit override and parses back unchanged:
        // the entry format is invariant across 1.4.1→1.5.1.
        $legacy = [
            'version'    => 'fp-1.4.1',
            'subject'    => 'Old post',
            'content'    => 'Old body',
            'author'     => 'admin',
            'date'       => '1769020790',
            'categories' => '1,2',
        ];
        $serialized = $this->composer->buildEntryString($legacy);
        $this->assertStringStartsWith('VERSION|fp-1.4.1|', $serialized);

        $parsed = (new ArticleParser())->parseEntryString($serialized);
        $this->assertSame('fp-1.4.1', $parsed['version']);
        $this->assertSame('Old post', $parsed['subject']);
        $this->assertSame('Old body', $parsed['content']);
        $this->assertSame('admin', $parsed['author']);
        $this->assertSame('1769020790', $parsed['date']);
        $this->assertSame('1,2', $parsed['categories']);
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

    public function testMarkdownHeading1(): void {
        $this->assertSame('[h2]Main[/h2]', $this->composer->markdownToBBCode('# Main'));
    }

    public function testMarkdownHeading2(): void {
        $this->assertSame('[h2]Title[/h2]', $this->composer->markdownToBBCode('## Title'));
    }

    public function testMarkdownHeading3(): void {
        $this->assertSame('[h3]Sub[/h3]', $this->composer->markdownToBBCode('### Sub'));
    }

    public function testMarkdownFencedCodeBlock(): void {
        $md = "```php\necho 'hello';\n```";
        $this->assertSame("[code]echo 'hello';[/code]", $this->composer->markdownToBBCode($md));
    }

    public function testMarkdownBlockquote(): void {
        $md = "> A wisdom quote";
        $this->assertSame("[quote]\nA wisdom quote\n[/quote]", trim($this->composer->markdownToBBCode($md)));
    }

    public function testMarkdownHorizontalRule(): void {
        $this->assertSame("[hr]", $this->composer->markdownToBBCode('---'));
        $this->assertSame("[hr]", $this->composer->markdownToBBCode('***'));
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
        // No explicit version: the default comes from the global system_ver()
        // stub (tests/bootstrap.php) which simulates FlatPress 1.5.1.
        $result = $this->composer->buildEntry([], 'Hello', 1700000000);
        $this->assertSame('fp-1.5.1', $result['version']);
        $this->assertSame('', $result['subject']);
        $this->assertSame('admin', $result['author']);
        $this->assertSame(1700000000, $result['date']);
        $this->assertSame('', $result['categories']);
    }

    public function testBuildEntryPreservesExplicitVersionOverride(): void {
        // An explicit 'version' property must always win over system_ver()/fallback.
        $result = $this->composer->buildEntry(['version' => 'fp-1.4.1'], 'Hello', 0);
        $this->assertSame('fp-1.4.1', $result['version']);

        $result = $this->composer->buildEntry(['version' => 'fp-1.6.0'], 'Hello', 0);
        $this->assertSame('fp-1.6.0', $result['version']);
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

    // ── extractScheduleDate: date: key and combinations ───────────

    public function testExtractScheduleDateAcceptsDateKeyFuture(): void {
        $future = time() + 86400;
        $ts = $this->composer->extractScheduleDate(['date' => (string) $future], time());
        $this->assertNotNull($ts);
        $this->assertGreaterThan(time(), $ts);
    }

    public function testExtractScheduleDateAcceptsDateKeyIsoString(): void {
        $future = date('Y-m-d H:i:s', time() + 86400);
        $ts = $this->composer->extractScheduleDate(['date' => $future], time());
        $this->assertNotNull($ts);
        $this->assertGreaterThan(time(), $ts);
    }

    public function testExtractScheduleDateReturnsNullForPastDateKey(): void {
        $past = time() - 86400;
        $ts = $this->composer->extractScheduleDate(['date' => (string) $past], time());
        $this->assertNull($ts);
    }

    public function testExtractScheduleDateUsesFutureFieldAmongCombination(): void {
        $future = time() + 86400;
        $past = time() - 86400;
        // date is past, publish_date is future → returns publish_date
        $ts = $this->composer->extractScheduleDate([
            'date' => (string) $past,
            'publish_date' => (string) $future,
        ], time());
        $this->assertNotNull($ts);
        $this->assertSame($future, $ts);

        // date is future, publish_date is past → returns date
        $ts = $this->composer->extractScheduleDate([
            'date' => (string) $future,
            'publish_date' => (string) $past,
        ], time());
        $this->assertNotNull($ts);
        $this->assertGreaterThan(time(), $ts);
    }

    public function testExtractScheduleDateReturnsNullWhenAllPast(): void {
        $past = time() - 86400;
        $ts = $this->composer->extractScheduleDate([
            'date' => (string) $past,
            'publish_date' => (string) $past,
            'scheduled' => (string) $past,
            'scheduled_date' => (string) $past,
        ], time());
        $this->assertNull($ts);
    }

    // ── Tables ───────────────────────────────────────────────────

    public function testMarkdownTableBasic(): void {
        $md = "| Nome | Ruolo |\n|------|-------|\n| Alice | Admin |\n| Bob | Editor |";
        $result = $this->composer->markdownToBBCode($md);
        $this->assertStringContainsString('<table>', $result);
        $this->assertStringContainsString('<th', $result);
        $this->assertStringContainsString('Nome', $result);
        $this->assertStringContainsString('Alice', $result);
        $this->assertStringContainsString('Editor', $result);
    }

    public function testMarkdownTableAlignment(): void {
        $md = "| Sinistra | Centro | Destra |\n|:---------|:------:|-------:|\n| a | b | c |";
        $result = $this->composer->markdownToBBCode($md);
        $this->assertStringContainsString('text-align:left', $result);
        $this->assertStringContainsString('text-align:center', $result);
        $this->assertStringContainsString('text-align:right', $result);
    }

    public function testMarkdownTableTooFewRowsIgnored(): void {
        $md = "| Header |\n|--------|";
        $result = $this->composer->markdownToBBCode($md);
        $this->assertStringNotContainsString('<table>', $result);
    }

    // ── Height in image BBCode ───────────────────────────────────

    public function testMarkdownImageWithHeight(): void {
        $result = $this->composer->markdownToBBCode('![pic](photo.jpg width=500 height=300)');
        $this->assertStringContainsString('width="500"', $result);
        $this->assertStringContainsString('height="300"', $result);
    }

    public function testMarkdownImageWithHeightOnly(): void {
        $result = $this->composer->markdownToBBCode('![pic](photo.jpg height=250)');
        $this->assertStringNotContainsString('width=', $result);
        $this->assertStringContainsString('height="250"', $result);
    }

    // ── Protocol-relative URLs ───────────────────────────────────

    public function testMarkdownImageProtocolRelativeUrl(): void {
        $result = $this->composer->markdownToBBCode('![pic](//cdn.example.com/pic.png)');
        $this->assertStringContainsString('https://cdn.example.com/pic.png', $result);
        $this->assertStringNotContainsString('images/', $result);
    }

    // ── Task lists ───────────────────────────────────────────────

    public function testMarkdownTaskListChecked(): void {
        $result = $this->composer->markdownToBBCode('- [x] Fatto');
        $this->assertStringContainsString('checkbox', $result);
        $this->assertStringContainsString('checked', $result);
        $this->assertStringContainsString('Fatto', $result);
    }

    public function testMarkdownTaskListUnchecked(): void {
        $result = $this->composer->markdownToBBCode('- [ ] Da fare');
        $this->assertStringContainsString('checkbox', $result);
        $this->assertStringNotContainsString('checked', $result);
        $this->assertStringContainsString('Da fare', $result);
    }
}
