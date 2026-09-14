<?php
// English language file for the Publish Article plugin

// Menu labels
$lang ['admin'] ['plugin'] ['submenu'] ['publisharticle'] = 'Publish Article';
$lang ['admin'] ['content'] ['submenu'] ['publisharticle'] = 'Publish Article';

// Shared keys (both panels use $langres = 'plugin:publisharticle')
$lang ['admin'] ['plugin'] ['publisharticle'] = array(

	// ── Config panel (Plugin menu) ──────────────────────────────
	'config_head'        => 'Publish Article – Settings',
	'config_description' => 'Configure the import folder and how articles are imported into FlatPress.',
	'config_save'        => 'Save settings',
	'config_saved'       => 'Settings saved successfully.',
	'config_error'       => 'Invalid cron expression.',

	'import_folder'      => 'Import folder',
	'import_folder_help' => 'Absolute or relative path to the folder where Markdown files are dropped for automatic publishing. Leave empty to use the default (<code>fp-content/content/import-in/</code>).',

	'import_frequency'      => 'Import frequency',
	'import_frequency_help' => 'How often the plugin checks the import folder for new articles.',

	'manual'          => 'Manual (never on page load)',
	'every_page_load' => 'Every page load',
	'cron_custom'     => 'Cron schedule (custom)',

	'cron_schedule'      => 'Cron expression',
	'cron_schedule_help' => 'A standard 5-field cron expression (minute hour day-of-month month day-of-week). The import runs at most once per matching time slot.',

	'cron_hourly_example'   => 'hourly, at minute 0',
	'cron_daily_example'    => 'daily, at 02:00',
	'cron_every6h_example'  => 'every 6 hours',
	'cron_every15m_example' => 'every 15 minutes',

	'default_category'      => 'Default category ID',
	'default_category_help' => 'Category ID applied to imported articles that do not specify a category in their frontmatter. Leave empty to use the frontmatter value only.',

	'default_status'      => 'Default status',
	'default_status_help' => 'Status applied to imported articles that do not specify one: Published or Draft.',
	'published' => 'Published',
	'draft'     => 'Draft',

	'done_subdir'      => 'Done subdirectory',
	'done_subdir_help' => 'Subfolder of the import folder where successfully imported files are moved.',

	'failed_subdir'      => 'Failed subdirectory',
	'failed_subdir_help' => 'Subfolder of the import folder where files that failed to import are moved for inspection.',

	// ── Publish panel (Articoli/Content menu) ───────────────────
	'head'               => 'Publish Article',
	'description'        => 'Import Markdown articles from the configured import folder into FlatPress.',
	'import_folder_not_set' => 'No import folder configured. Go to Settings → Plugins → Publish Article.',

	'pending_files'      => 'Files waiting to be imported',
	'import_now'         => 'Import now',
	'import_now_help'    => 'Scan the import folder and publish all pending articles.',
	'no_pending_files'   => 'No files are waiting to be imported.',

	'import_done'        => 'Import completed.',
	'import_error'       => 'Import failed.',

	'recent_imports'     => 'Recent imports',
	'log_file'           => 'File',
	'log_status'         => 'Status',
	'log_time'           => 'Time',

	// Protection instructions
	'caddy_instructions'   => 'For Caddy 2: include the extracted snippet in your Caddyfile (see the README of the plugin).',
	'nginx_instructions'   => 'For Nginx: add <code>location ~ ^/fp-content/content/import-in/ { deny all; }</code> to your server block.',
	'apache_instructions'  => 'For Apache/LiteSpeed: create a <code>.htaccess</code> file with <code>Require all denied</code> in the folder.',

	'submit' => 'Save configuration',
);