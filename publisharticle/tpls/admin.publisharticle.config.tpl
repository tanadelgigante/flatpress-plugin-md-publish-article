<h2>{$plang.config_head}</h2>
<p>{$plang.config_description}</p>

{include file="shared:errorlist.tpl"}

{if isset($success)}
	<div class="notice{if $success < 0} error{/if}">
		{if $success > 0}{$plang.config_saved}{else}{$plang.config_error}{/if}
	</div>
{/if}

{html_form class="option-set"}
<dl class="option-list">

	<!-- Import Folder -->
	<dt><label for="import_folder">{$plang.import_folder}</label></dt>
	<dd>
		<input type="text" name="import_folder" id="import_folder" value="{$import_folder|escape}" size="60">
		<p class="form-help">{$plang.import_folder_help}</p>
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

		<!-- Custom cron schedule -->
		<div id="cron_schedule_field" style="display: {if $is_cron}block{else}none{/if}; margin-top: 10px;">
			<label for="cron_schedule"><strong>{$plang.cron_schedule}</strong></label><br>
			<input type="text" name="cron_schedule" id="cron_schedule" value="{$cron_schedule|escape}" size="30">
			<p class="form-help">{$plang.cron_schedule_help}</p>
		</div>
	</dd>

	<!-- Default Category -->
	<dt><label for="default_category">{$plang.default_category}</label></dt>
	<dd>
		<input type="text" name="default_category" id="default_category" value="{$default_category|escape}" size="10">
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

	<!-- Done Subdir -->
	<dt><label for="done_subdir">{$plang.done_subdir}</label></dt>
	<dd>
		<input type="text" name="done_subdir" id="done_subdir" value="{$done_subdir|escape}" size="20">
		<p class="form-help">{$plang.done_subdir_help}</p>
	</dd>

	<!-- Failed Subdir -->
	<dt><label for="failed_subdir">{$plang.failed_subdir}</label></dt>
	<dd>
		<input type="text" name="failed_subdir" id="failed_subdir" value="{$failed_subdir|escape}" size="20">
		<p class="form-help">{$plang.failed_subdir_help}</p>
	</dd>

</dl>

<p>
	<input type="submit" name="publisharticle-config-submit" value="{$plang.config_save}" class="button">
</p>

{/html_form}

<script>
document.getElementById('import_frequency').addEventListener('change', function() {
	document.getElementById('cron_schedule_field').style.display =
		(this.value === 'custom') ? 'block' : 'none';
});
</script>
