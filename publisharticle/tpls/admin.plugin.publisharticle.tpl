<h2>{$plang.head}</h2>
<p>{$plang.description}</p>

{include file="shared:errorlist.tpl"}

{html_form class="option-set"}
<dl class="option-list">

	<!-- Import Folder -->
	<dt><label for="import_folder">{$plang.import_folder}</label></dt>
	<dd>
		<input type="text" name="import_folder" id="import_folder" value="{$import_folder|escape}" size="60">
		<p class="form-help">{$plang.import_folder_help}</p>
		{if isset($import_folder_status)}
			<div class="import-status {if $import_folder_status.protected}protected{else}unprotected{/if}">
				<strong>{$import_folder_status.server|upper}:</strong> {$import_folder_status.message}
				{if !$import_folder_status.protected}
					<br>
					{if $import_folder_status.server == 'caddy'}
						<p>{$plang.caddy_instructions}</p>
					{elseif $import_folder_status.server == 'nginx'}
						<p>{$plang.nginx_instructions}</p>
					{elseif $import_folder_status.server == 'apache' || $import_folder_status.server == 'litespeed'}
						<p>{$plang.apache_instructions}</p>
					{/if}
				{/if}
			</div>
			<p>{$plang.pending_files}: {$pending_count}</p>
		{/if}
	</dd>

	<!-- Import Frequency -->
	<dt><label for="import_frequency">{$plang.import_frequency}</label></dt>
	<dd>
		<select name="import_frequency" id="import_frequency">
			<option value="manual"{if $is_manual} selected{/if}>{$plang.manual}</option>
			<option value="every_page_load"{if $is_every_page_load} selected{/if}>{$plang.every_page_load}</option>
			<option value="custom"{if $is_cron} selected{/if}>{$plang.cron_custom}</option>
		</select>
		<p class="form-help">{$plang.import_frequency_help}</p>

		<!-- Custom cron schedule (shown when custom is selected) -->
		<div id="cron_schedule_field" style="display: {if $is_cron}block{else}none{/if}; margin-top: 10px;">
			<label for="cron_schedule"><strong>{$plang.cron_schedule}</strong></label><br>
			<input type="text" name="cron_schedule" id="cron_schedule" value="{$cron_schedule|escape}" size="30">
			<p class="form-help">{$plang.cron_schedule_help}</p>
			<div class="cron-examples">
				<strong>Examples:</strong>
				<ul>
					<li><code>0 * * * *</code> — {$plang.cron_hourly_example}</li>
					<li><code>0 2 * * *</code> — {$plang.cron_daily_example}</li>
					<li><code>0 */6 * * *</code> — {$plang.cron_every6h_example}</li>
					<li><code>*/15 * * * *</code> — {$plang.cron_every15m_example}</li>
				</ul>
			</div>
		</div>
	</dd>

	<!-- Default Category -->
	<dt><label for="default_category">{$plang.default_category}</label></dt>
	<dd>
		<input type="text" name="default_category" id="default_category" value="{$default_category|escape}" size="30">
		<p class="form-help">{$plang.default_category_help}</p>
	</dd>

	<!-- Default Status -->
	<dt><label for="default_status">{$plang.default_status}</label></dt>
	<dd>
		<select name="default_status" id="default_status">
			<option value="publish"{if $default_status == 'publish'} selected{/if}>{$plang.published}</option>
			<option value="draft"{if $default_status == 'draft'} selected{/if}>{$plang.draft}</option>
		</select>
		<p class="form-help">{$plang.default_status_help}</p>
	</dd>

	<!-- Done Subdirectory -->
	<dt><label for="done_subdir">{$plang.done_subdir}</label></dt>
	<dd>
		<input type="text" name="done_subdir" id="done_subdir" value="{$done_subdir|escape}" size="30">
		<p class="form-help">{$plang.done_subdir_help}</p>
	</dd>

	<!-- Failed Subdirectory -->
	<dt><label for="failed_subdir">{$plang.failed_subdir}</label></dt>
	<dd>
		<input type="text" name="failed_subdir" id="failed_subdir" value="{$failed_subdir|escape}" size="30">
		<p class="form-help">{$plang.failed_subdir_help}</p>
	</dd>

</dl>

<p class="buttonbar">
	<input type="submit" name="publisharticle-submit" value="{$plang.submit}">
</p>
{/html_form}

<script type="text/javascript">
document.addEventListener('DOMContentLoaded', function() {
	var freqSelect = document.getElementById('import_frequency');
	var cronField = document.getElementById('cron_schedule_field');
	freqSelect.addEventListener('change', function() {
		cronField.style.display = (this.value === 'custom') ? 'block' : 'none';
	});
});
</script>

<style>
.import-status {
	padding: 10px;
	margin: 10px 0;
	border-radius: 4px;
	border: 1px solid #ccc;
}
.import-status.protected {
	background: #e8f5e9;
	border-color: #4caf50;
}
.import-status.unprotected {
	background: #ffebee;
	border-color: #f44336;
}
.cron-examples ul {
	margin: 5px 0;
	padding-left: 20px;
}
.cron-examples li {
	margin: 3px 0;
	font-size: 0.9em;
}
.form-help {
	color: #666;
	font-size: 0.9em;
	margin: 2px 0 10px 0;
}
</style>