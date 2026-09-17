<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for ArticleImporter — pure-logic methods (applyDefaults,
 * scan, scan-extension handling) plus basic constructor behaviour.
 */
class ArticleImporterTest extends TestCase {

    private const TMP_DIR = __DIR__ . '/fixtures/tmp';

    protected function setUp(): void {
        // Clean and recreate the temp fixture dir
        if (is_dir(self::TMP_DIR)) {
            $this->rrmdir(self::TMP_DIR);
        }
        mkdir(self::TMP_DIR, 0777, true);
    }

    protected function tearDown(): void {
        if (is_dir(self::TMP_DIR)) {
            $this->rrmdir(self::TMP_DIR);
        }
    }

    private function rrmdir(string $dir): void {
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                $this->rrmdir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }

    // ── applyDefaults ──────────────────────────────────────────────

    public function testApplyDefaultsAddsCategoryToExistingFrontmatter(): void {
        $importer = new ArticleImporter(null, self::TMP_DIR . '/', ['default_category' => '7']);
        $content = "---\nsubject: Test\n---\nBody";
        $result = $importer->applyDefaults($content);

        $this->assertStringContainsString('categories: 7', $result);
        $this->assertStringContainsString('subject: Test', $result);
        $this->assertStringContainsString('Body', $result);
    }

    public function testApplyDefaultsCreatesFrontmatterWhenMissing(): void {
        $importer = new ArticleImporter(null, self::TMP_DIR . '/', ['default_category' => '7']);
        $result = $importer->applyDefaults("Plain content");

        $this->assertStringContainsString('categories: 7', $result);
        $this->assertStringContainsString('Plain content', $result);
    }

    public function testApplyDefaultsDoesNotOverwriteExistingCategory(): void {
        $importer = new ArticleImporter(null, self::TMP_DIR . '/', ['default_category' => '7']);
        $content = "---\nsubject: T\ncategories: 3\n---\nBody";
        $result = $importer->applyDefaults($content);

        $this->assertStringContainsString('categories: 3', $result);
        // The default "7" must not be injected
        $this->assertStringNotContainsString('categories: 7', $result);
    }

    public function testApplyDefaultsAddsDraftStatus(): void {
        $importer = new ArticleImporter(null, self::TMP_DIR . '/', ['default_status' => 'draft']);
        $result = $importer->applyDefaults("---\ntitle: X\n---\nBody");

        $this->assertStringContainsString('status: draft', $result);
    }

    public function testApplyDefaultsNoDefaultsReturnsUnchanged(): void {
        $importer = new ArticleImporter(null, self::TMP_DIR . '/', []);
        $content = "---\nsubject: T\n---\nBody";
        $this->assertSame($content, $importer->applyDefaults($content));
    }

    public function testApplyDefaultsDefaultStatusPublishInjected(): void {
        $importer = new ArticleImporter(null, self::TMP_DIR . '/', ['default_status' => 'publish']);
        $result = $importer->applyDefaults("---\nsubject: T\n---\nBody");
        // publish status is the FlatPress default → nothing injected
        $this->assertStringNotContainsString('status:', $result);
    }

    // ── scan ───────────────────────────────────────────────────────

    public function testScanFindsMarkdownExtensions(): void {
        file_put_contents(self::TMP_DIR . '/a.md', 'a');
        file_put_contents(self::TMP_DIR . '/b.markdown', 'b');
        file_put_contents(self::TMP_DIR . '/c.mdown', 'c');
        file_put_contents(self::TMP_DIR . '/d.txt', 'd');

        $importer = new ArticleImporter(null, self::TMP_DIR . '/', []);
        $files = $importer->scan();

        $this->assertCount(4, $files);
    }

    public function testScanIgnoresOtherExtensions(): void {
        file_put_contents(self::TMP_DIR . '/a.md', 'a');
        file_put_contents(self::TMP_DIR . '/image.jpg', 'j');

        $importer = new ArticleImporter(null, self::TMP_DIR . '/', []);
        $files = $importer->scan();

        $this->assertCount(1, $files);
        $this->assertStringContainsString('a.md', $files[0]);
    }

    public function testScanEmptyDirReturnsEmpty(): void {
        $importer = new ArticleImporter(null, self::TMP_DIR . '/', []);
        $this->assertSame([], $importer->scan());
    }

    public function testScanMissingDirReturnsEmpty(): void {
        $importer = new ArticleImporter(null, self::TMP_DIR . '/does-not-exist/', []);
        $this->assertSame([], $importer->scan());
    }

    // ── moveTo ─────────────────────────────────────────────────────

    public function testMoveToCreatesSubdirAndMovesFile(): void {
        $tmp = self::TMP_DIR . '/';
        file_put_contents($tmp . 'x.md', 'x');

        $importer = new ArticleImporter(null, $tmp, []);
        $ok = $importer->moveTo($tmp . 'x.md', 'done');

        $this->assertTrue($ok);
        $this->assertFileExists($tmp . 'done/x.md');
        $this->assertFileDoesNotExist($tmp . 'x.md');
    }

    public function testMoveToConflictingNameGetsTimestampPrefix(): void {
        $tmp = self::TMP_DIR . '/';
        file_put_contents($tmp . 'x.md', 'one');
        mkdir($tmp . 'done');
        file_put_contents($tmp . 'done/x.md', 'two');

        $importer = new ArticleImporter(null, $tmp, []);
        $ok = $importer->moveTo($tmp . 'x.md', 'done');

        $this->assertTrue($ok);
        $this->assertFileDoesNotExist($tmp . 'x.md');
        // A new timestamped file exists in done/
        $entries = array_diff(scandir($tmp . 'done'), ['.', '..']);
        $this->assertCount(2, $entries);
        $this->assertFileExists($tmp . 'done/x.md'); // original still there
    }

    // ── moveTo: .note + sibling images ─────────────────────────────

    public function testMoveToWritesNoteFile(): void {
        $tmp = self::TMP_DIR . '/';
        file_put_contents($tmp . 'x.md', 'x');

        $importer = new ArticleImporter(null, $tmp, []);
        $ok = $importer->moveTo($tmp . 'x.md', 'done', 'Import done');

        $this->assertTrue($ok);
        $this->assertFileExists($tmp . 'done/x.md');
        $this->assertSame('Import done', file_get_contents($tmp . 'done/x.md.note'));
    }

    public function testMoveToMovesSiblingImages(): void {
        $tmp = self::TMP_DIR . '/';
        file_put_contents($tmp . 'post.md', 'x');
        file_put_contents($tmp . 'post.jpg', 'img');

        $importer = new ArticleImporter(null, $tmp, []);
        $ok = $importer->moveTo($tmp . 'post.md', 'done');

        $this->assertTrue($ok);
        $this->assertFileExists($tmp . 'done/post.md');
        $this->assertFileExists($tmp . 'done/post.jpg');
        $this->assertFileDoesNotExist($tmp . 'post.jpg');
    }

    // ── importFile: done/ and failed/ routing ──────────────────────

    public function testImportFileMovesToDone(): void {
        $tmp = self::TMP_DIR . '/';
        file_put_contents($tmp . 'post.md', "---\ntitle: Test\n---\nBody");

        $importer = new ArticleImporter(new FakeSuccessProcessor(), $tmp, []);
        $result = $importer->importFile($tmp . 'post.md');

        $this->assertTrue($result['success']);
        $this->assertFileDoesNotExist($tmp . 'post.md');
        $this->assertFileExists($tmp . 'done/post.md');
        $this->assertFileExists($tmp . 'done/post.md.note');
    }

    public function testImportFileMovesToFailed(): void {
        $tmp = self::TMP_DIR . '/';
        file_put_contents($tmp . 'bad.md', "---\ntitle: Bad\n---\nBody");

        $importer = new ArticleImporter(new FakeFailingProcessor(), $tmp, []);
        $result = $importer->importFile($tmp . 'bad.md');

        $this->assertFalse($result['success']);
        $this->assertFileDoesNotExist($tmp . 'bad.md');
        $this->assertFileExists($tmp . 'failed/bad.md');
        $this->assertFileExists($tmp . 'failed/bad.md.note');
    }

    // ── constructor / directories ──────────────────────────────────

    public function testCustomImportDirIsUsed(): void {
        $importer = new ArticleImporter(null, 'custom/path/', []);
        $this->assertSame('custom/path/', $importer->getImportDir());
    }

    public function testDefaultImportDirUsesContentDirConstant(): void {
        $importer = new ArticleImporter(null, null, []);
        $this->assertStringContainsString('import-in', $importer->getImportDir());
    }

    public function testEmptyStringImportDirFallsBackToDefault(): void {
        $importer = new ArticleImporter(null, '', []);
        $this->assertSame($importer->getDefaultImportDir(), $importer->getImportDir());
    }

    public function testSetImportDirEmptyStringFallsBackToDefault(): void {
        $importer = new ArticleImporter(null, null, []);
        $importer->setImportDir('');
        $this->assertSame($importer->getDefaultImportDir(), $importer->getImportDir());
    }

    public function testImportDirAlwaysGetsTrailingSlash(): void {
        $importer = new ArticleImporter(null, 'custom/path', []);
        $this->assertSame('custom/path/', $importer->getImportDir());

        $importer = new ArticleImporter(null, 'custom/path/', []);
        $this->assertSame('custom/path/', $importer->getImportDir());

        $importer = new ArticleImporter(null, 'custom\\path', []);
        $this->assertSame('custom\\path/', $importer->getImportDir());
    }

    public function testSetImportDirAlwaysGetsTrailingSlash(): void {
        $importer = new ArticleImporter(null, null, []);
        $importer->setImportDir('custom/path');
        $this->assertSame('custom/path/', $importer->getImportDir());
    }
}

/**
 * Stub processor that reports a successful publication.
 */
class FakeSuccessProcessor {
    public function process($rawMarkdown, $files = [], $imagesPrefix = '') {
        return ['id' => 'entry260915-120000', 'scheduled' => false, 'images' => []];
    }

    public function getImageUploader() {
        return new ImageUploader();
    }
}

/**
 * Stub processor that reports a processing failure.
 */
class FakeFailingProcessor {
    public function process($rawMarkdown, $files = [], $imagesPrefix = '') {
        return false;
    }

    public function getImageUploader() {
        return new ImageUploader();
    }
}