<h2>{$plang.head}</h2>
<p>{$plang.description}</p>

{include file="shared:errorlist.tpl"}

{if isset($success)}
	<div class="notice{if $success < 0} error{/if}">
		{if $success > 0}{$plang.import_done}{else}{$plang.import_error}{/if}
	</div>
{/if}

<!-- Import Folder Status -->
{if isset($import_folder_path)}
	<div class="import-info">
		<dl>
			<dt>{$plang.import_folder_path}</dt>
			<dd><code>{$import_folder_path|escape}</code></dd>
		</dl>
		{if isset($import_folder_status)}
			<div class="import-status {if $import_folder_status.protected}protected{else}unprotected{/if}">
				<strong>{$import_folder_status.server|upper}:</strong> {$import_folder_status.message}
			</div>
		{/if}
	</div>
{/if}

<!-- Publication form -->
{html_form class="option-set"}
<dl class="option-list">

	<!-- Markdown file -->
	<dt><label for="md_file">{$plang.file_label}</label></dt>
	<dd>
		<select name="md_file" id="md_file">
			<option value="">{$plang.file_none}</option>
			{foreach $md_files as $file}
				<option value="{$file|escape}"{if $selected_file == $file} selected{/if}>{$file|escape}</option>
			{/foreach}
		</select>
		<p class="form-help">{$plang.file_help}</p>
	</dd>

	<!-- Images (multiple) -->
	<dt><label for="images">{$plang.images_label}</label></dt>
	<dd>
		<select name="images[]" id="images" multiple size=6>
			{foreach $image_files as $img}
				<option value="{$img|escape}">{$img|escape}</option>
			{/foreach}
		</select>
		<p class="form-help">{$plang.images_help}</p>
	</dd>

	<!-- Publication date -->
	<dt><label for="pub_date">{$plang.date_label}</label></dt>
	<dd>
		<input type="datetime-local" name="pub_date" id="pub_date" value="{$pub_date|escape}">
		<p class="form-help">{$plang.date_help}</p>
	</dd>

</dl>

<p class="buttonbar">
	<input type="submit" name="publisharticle-publish" value="{$plang.submit}" class="button">
	<input type="submit" name="publisharticle-draft" value="{$plang.submit_draft}" class="button">
</p>

{/html_form}

<!-- Pending files / import all -->
{if isset($pending_count) && $pending_count > 0}
	<form method="post">
		<p>
			<input type="submit" name="publisharticle-import-now" value="{$plang.import_now}" class="button">
		</p>
	</form>
{else}
	<p>{$plang.no_pending_files}</p>
{/if}

<!-- Recent Imports -->
{if isset($recent_imports) && count($recent_imports) > 0}
	<h3>{$plang.recent_imports}</h3>
	<table class="import-log">
		<thead>
			<tr>
				<th>{$plang.log_file}</th>
				<th>{$plang.log_status}</th>
				<th>{$plang.log_time}</th>
			</tr>
		</thead>
		<tbody>
			{foreach $recent_imports as $entry}
				<tr class="{if $entry.success}import-ok{else}import-fail{/if}">
					<td>{$entry.file|escape}</td>
					<td>{if $entry.success}✓ OK{else}✗ {$entry.error|escape}{/if}</td>
					<td>{$entry.time}</td>
				</tr>
			{/foreach}
		</tbody>
	</table>
{/if}