<?php
// Italian language file for the Publish Article plugin

// Menu labels
$lang ['admin'] ['plugin'] ['submenu'] ['publisharticle'] = 'Publish Article';
$lang ['admin'] ['plugin'] ['submenu'] ['pubartcfg'] = 'Publish Article – Impostazioni';

// Shared keys (both panels use $langres = 'plugin:publisharticle')
$lang ['admin'] ['plugin'] ['publisharticle'] = array(

	// ── Pannello Configurazione (menu Plugin) ───────────────────
	'config_head'        => 'Publish Article – Impostazioni',
	'config_description' => 'Configura la cartella di import e le modalità di importazione degli articoli in FlatPress.',
	'config_save'        => 'Salva impostazioni',
	'config_saved'       => 'Impostazioni salvate con successo.',
	'config_error'       => 'Espressione cron non valida.',

	'import_folder'      => 'Cartella di import',
	'import_folder_help' => 'Percorso assoluto o relativo della cartella in cui depositare i file Markdown per la pubblicazione automatica. Lascia vuoto per usare quella predefinita (<code>fp-content/content/import-in/</code>).',

	'import_frequency'      => 'Frequenza di import',
	'import_frequency_help' => 'Ogni quanto il plugin controlla la cartella di import per nuovi articoli.',

	'manual'          => 'Manuale (mai al caricamento della pagina)',
	'every_page_load' => 'Ad ogni caricamento della pagina',
	'cron_custom'     => 'Pianificazione cron (personalizzata)',

	'cron_schedule'      => 'Espressione cron',
	'cron_schedule_help' => 'Espressione cron standard a 5 campi (minuto ora giorno-del-mese mese giorno-della-settimana). L\'import viene eseguito al massimo una volta per intervallo temporale corrispondente.',

	'cron_hourly_example'   => 'ogni ora, al minuto 0',
	'cron_daily_example'    => 'ogni giorno, alle 02:00',
	'cron_every6h_example'  => 'ogni 6 ore',
	'cron_every15m_example' => 'ogni 15 minuti',

	'default_category'      => 'ID categoria predefinita',
	'default_category_help' => 'ID categoria applicato agli articoli importati che non specificano una categoria nel frontmatter. Lascia vuoto per usare solo il valore del frontmatter.',

	'default_status'      => 'Stato predefinito',
	'default_status_help' => 'Stato applicato agli articoli importati che non ne specificano uno: Pubblicato o Bozza.',
	'published' => 'Pubblicato',
	'draft'     => 'Bozza',

	'done_subdir'      => 'Sottocartella done',
	'done_subdir_help' => 'Sottocartella della cartella di import in cui vengono spostati i file importati con successo.',

	'failed_subdir'      => 'Sottocartella failed',
	'failed_subdir_help' => 'Sottocartella della cartella di import in cui vengono spostati i file che non sono stati importati per ispezione.',

	// ── Pannello Pubblicazione (menu Articoli) ──────────────────
	'head'               => 'Publish Article',
	'description'        => 'Importa gli articoli Markdown dalla cartella di import configurata in FlatPress.',
	'import_folder_not_set' => 'Nessuna cartella di import configurata. Vai su Impostazioni → Plugin → Publish Article.',

	'pending_files'      => 'File in attesa di importazione',
	'import_now'         => 'Importa ora',
	'import_now_help'    => 'Analizza la cartella di import e pubblica tutti gli articoli in attesa.',
	'no_pending_files'   => 'Nessun file in attesa di importazione.',

	'import_done'        => 'Importazione completata.',
	'import_error'       => 'Importazione fallita.',

	'recent_imports'     => 'Importazioni recenti',
	'log_file'           => 'File',
	'log_status'         => 'Stato',
	'log_time'           => 'Data',

	// Istruzioni protezione cartella
	'caddy_instructions'   => 'Per Caddy 2: includi lo snippet estratto nel tuo Caddyfile (vedi la README del plugin).',
	'nginx_instructions'   => 'Per Nginx: aggiungi <code>location ~ ^/fp-content/content/import-in/ { deny all; }</code> al tuo blocco server.',
	'apache_instructions'  => 'Per Apache/LiteSpeed: crea un file <code>.htaccess</code> con <code>Require all denied</code> nella cartella.',

	'submit' => 'Salva configurazione',
);
