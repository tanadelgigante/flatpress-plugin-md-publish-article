# Publish Article Plugin for FlatPress

This plugin allows you to publish articles in FlatPress by uploading Markdown files.

## Installation

Copy the `publisharticle/` folder into FlatPress's `fp-plugins/` directory:

```text
fp-plugins/
  publisharticle/
    plugin.publisharticle.php     ← the plugin entry point (name must match the folder ID)
    lang/lang.en-us.php
    panels/admin.plugin.panel.publisharticle.php
    tpls/admin.plugin.publisharticle.tpl
    ArticleProcessor.php
    ArticleImporter.php
    ...
```

The main file must be named `plugin.<folder>.php` — here `plugin.publisharticle.php` — otherwise FlatPress will not load the plugin. After copying, enable **Publish Article** in the admin area (Manage → Plugins).

## Features
- Parse Markdown files for content.
- Support YAML-like frontmatter for properties:
  - `version` — version of the entry format (default `fp-1.4.1`)
  - `subject` (alias `title`) — entry title
  - `author` — entry author (default `admin`)
  - `date` — entry date (used for the entry ID)
  - `categories` — comma-separated categories: can be **names** (`News, Tech`) or numeric IDs (`5,15`); names are translated to IDs using FlatPress `categories_encoded.dat` (or `categories.txt`)
  - `publish_date` / `scheduled` / `scheduled_date` — future publish date for **scheduled/delayed publishing**
- Convert Markdown content to FlatPress BBCode.
- Handle scheduled/future publishing (articles are stored in a pending directory and auto-published on first page load after the scheduled time).
- **Image upload**: attach images to the article — they are validated, stored in `fp-content/images/`, and the relative paths (`images/...`) are returned so you can reference them in the content.
- **Import folder**: publish articles by placing Markdown files in a folder (e.g. via FTP/SFTP) — they are imported automatically on page load.

## Frontmatter example

```markdown
---
subject: My Article
author: ilgigante77
date: 2026-09-13
categories: News, Tech
publish_date: 2026-09-20 10:00:00
---
Content of the article in **Markdown**.

![A nice picture](photo.jpg width=500)
```

## Import folder

Deposit a Markdown file (`.md`, `.markdown`, `.mdown`, `.txt`) in the import folder (default: `fp-content/content/import-in/`):

- The plugin scans the folder on page load according to the configured **import frequency**.
- Each file is processed: frontmatter parsed, content converted to BBCode, images imported, article published.
- **Sibling images** are imported automatically:
  - files with the same base name (`article.jpg`, `article.png`, ...)
  - a sibling folder `images/` or `<basename>/`
- After processing the file is moved to `import-in/done/`; on failure it is moved to `import-in/failed/` and left there for inspection.
- Scheduled articles (`publish_date` in the future) keep working the same way.

The import folder is shown in the admin page together with the pending files.

## Configuration 

The plugin exposes a settings panel in the FlatPress admin area (**Manage → Plugins → Publish Article**):

| Setting | Default | Description |
| --- | --- | --- |
| `import_folder` | *(empty → `fp-content/content/import-in/`)* | Path to the folder where Markdown files are imported from. |
| `import_frequency` | `every_page_load` | How often the import runs: `manual` (never on page load), `every_page_load`, or a custom **cron expression** (e.g. `0 * * * *` for hourly, `*/15 * * * *` for every 15 minutes). Cron imports run at most once per matching time slot. |
| `default_category` | *(empty)* | Category ID applied to imported articles that have no `categories` in frontmatter. |
| `default_status` | `publish` | Status applied to imported articles that have no `status` in frontmatter (`publish` or `draft`). |
| `done_subdir` | `done` | Subfolder of the import folder where successfully imported files are moved. |
| `failed_subdir` | `failed` | Subfolder of the import folder where failed imports are moved for inspection. |

When a cron expression is selected, the custom schedule field appears; the import then runs at most once per matching time slot (tracked via the `last_import_run` option).

## Security: web access to the import folder

The import folder contains **unpublished** Markdown files. FlatPress does **not** protect `fp-content/` by default, so the plugin:

- deploys an `.htaccess` inside the import folder (`Options -Indexes` + `Require all denied` for Apache 2.2/2.4) — created automatically when the folder is created
- for **Caddy 2** users: copies a `caddy_import_protect.conf` snippet into the import folder and a `.caddy` marker so the admin can see it was delivered — **you must manually include it in your `Caddyfile`**
- shows a **warning in the admin page** if the protection is missing, with server-specific instructions

### Apache / LiteSpeed

The `.htaccess` is read automatically — no extra config needed.

### Caddy 2

Caddy does **not** read `.htaccess`. The plugin copies `caddy_import_protect.conf` into the import folder. Add this to your Caddyfile:

```
# --- Variante 1: protezione della root import-in/ ---
@import_deny {
    path /fp-content/content/import-in/*
}
handle @import_deny {
    respond "Forbidden" 403
}
```

The plugin ships three variants in `caddy_import_protect.conf`: root only, all subfolders (including `done/`/`failed/`), or just Markdown files. Choose the one that fits your needs.

### Nginx

Add to your server block:

```nginx
location ~ ^/fp-content/content/import-in/ {
    deny all;
    return 403;
}
```

### Notes

- The `.htaccess` / `.caddy` marker is only written once; if you customize it, the plugin will not overwrite it.
- The admin page shows the current protection status and detected server software.

## Image upload

In the admin form you can select one or more images (field `images[]`). They are:

- validated (extension: jpg, jpeg, png, gif, webp, svg, bmp — max size ~5 MB)
- moved to `fp-content/images/` with a unique name `<entryID>_<timestamp>_<random>.<ext>`
- listed in the result so you can reference them with:
  ```markdown
  ![My photo](images/<entryID>_20260913-101530_1234.jpg width=500)
  ```

Paths in Markdown image syntax:

- **Plain filenames** (`photo.jpg`) → normalized to `images/photo.jpg` in the BBCode output.
- **Local FlatPress paths** (`images/...`, `attachs/...`) → left untouched.
- **Already hosted elsewhere** (absolute URLs `https://...`, protocol-relative `//...`, any scheme `ftp://...`, `data:`, or absolute paths `/uploads/...`) → **kept exactly as written** in the Markdown, pointed to their original address. No conversion happens, and `scanMarkdownImages()` excludes them from upload candidates.

- Without `publish_date` → article is published immediately.
- With `publish_date` in the future → article is stored as pending and published automatically when the time arrives.
- `date` determines the entry ID (`entryYYMMDD-HHMMSS`).
- `categories` accepts **names** or **IDs**. Names are resolved against the FlatPress category map; unresolved names are kept as-is for a later translation stage.

---

## ⚠️ Disclaimer

This plugin is **vibe-coded** — it was designed, written, reviewed and refined in a series of conversational AI-assisted coding sessions. While every effort has been made to make it functional and secure, it has **not** been produced through a traditional software engineering lifecycle with formal testing, audits, or production hardening.

**THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT.**

In no event shall the author(s) be held liable for any claim, damages or other liability, whether in an action of contract, tort or otherwise, arising from, out of or in connection with the software or the use or other dealings in the software.

Use at your own risk. Always back up your `fp-content/` directory before using this plugin on a production site.

## License

This plugin is licensed under the **GNU General Public License v3.0 (GPL-3.0)**.
See the `LICENSE` file for details.
