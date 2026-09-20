<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Unit tests for the PublishArticleLogger utility and the
 * publisharticle_log() procedural wrapper (WORKPLAN Task 5.2, R19).
 *
 * The logger writes to PHP's error_log() by default; these tests use the
 * static capture()/release() helpers so the output is diverted into an
 * in-memory buffer instead (no global side effects, no temp error_log file).
 *
 * Threshold selection uses PublishArticleLogger::setLevel() because the
 * canonical plugin option (plugin_getoptions('publisharticle')['log_level'])
 * is backed by a fixed empty stub in tests/bootstrap.php; the plugin-option
 * side is covered here via publisharticle_get_options() (default 'info')
 * and PublishArticleLogger::normalizeLevel().
 */
class PublishArticleLoggerTest extends TestCase {

    protected function tearDown(): void {
        // Always stop capturing and reset any forced threshold so a failed
        // test never leaks state into the next one.
        PublishArticleLogger::release();
        PublishArticleLogger::setLevel(null);
    }

    // ── Line format ────────────────────────────────────────────

    public function testLineFormat(): void {
        PublishArticleLogger::setLevel('info');
        PublishArticleLogger::capture();
        PublishArticleLogger::info('ArticleImporter::importFile: importing x.md');
        $lines = PublishArticleLogger::release();

        $this->assertCount(1, $lines);
        $this->assertSame(
            '[publisharticle] INFO ArticleImporter::importFile: importing x.md',
            $lines[0]
        );
    }

    public function testContextAppendedAsKv(): void {
        PublishArticleLogger::setLevel('debug');
        PublishArticleLogger::capture();
        PublishArticleLogger::debug('parsed', ['subject' => 'Hello', 'n' => 2]);
        $lines = PublishArticleLogger::release();

        $this->assertCount(1, $lines);
        $this->assertSame(
            '[publisharticle] DEBUG parsed [subject=Hello n=2]',
            $lines[0]
        );
    }

    public function testNonScalarContextIsJsonEncoded(): void {
        PublishArticleLogger::setLevel('warn');
        PublishArticleLogger::capture();
        PublishArticleLogger::warn('array context', ['ids' => [1, 2]]);
        $lines = PublishArticleLogger::release();

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('[ids=[1,2]]', $lines[0]);
    }

    // ── Threshold filtering ────────────────────────────────────

    public function testDebugThresholdEmitsEveryLevel(): void {
        PublishArticleLogger::setLevel('debug');
        PublishArticleLogger::capture();
        PublishArticleLogger::debug('d');
        PublishArticleLogger::info('i');
        PublishArticleLogger::warn('w');
        PublishArticleLogger::error('e');
        PublishArticleLogger::fatal('f');
        $lines = PublishArticleLogger::release();

        $this->assertCount(5, $lines);
        $levels = array_map(function ($l) {
            return explode(' ', substr($l, strlen('[publisharticle] ')), 2)[0];
        }, $lines);
        $this->assertSame(['DEBUG', 'INFO', 'WARN', 'ERROR', 'FATAL'], $levels);
    }

    public function testInfoThresholdSuppressesDebugOnly(): void {
        PublishArticleLogger::setLevel('info');
        PublishArticleLogger::capture();
        PublishArticleLogger::debug('noise');
        PublishArticleLogger::info('hello');
        PublishArticleLogger::warn('careful');
        $lines = PublishArticleLogger::release();

        $this->assertCount(2, $lines);
        $this->assertStringContainsString('INFO hello', $lines[0]);
        $this->assertStringContainsString('WARN careful', $lines[1]);
    }

    public function testWarnThresholdSuppressesDebugAndInfo(): void {
        PublishArticleLogger::setLevel('warn');
        PublishArticleLogger::capture();
        PublishArticleLogger::debug('noise');
        PublishArticleLogger::info('hello');
        PublishArticleLogger::warn('careful');
        PublishArticleLogger::error('boom');
        PublishArticleLogger::fatal('dead');
        $lines = PublishArticleLogger::release();

        $this->assertCount(3, $lines);
        $this->assertStringContainsString('WARN careful', $lines[0]);
        $this->assertStringContainsString('ERROR boom', $lines[1]);
        $this->assertStringContainsString('FATAL dead', $lines[2]);
    }

    public function testWarnAlwaysEmittedAtDebugThreshold(): void {
        // WARN/ERROR/FATAL are always emitted at every allowed threshold.
        PublishArticleLogger::setLevel('debug');
        PublishArticleLogger::capture();
        PublishArticleLogger::warn('w');
        PublishArticleLogger::error('e');
        PublishArticleLogger::fatal('f');
        $lines = PublishArticleLogger::release();

        $this->assertCount(3, $lines);
    }

    public function testUnknownLevelDegradesToWarn(): void {
        PublishArticleLogger::setLevel('info');
        PublishArticleLogger::capture();
        PublishArticleLogger::log('bogus', 'mystery');
        $lines = PublishArticleLogger::release();

        $this->assertCount(1, $lines);
        $this->assertStringContainsString('WARN mystery', $lines[0]);
    }

    // ── normalizeLevel ─────────────────────────────────────────

    public function testNormalizeLevelAcceptsConfiguredValues(): void {
        $this->assertSame('debug', PublishArticleLogger::normalizeLevel('debug'));
        $this->assertSame('info', PublishArticleLogger::normalizeLevel('info'));
        $this->assertSame('warn', PublishArticleLogger::normalizeLevel('warn'));
        // Case-insensitive and whitespace-tolerant
        $this->assertSame('debug', PublishArticleLogger::normalizeLevel('  DEBUG '));
        $this->assertSame('info', PublishArticleLogger::normalizeLevel('Info'));
    }

    public function testNormalizeLevelFallsBackToInfo(): void {
        $this->assertSame('info', PublishArticleLogger::normalizeLevel(''));
        $this->assertSame('info', PublishArticleLogger::normalizeLevel('banana'));
        $this->assertSame('info', PublishArticleLogger::normalizeLevel('off'));
        $this->assertSame('info', PublishArticleLogger::normalizeLevel(null));
        $this->assertSame('info', PublishArticleLogger::normalizeLevel(42));
    }

    // ── Plugin-option / config smoke test ──────────────────────

    public function testConfigOptionDefaultsToInfo(): void {
        // The config panel stores the option through publisharticle_get_options()
        // defaults; after normalization the default value is always 'info'.
        $options = publisharticle_get_options();
        $this->assertArrayHasKey('log_level', $options);
        $this->assertSame('info', $options['log_level']);
        $this->assertSame(
            'info',
            PublishArticleLogger::normalizeLevel($options['log_level'])
        );
    }

    public function testGetLevelFallsBackToDefaultInfoWithoutOption(): void {
        // The bootstrap stub for plugin_getoptions() always returns [];
        // the logger must fall back to the DEFAULT_LEVEL constant.
        $this->assertSame('info', PublishArticleLogger::getLevel());
    }

    // ── Procedural wrapper ─────────────────────────────────────

    public function testProceduralWrapperRoutesToLogger(): void {
        PublishArticleLogger::setLevel('info');
        PublishArticleLogger::capture();
        publisharticle_log('info', 'ArticleImporter::importFile: routed wrapper');
        publisharticle_log('warn', 'still routed', ['k' => 'v']);
        $lines = PublishArticleLogger::release();

        $this->assertCount(2, $lines);
        $this->assertStringContainsString('INFO ArticleImporter::importFile: routed wrapper', $lines[0]);
        $this->assertStringContainsString('WARN still routed [k=v]', $lines[1]);
    }
}