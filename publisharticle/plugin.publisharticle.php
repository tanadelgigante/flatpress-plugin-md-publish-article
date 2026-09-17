<?php
/**
 * Plugin Name: Publish Markdown Article Plugin
 * Plugin URI: https://www.flatpress.org
 * Description: Allows publishing articles from Markdown files with properties, with configurable import folder and import frequency.
 * Version: 0.9
 * Author: Il Gigante
 */

require_once 'ArticleProcessor.php';
require_once 'ArticleImporter.php';

/**
 * The classic FlatPress plugin API uses procedural hooks with add_action().
 * We implement both the import logic and the admin handling here.
 */

/**
 * Load plugin options (merging with defaults).
 *
 * @return array
 */
function publisharticle_get_options() {
	$defaults = array(
		'import_folder'    => '',
		'import_frequency' => 'every_page_load', // 'manual' | 'every_page_load' | cron expression
		'default_category' => '',
		'default_status'   => 'publish',
		'done_subdir'      => 'done',
		'failed_subdir'    => 'failed',
	);

	$options = function_exists('plugin_getoptions') ? plugin_getoptions('publisharticle') : array();

	if (!is_array($options)) {
		$options = array();
	}

	return array_merge($defaults, $options);
}

/**
 * Determines whether the import should run now, based on the
 * configured frequency:
 *
 * - 'manual'           => never on page load
 * - 'every_page_load'  => always
 * - cron expression    => only when the current time matches the
 *                          expression, and at most once per minute
 *                          (tracked via the last_import_run option)
 *
 * @param array $options Plugin options
 * @return bool
 */
function publisharticle_should_import($options) {
	$frequency = isset($options['import_frequency']) ? $options['import_frequency'] : 'every_page_load';

	if ($frequency === 'manual') {
		return false;
	}

	if ($frequency === 'every_page_load') {
		return true;
	}

	// Treat the value as a cron expression (5 fields: min hour dom mon dow)
	if (!publisharticle_cron_matches($frequency, time())) {
		return false;
	}

	// Within a matching minute, run only once
	$last_run = isset($options['last_import_run']) ? (int) $options['last_import_run'] : 0;
	$now = time();

	return floor($now / 60) > floor($last_run / 60);
}

/**
 * Checks whether a 5-field cron expression matches the given timestamp.
 *
 * Supported syntax per field (minute hour day-of-month month day-of-week):
 *   *        any value
 *   5        exact value
 *   1,3,5    list of values
 *   1-5      range
 *   * /15    step over the whole range
 *   1-30/5   step over a range
 * Names are accepted for month (jan-dec) and day-of-week (sun-sat).
 *
 * @param string $expr Cron expression
 * @param int|null $timestamp Timestamp to test (default: now)
 * @return bool
 */
function publisharticle_cron_matches($expr, $timestamp = null) {
	if (!is_string($expr) || trim($expr) === '') {
		return false;
	}

	$fields = preg_split('/\s+/', trim($expr));
	if (count($fields) !== 5) {
		return false;
	}

	if ($timestamp === null) {
		$timestamp = time();
	}

	// Current time components (day-of-week: 0 = Sunday, matching cron)
	$now = array(
		(int) date('i', $timestamp), // minute 0-59
		(int) date('G', $timestamp), // hour 0-23
		(int) date('j', $timestamp), // day of month 1-31
		(int) date('n', $timestamp), // month 1-12
		(int) date('w', $timestamp), // day of week 0-6 (0 = Sunday)
	);

	$names = array(
		3 => array('jan' => 1, 'feb' => 2, 'mar' => 3, 'apr' => 4, 'may' => 5, 'jun' => 6,
			'jul' => 7, 'aug' => 8, 'sep' => 9, 'oct' => 10, 'nov' => 11, 'dec' => 12),
		4 => array('sun' => 0, 'mon' => 1, 'tue' => 2, 'wed' => 3, 'thu' => 4, 'fri' => 5, 'sat' => 6),
	);

	foreach ($fields as $index => $field) {
		if (!publisharticle_cron_field_matches($field, $now[$index], $index, $names)) {
			return false;
		}
	}

	return true;
}

/**
 * Checks a single cron field against a value.
 *
 * @param string $field Cron field (e.g. "* /15", "1-5", "mon,fri")
 * @param int $value Current value
 * @param int $index Field index (0=minute ... 4=day-of-week)
 * @param array $names Name maps for month/day-of-week
 * @return bool
 */
function publisharticle_cron_field_matches($field, $value, $index, $names) {
	$ranges = array(
		array(0, 59),
		array(0, 23),
		array(1, 31),
		array(1, 12),
		array(0, 6),
	);

	// Special case: standard cron treats 7 as Sunday too
	if ($index === 4 && $value === 7) {
		$value = 0;
	}

	$min = $ranges[$index][0];
	$max = $ranges[$index][1];

	foreach (explode(',', $field) as $part) {
		$part = trim($part);
		if ($part === '') {
			continue;
		}

		// Split off an optional step: "<base>/<step>"
		$step = 1;
		$base = $part;
		if (strpos($part, '/') !== false) {
			list($base, $stepStr) = explode('/', $part, 2);
			$step = (int) $stepStr;
			if ($step < 1) {
				continue;
			}
		}

		// Resolve names (month / day-of-week)
		$resolve = function ($token) use ($names, $index) {
			$token = strtolower(trim($token));
			if ($index === 4 && $token === '7') {
				return 0; // standard cron treats 7 as Sunday
			}
			if (isset($names[$index][$token])) {
				return $names[$index][$token];
			}
			return is_numeric($token) ? (int) $token : null;
		};

		// Determine the range covered by this part
		if ($base === '*' || $base === '?') {
			$from = $min;
			$to = $max;
		} elseif (strpos($base, '-') !== false) {
			list($fromTok, $toTok) = explode('-', $base, 2);
			$from = $resolve($fromTok);
			$to = $resolve($toTok);
			if ($from === null || $to === null) {
				continue;
			}
		} else {
			$single = $resolve($base);
			if ($single === null) {
				continue;
			}
			// Exact value with a step means "from value upward"
			$from = $single;
			$to = ($step > 1) ? $max : $single;
		}

		// Normalise descending ranges (e.g. day-of-week sun-sat)
		if ($to < $from) {
			// wrap-around range: test both segments
			if (publisharticle_cron_in_range($value, $from, $max, $step) ||
				publisharticle_cron_in_range($value, $min, $to, $step)) {
				return true;
			}
			continue;
		}

		if (publisharticle_cron_in_range($value, $from, $to, $step)) {
			return true;
		}
	}

	return false;
}

/**
 * Whether $value lies in [$from, $to] honouring a step, aligned to $from.
 *
 * @param int $value
 * @param int $from
 * @param int $to
 * @param int $step
 * @return bool
 */
function publisharticle_cron_in_range($value, $from, $to, $step) {
	if ($value < $from || $value > $to) {
		return false;
	}
	if ($step <= 1) {
		return true;
	}
	return (($value - $from) % $step) === 0;
}

/**
 * Validates a 5-field cron expression (minute hour day-of-month month day-of-week).
 *
 * @param string $expr Cron expression to validate
 * @return bool True if valid, false otherwise
 */
function publisharticle_valid_cron($expr) {
	if (!is_string($expr) || trim($expr) === '') {
		return false;
	}

	$fields = preg_split('/\s+/', trim($expr));
	if (count($fields) !== 5) {
		return false;
	}

	$ranges = array(
		array(0, 59),
		array(0, 23),
		array(1, 31),
		array(1, 12),
		array(0, 7),
	);

	$names = array(
		3 => array('jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'),
		4 => array('sun', 'mon', 'tue', 'wed', 'thu', 'fri', 'sat'),
	);

	foreach ($fields as $index => $field) {
		$min = $ranges[$index][0];
		$max = $ranges[$index][1];

		foreach (explode(',', $field) as $part) {
			$part = trim($part);
			if ($part === '') {
				return false;
			}

			if (strpos($part, '/') !== false) {
				list($base, $step) = explode('/', $part, 2);
				if (!ctype_digit($step) || (int) $step < 1) {
					return false;
				}
			} else {
				$base = $part;
			}

			if ($base === '*' || $base === '?') {
				continue;
			}

			if (strpos($base, '-') !== false) {
				list($from, $to) = explode('-', $base, 2);
				$tokens = array($from, $to);
			} else {
				$tokens = array($base);
			}

			foreach ($tokens as $token) {
				$token = strtolower(trim($token));
				if (isset($names[$index]) && in_array($token, $names[$index])) {
					continue;
				}
				if (!ctype_digit($token)) {
					return false;
				}
				$num = (int) $token;
				if ($num < $min || $num > $max) {
					return false;
				}
			}
		}
	}

	return true;
}

/**
 * Hook: init - imports articles from the import folder when due.
 */
function publisharticle_process_import() {
	$options = publisharticle_get_options();

	if (!publisharticle_should_import($options)) {
		return;
	}

	$importFolder = !empty($options['import_folder']) ? $options['import_folder'] : null;
	$importer = new ArticleImporter(null, $importFolder, $options);
	$importer->importAll();

	// Remember the last run so cron expressions only fire once per slot
	if (function_exists('plugin_addoption')) {
		plugin_addoption('publisharticle', 'last_import_run', time());
		plugin_saveoptions('publisharticle');
	}
}

/**
 * Hook: init - promotes scheduled (future-dated) articles whose time has come.
 */
function publisharticle_process_scheduled() {
	$processor = new ArticleProcessor();
	$processor->processScheduled();
}

/**
 * Registers the admin panel when the AdminPanelAction class is available.
 */
if (class_exists('AdminPanelAction')) {
	// Register admin panels
	require_once plugin_getdir('publisharticle') . 'panels/admin.plugin.panel.publisharticle.php';
	require_once plugin_getdir('publisharticle') . 'panels/admin.plugin.panel.pubartcfg.php';
}

// Wire the hooks
add_action('init', 'publisharticle_process_scheduled');
add_action('init', 'publisharticle_process_import');