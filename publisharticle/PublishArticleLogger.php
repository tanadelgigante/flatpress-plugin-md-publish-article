<?php

/**
 * PublishArticleLogger — leveled logging utility for the Publish Article plugin.
 *
 * Requirement R19 (WORKPLAN Phase 5, Task 5.2). Design decisions:
 *
 * - Levels: DEBUG, INFO, WARN, ERROR, FATAL (ordered by severity).
 * - Configurable threshold: the plugin option `log_level` accepts the values
 *   `debug` | `info` | `warn` (default `info`). DEBUG is emitted only when
 *   the threshold is `debug`; INFO is emitted when the threshold is `info`
 *   or `debug`. WARN, ERROR and FATAL are ALWAYS emitted because their
 *   severity is never below any allowed threshold — critical messages can
 *   never be silenced by configuration.
 * - Output channel: PHP's error_log() with the `[publisharticle]` prefix.
 *   No external dependency; works on FlatPress 1.4.x / 1.5.x and outside the
 *   FlatPress runtime (unit tests). Messages are expected to start with
 *   __METHOD__ (e.g. 'ArticleImporter::importFile: ...') for consistency with
 *   the historical bare error_log() calls this logger replaces.
 * - Class vs function (documented choice): the class owns the constants and
 *   the logic; the thin global helper publisharticle_log() is the recommended
 *   call-site entry point. The helper guards with class_exists(), so a caller
 *   never fatals if the logger class is unavailable for any reason
 *   (backwards-compatible by design). Call sites pass plain string levels
 *   ('debug', 'info', 'warn', 'error', 'fatal') so they do not reference
 *   class constants and remain safe when the class is missing.
 * - Testability: capture()/release() divert the output into an in-memory
 *   buffer instead of calling error_log(); setLevel() forces a threshold.
 *   The canonical level source remains the `log_level` plugin option.
 *
 * FlatPress APIs referenced:
 * - plugin_getoptions('publisharticle') to read the `log_level` option.
 *
 * Known limitation: on the CLI SAPI error_log() writes to stderr, so during
 * PHPUnit runs WARN/ERROR messages of exercised failure paths appear in the
 * test output. They do not trigger PHP's error handler, therefore they do not
 * fail the suite (failOnWarning only sees real PHP warnings/notices).
 */
class PublishArticleLogger {

    /** Level constants, ordered from least to most severe. */
    const DEBUG = 'DEBUG';
    const INFO  = 'INFO';
    const WARN  = 'WARN';
    const ERROR = 'ERROR';
    const FATAL = 'FATAL';

    /** Default threshold used when the plugin option is missing/invalid. */
    const DEFAULT_LEVEL = 'info';

    /** Allowed configurable thresholds (WARN/ERROR/FATAL are always active). */
    const VALID_LEVELS = ['debug', 'info', 'warn'];

    /**
     * Severity order used for threshold filtering.
     * Lowercase keys mirror the `log_level` option values.
     */
    private static $severity = [
        'debug' => 0,
        'info'  => 1,
        'warn'  => 2,
        'error' => 3,
        'fatal' => 4,
    ];

    /** Forced threshold override (null = read the plugin option). */
    private static $forcedLevel = null;

    /** Capture buffer (null = write to the real error log). */
    private static $captured = null;

    /**
     * Normalizes a proposed `log_level` value.
     * Accepts `debug`, `info` or `warn` (case-insensitive, whitespace
     * tolerant) and returns the canonical lowercase form. Anything else
     * (including an empty or missing value) falls back to the default `info`,
     * so the option is always well-formed after being saved from the panel.
     *
     * @param mixed $level Raw value (usually a string from the config panel)
     * @return string 'debug'|'info'|'warn'
     */
    public static function normalizeLevel($level) {
        $level = is_string($level) ? strtolower(trim($level)) : '';
        return in_array($level, self::VALID_LEVELS, true) ? $level : self::DEFAULT_LEVEL;
    }

    /**
     * Forces the effective threshold. Mostly useful for tests and for callers
     * that need a runtime override; the canonical source remains the
     * `log_level` plugin option (see getLevel()). Pass null to stop forcing
     * and go back to reading the plugin option.
     *
     * @param string|null $level 'debug'|'info'|'warn' or null to reset
     */
    public static function setLevel($level = null) {
        self::$forcedLevel = $level === null ? null : self::normalizeLevel($level);
    }

    /**
     * Returns the effective threshold ('debug'|'info'|'warn').
     * Resolution order:
     *   1. a level forced with setLevel();
     *   2. the `log_level` plugin option read via plugin_getoptions()
     *      (guarded with function_exists so the logger also works in
     *      standalone/unit-test contexts without FlatPress);
     *   3. the DEFAULT_LEVEL fallback.
     *
     * @return string 'debug'|'info'|'warn'
     */
    public static function getLevel() {
        if (self::$forcedLevel !== null) {
            return self::$forcedLevel;
        }
        if (function_exists('plugin_getoptions')) {
            $options = plugin_getoptions('publisharticle');
            if (is_array($options) && isset($options['log_level'])) {
                return self::normalizeLevel($options['log_level']);
            }
        }
        return self::DEFAULT_LEVEL;
    }

    /**
     * Whether the given level passes the current threshold filter.
     * WARN/ERROR/FATAL are never below any allowed threshold, hence they are
     * always enabled regardless of the configured level.
     *
     * @param string $level Level label (case-insensitive)
     * @return bool
     */
    public static function isEnabled($level) {
        $level = strtolower((string) $level);
        if (!isset(self::$severity[$level])) {
            return false;
        }
        return self::$severity[$level] >= self::$severity[self::getLevel()];
    }

    /**
     * Formats one log line.
     * Format: [publisharticle] <LEVEL> <message> [<context k=v>]
     * The context part is appended only when $context is not empty.
     * Non-scalar context values are json_encode()d for readability.
     *
     * @param string $level Uppercase level label (DEBUG/INFO/WARN/ERROR/FATAL)
     * @param string $message Log message (convention: starts with __METHOD__)
     * @param array  $context Optional key => value pairs
     * @return string The formatted line
     */
    public static function formatLine($level, $message, $context = []) {
        $line = '[publisharticle] ' . $level . ' ' . $message;
        if (!empty($context)) {
            $parts = [];
            foreach ($context as $k => $v) {
                $value = (is_scalar($v) || $v === null) ? (string) $v : json_encode($v);
                $parts[] = $k . '=' . $value;
            }
            $line .= ' [' . implode(' ', $parts) . ']';
        }
        return $line;
    }

    /**
     * Core logging method: applies the threshold filter, formats the line and
     * sends it to the output channel — error_log() by default, or the
     * in-memory capture buffer used by the unit tests. Unknown levels degrade
     * to WARN so a typo is never silently lost.
     *
     * @param string $level Level label (case-insensitive)
     * @param string $message Log message
     * @param array  $context Optional key => value pairs
     */
    public static function log($level, $message, $context = []) {
        $level = strtolower((string) $level);
        if (!isset(self::$severity[$level])) {
            $level = 'warn';
        }
        if (!self::isEnabled($level)) {
            return;
        }
        $line = self::formatLine(strtoupper($level), $message, $context);
        if (self::$captured !== null) {
            self::$captured[] = $line;
            return;
        }
        error_log($line);
    }

    /**
     * Convenience: logs a DEBUG message (only when the threshold is `debug`).
     *
     * @param string $message Log message
     * @param array  $context Optional key => value pairs
     */
    public static function debug($message, $context = []) {
        self::log(self::DEBUG, $message, $context);
    }

    /**
     * Convenience: logs an INFO message (threshold `info` or `debug`).
     *
     * @param string $message Log message
     * @param array  $context Optional key => value pairs
     */
    public static function info($message, $context = []) {
        self::log(self::INFO, $message, $context);
    }

    /**
     * Convenience: logs a WARN message (always emitted).
     *
     * @param string $message Log message
     * @param array  $context Optional key => value pairs
     */
    public static function warn($message, $context = []) {
        self::log(self::WARN, $message, $context);
    }

    /**
     * Convenience: logs an ERROR message (always emitted).
     *
     * @param string $message Log message
     * @param array  $context Optional key => value pairs
     */
    public static function error($message, $context = []) {
        self::log(self::ERROR, $message, $context);
    }

    /**
     * Convenience: logs a FATAL message (always emitted).
     *
     * @param string $message Log message
     * @param array  $context Optional key => value pairs
     */
    public static function fatal($message, $context = []) {
        self::log(self::FATAL, $message, $context);
    }

    /**
     * Starts capturing log lines into an in-memory buffer (test helper).
     * While capturing is active nothing is written to the real error log,
     * so tests can assert on the exact output without global side effects
     * (no ini_set, no temporary error_log file).
     */
    public static function capture() {
        self::$captured = [];
    }

    /**
     * Stops capturing and returns the lines captured since capture().
     * Safe to call when capturing was never started (returns []).
     *
     * @return string[] Captured log lines
     */
    public static function release() {
        $lines = self::$captured !== null ? self::$captured : [];
        self::$captured = null;
        return $lines;
    }
}

if (!function_exists('publisharticle_log')) {
    /**
     * Procedural convenience wrapper around PublishArticleLogger::log().
     *
     * Recommended call-site entry point for the plugin code. It is guarded
     * with class_exists() so the caller never breaks if the logger class is
     * unavailable for any reason (backwards-compatible by design): the call
     * becomes a silent no-op instead of a fatal "class not found" error.
     *
     * Allowed $level values: 'debug'|'info'|'warn'|'error'|'fatal'
     * (case-insensitive). The threshold comes from the `log_level` plugin
     * option (default 'info'); WARN/ERROR/FATAL are always written.
     *
     * @param string $level Level label (case-insensitive)
     * @param string $message Log message (convention: starts with __METHOD__)
     * @param array  $context Optional key => value pairs appended as k=v
     */
    function publisharticle_log($level, $message, $context = []) {
        if (class_exists('PublishArticleLogger')) {
            PublishArticleLogger::log($level, $message, $context);
        }
    }
}