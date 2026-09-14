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
