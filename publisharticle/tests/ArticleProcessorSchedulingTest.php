<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Tests for ArticleProcessor scheduling: by default a future-dated article
 * is written to the pending directory, but when the folder importer asks to
 * defer scheduling nothing is written (the source file is re-scanned).
 */
class ArticleProcessorSchedulingTest extends TestCase {

    private function pendingDir(): string {
        return rtrim(CONTENT_DIR, '/\\') . '/pending';
    }

    private function clearPending(): void {
        $dir = $this->pendingDir();
        if (!is_dir($dir)) {
            return;
        }
        foreach (glob($dir . '/*') ?: [] as $f) {
            if (is_file($f)) {
                @unlink($f);
            }
        }
        @rmdir($dir);
    }

    private function futureMarkdown(): string {
        $future = date('Y-m-d H:i:s', time() + 86400);
        return "---\nsubject: Later\npublish_date: " . $future . "\n---\nBody";
    }

    protected function setUp(): void {
        $this->clearPending();
    }

    protected function tearDown(): void {
        $this->clearPending();
    }

    public function testProcessWritesPendingEntryByDefault(): void {
        $processor = new ArticleProcessor();
        $res = $processor->process($this->futureMarkdown());

        $this->assertIsArray($res);
        $this->assertNotEmpty($res['scheduled']);
        $this->assertCount(1, glob($this->pendingDir() . '/*.txt') ?: []);
    }

    public function testProcessDeferredDoesNotWritePendingEntry(): void {
        $processor = new ArticleProcessor();
        $res = $processor->process($this->futureMarkdown(), [], '', true);

        $this->assertIsArray($res);
        $this->assertNotEmpty($res['scheduled']);
        $this->assertSame([], glob($this->pendingDir() . '/*.txt') ?: []);
    }
}
