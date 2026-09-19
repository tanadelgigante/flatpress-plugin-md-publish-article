<?php
date_default_timezone_set('UTC');

/**
 * PHPUnit bootstrap for Publish Article plugin tests.
 *
 * Loads all source files and stubs any FlatPress APIs so the tests
 * run in isolation — no FlatPress installation required.
 */

// Source directory (same level as tests/)
$srcDir = __DIR__ . '/..';

// Add source dir to include_path so bare require_once paths resolve
ini_set('include_path', $srcDir . PATH_SEPARATOR . get_include_path());

// ── Stub FlatPress constants that source files check ──────────────
if (!defined('CONTENT_DIR')) {
    define('CONTENT_DIR', $srcDir . '/tests/fixtures/content/');
}
if (!defined('IMAGES_DIR')) {
    define('IMAGES_DIR', $srcDir . '/tests/fixtures/content/images/');
}

// ── Stub FlatPress functions referenced by the plugin file ────────
if (!function_exists('plugin_getoptions')) {
    function plugin_getoptions($plugin) {
        static $options = [];
        return isset($options[$plugin]) ? $options[$plugin] : [];
    }
}
if (!function_exists('plugin_addoption')) {
    function plugin_addoption($plugin, $key, $val) {
        // no-op in test context
    }
}
if (!function_exists('plugin_saveoptions')) {
    function plugin_saveoptions($plugin) {
        return true;
    }
}
if (!function_exists('plugin_getdir')) {
    function plugin_getdir($plugin) {
        global $srcDir;
        return rtrim($srcDir, '/\\') . '/';
    }
}
if (!function_exists('add_action')) {
    function add_action($hook, $callback, $priority = 10, $args = 1) {
        // no-op
    }
}
if (!function_exists('entry_dir')) {
    function entry_dir($id) {
        return '';
    }
}

// ── Stub FlatPress system_ver() ───────────────────────────────────
// FlatPress defines system_ver() = 'fp-' . SYSTEM_VER in core.system.php
// (1.4.x → 'fp-1.4.1', 1.5.x → 'fp-1.5.1'). ArticleComposer uses it as the
// default entry VERSION tag and falls back to FALLBACK_VERSION when it is
// missing (unit-test bootstrap, no FlatPress runtime).
//
// SCELTA (Fase 3, Task 3.1): the default test runtime simulates FlatPress
// 1.5.1, so this stub returns 'fp-1.5.1' (matching the value written by
// admin.entry.write.php on a real 1.5.x install). No test in the main suite
// can observe the fallback path because PHP cannot "unset" a function in the
// same process; the absent-system_ver fallback is covered in isolation by
// ArticleComposerTest::testDefaultVersionFallsBackWithoutSystemVer(), which
// spawns a fresh PHP process that loads the sources WITHOUT this stub.
if (!function_exists('system_ver')) {
    function system_ver() {
        return 'fp-1.5.1';
    }
}

// ── Load all source classes ───────────────────────────────────────
// These files use require_once with bare names (e.g. require_once 'ArticleParser.php')
// which will resolve via the include_path set above.
require_once 'ArticleParser.php';
require_once 'ArticleComposer.php';
require_once 'ArticleWriter.php';
require_once 'CategoryResolver.php';
require_once 'ImageUploader.php';
require_once 'ArticleProcessor.php';
require_once 'ArticleImporter.php';

// Load the plugin file so the cron matcher functions are available.
// The plugin's require_once statements will be no-ops (already loaded).
require_once 'plugin.publisharticle.php';
