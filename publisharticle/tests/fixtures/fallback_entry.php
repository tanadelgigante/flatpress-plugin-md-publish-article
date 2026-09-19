<?php
/**
 * Standalone fallback probe for ArticleComposer.
 *
 * Used by ArticleComposerTest::testDefaultVersionFallsBackWithoutSystemVer().
 *
 * Runs in a FRESH PHP process that loads the plugin sources WITHOUT
 * tests/bootstrap.php, so the global system_ver() stub is NOT defined.
 * ArticleComposer::buildEntryString()/buildEntry() must therefore fall back
 * to ArticleComposer::FALLBACK_VERSION as the default entry version.
 *
 * Usage: php fallback_entry.php <source-dir>
 * (the source dir is prepended to include_path so the bare require_once
 * statements of the plugin sources resolve — same trick as tests/bootstrap.php)
 *
 * Output (one line per probe):
 *   line 1: serialized entry with the DEFAULT version (no 'version' key)
 *   line 2: buildEntry()['version'] with no version property
 *   line 3: serialized entry with an explicit 'version' override
 */

if (!isset($argv[1])) {
    fwrite(STDERR, "usage: php fallback_entry.php <source-dir>\n");
    exit(3);
}

ini_set('include_path', $argv[1] . PATH_SEPARATOR . ini_get('include_path'));

require_once 'ArticleParser.php';
require_once 'ArticleComposer.php';

// Sanity guard: this probe MUST run without the bootstrap stub.
if (function_exists('system_ver')) {
    fwrite(STDERR, "system_ver() is unexpectedly defined — cannot probe the fallback path\n");
    exit(2);
}

$composer = new ArticleComposer();

// 1) buildEntryString() default version -> FALLBACK_VERSION
echo $composer->buildEntryString([
    'subject' => 'Fallback',
    'content' => 'Body',
    'date'    => '1234567890',
]), "\n";

// 2) buildEntry() default version -> FALLBACK_VERSION
echo $composer->buildEntry([], 'Body', 1700000000)['version'], "\n";

// 3) explicit version override is still honoured in the fallback runtime
echo $composer->buildEntryString([
    'version' => 'fp-1.6.0',
    'subject' => 'T',
    'content' => 'C',
    'date'    => '1234567890',
]), "\n";