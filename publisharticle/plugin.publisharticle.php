<?php
/**
 * Plugin Name: Publish Article Plugin
 * Plugin URI: https://www.flatpress.org
 * Description: Allows publishing articles from Markdown files with properties, with configurable import folder and import frequency.
 * Version: 0.2
 * Author: Your Name
 */

require_once 'ArticleProcessor.php';
require_once 'ArticleImporter.php';

/**
 * Load plugin options (merging with defaults).
 *
 * @return array
 */
function publisharticle_get_options() {
	$defaults = array(
		'import_folder'     => '',
		'import_frequency'  => 'every_page_load',
		'default_category'  => '',
		'default_status'    => 'publish',
		'done_subdir'       => 'done',
		'failed_subdir'     => 'failed',
	);

	$options = function_exists('plugin_getoptions')
		? plugin_getoptions('publisharticle')
		: array();

	if (!is_array($options)) {
		$options = array();
	}

	return array_merge($defaults, $options);
}

/**
 * Determines whether the import should run now.
 *
 * Supported values:
 *
 * - manual
 * - every_page_load
 * - 5-field cron expression
 *
 * @param array $options
 * @return bool
 */
function publisharticle_should_import($options) {
	$frequency = isset($options['import_frequency'])
		? $options['import_frequency']
		: 'every_page_load';

	if ($frequency === 'manual') {
		return false;
	}

	if ($frequency === 'every_page_load') {
		return true;
	}

	// Treat the value as a cron expression.
	if (!publisharticle_cron_matches($frequency, time())) {
		return false;
	}

	// Within a matching minute, run only once.
	$last_run = isset($options['last_import_run'])
		? (int) $options['last_import_run']
		: 0;

	$now = time();

	return floor($now / 60) > floor($last_run / 60);
}

/**
 * Checks whether a 5-field cron expression matches a timestamp.
 *
 * Supported syntax:
 *
 *   *        any value
 *   5        exact value
 *   1,3,5    list of values
 *   1-5      range
 *   */15     step
 *   1-30/5   step over range
 *
 * Names are accepted for:
 *
 *   month: jan-dec
 *   day:   sun-sat
 *
 * @param string $expr
 * @param int|null $timestamp
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

	$now = array(
		(int) date('i', $timestamp), // minute
		(int) date('G', $timestamp), // hour
		(int) date('j', $timestamp), // day of month
		(int) date('n', $timestamp), // month
		(int) date('w', $timestamp), // day of week
	);

	$names = array(
		3 => array(
			'jan' => 1,
			'feb' => 2,
			'mar' => 3,
			'apr' => 4,
			'may' => 5,
			'jun' => 6,
			'jul' => 7,
			'aug' => 8,
			'sep' => 9,
			'oct' => 10,
			'nov' => 11,
			'dec' => 12,
		),
		4 => array(
			'sun' => 0,
			'mon' => 1,
			'tue' => 2,
			'wed' => 3,
			'thu' => 4,
			'fri' => 5,
			'sat' => 6,
		),
	);

	foreach ($fields as $index => $field) {
		if (!publisharticle_cron_field_matches(
			$field,
			$now[$index],
			$index,
			$names
		)) {
			return false;
		}
	}

	return true;
}

/**
 * Checks a single cron field against a value.
 *
 * @param string $field
 * @param int $value
 * @param int $index
 * @param array $names
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

	$min = $ranges[$index][0];
	$max = $ranges[$index][1];

	foreach (explode(',', $field) as $part) {
		$part = trim($part);

		if ($part === '') {
			continue;
		}

		$step = 1;
		$base = $part;

		// Split optional step.
		if (strpos($part, '/') !== false) {
			list($base, $stepStr) = explode('/', $part, 2);

			$step = (int) $stepStr;

			if ($step < 1) {
				continue;
			}
		}

		// Resolve numeric or named values.
		$resolve = function ($token) use ($names, $index) {
			$token = strtolower(trim($token));

			if ($index === 4 && $token === '7') {
				return 0;
			}

			if (isset($names[$index][$token])) {
				return $names[$index][$token];
			}

			return is_numeric($token)
				? (int) $token
				: null;
		};

		// Determine range.
		if ($base === '*' || $base === '?') {
			$from = $min;
			$to   = $max;

		} elseif (strpos($base, '-') !== false) {
			list($fromTok, $toTok) = explode('-', $base, 2);

			$from = $resolve($fromTok);
			$to   = $resolve($toTok);

			if ($from === null || $to === null) {
				continue;
			}

		} else {
			$single = $resolve($base);

			if ($single === null) {
				continue;
			}

			$from = $single;
			$to   = ($step > 1) ? $max : $single;
		}

		// Handle descending/wrapped ranges.
		if ($to < $from) {
			if (
				publisharticle_cron_in_range(
					$value,
					$from,
					$max,
					$step
				) ||
				publisharticle_cron_in_range(
					$value,
					$min,
					$to,
					$step
				)
			) {
				return true;
			}

			continue;
		}

		if (publisharticle_cron_in_range(
			$value,
			$from,
			$to,
			$step
		)) {
			return true;
		}
	}

	return false;
}

/**
 * Whether a value lies in [$from, $to] honouring a step.
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
 * Validates a 5-field cron expression.
 *
 * @param string $expr
 * @return bool
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
		3 => array(
			'jan',
			'feb',
			'mar',
			'apr',
			'may',
			'jun',
			'jul',
			'aug',
			'sep',
			'oct',
			'nov',
			'dec',
		),
		4 => array(
			'sun',
			'mon',
			'tue',
			'wed',
			'thu',
			'fri',
			'sat',
		),
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

				if (
					isset($names[$index]) &&
					in_array($token, $names[$index])
				) {
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
 * Hook: init.
 *
 * Imports articles from the configured import folder when due.
 */
function publisharticle_process_import() {
	$options = publisharticle_get_options();

	if (!publisharticle_should_import($options)) {
		return;
	}

	$importer = new ArticleImporter(
		null,
		$options['import_folder'],
		$options
	);

	$importer->importAll();

	// Remember last run.
	if (function_exists('plugin_addoption')) {
		plugin_addoption(
			'publisharticle',
			'last_import_run',
			time()
		);

		plugin_saveoptions('publisharticle');
	}
}

/**
 * Hook: init.
 *
 * Promotes scheduled/future-dated articles whose time has come.
 */
function publisharticle_process_scheduled() {
	$processor = new ArticleProcessor();
	$processor->processScheduled();
}

/**
 * Register admin panels.
 *
 * IMPORTANT:
 * The classes defined by the panel files must use the naming scheme
 * expected by FlatPress AdminPanel::get_action():
 *
 *   AdminPanel_plugin_pubartcfg
 *   AdminPanel_content_publisharticle
 */
if (class_exists('AdminPanelAction')) {

	require_once plugin_getdir('publisharticle')
		. 'panels/admin.plugin.panel.publisharticle.php';

	require_once plugin_getdir('publisharticle')
		. 'panels/admin.plugin.panel.pubartcfg.php';
}

/**
 * Wire hooks.
 */
add_action('init', 'publisharticle_process_scheduled');
add_action('init', 'publisharticle_process_import');