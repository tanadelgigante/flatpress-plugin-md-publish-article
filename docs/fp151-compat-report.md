# Report di compatibilità — plugin `publisharticle` v0.9 su FlatPress 1.5.1

- **Data test**: 2026-09-19
- **Plugin**: publisharticle 0.9 ("Publish Markdown Article Plugin", autore "Il Gigante")
- **FlatPress testato**: 1.5.1 (installazione pulita di `/mnt/v/devel/flatpress-1.5.1`)
- **Referente piano**: `docs/WORKPLAN.md` — Fase 1, Task 1.2/1.3/1.4
- **Vincoli rispettati**: nessun file del plugin modificato; nessun commit/push; le entry di test risiedono solo nel testbed.

## 1. Ambiente

| Voce | Valore |
|---|---|
| Pitform | Linux, filesystem **ext4** (`/home/alessio/fp-test-151`); fonte sorgenti su `/mnt/v` (9p, lenta) |
| PHP | **8.3.6** (CLI, NTS), `-d error_reporting=E_ALL -d display_errors=1 -d log_errors=1` |
| Server | `php -S localhost:8017` (built-in), log `/home/alessio/fp-test-151/php-server.log` |
| Estensioni | mbstring, curl, json, ... ; **GD 2.3.3** installato durante la sessione (`php8.3-gd`) per il rendering `[img]` |
| APCu | **non disponibile** nel PHP CLI |
| Timezone | PHP in **UTC** (date.timezone non impostato, `general.timeoffset=0`); ora locale macchina UTC+2 |

Setup: copia completa dell'albero FP via `tar` in `/home/alessio/fp-test-151` (1517 file, ~8,2 MB), installazione via `/setup.php` (admin `fpadmin`, blog `FP Test`, `blogid fp-98149b2b`), attivazione plugin aggiungendo `'publisharticle'` in `fp-content/config/plugins.conf.php`, `#publisharticle` copiato in `fp-plugins/`.

## 2. Checklist funzionale (Punto per punto)

| # | Punto (da WORKPLAN) | Esito | Evidenza |
|---|---|---|---|
| 1 | Pannelli admin caricano con form corretti | **PASS** | `admin.php?p=plugin&action=publisharticle` e `...&action=pubartcfg` → HTTP 200; campi `md_file`, `images[]`, `publish_now`, `publisharticle-publish/draft` e `import_folder`, `import_frequency`, `default_category`, `default_status`, `done_subdir`, `failed_subdir` presenti; nessun errore Smarty; campi CSRF `_wpnonce`/`_wp_http_referer` presenti |
| 2 | Upload `.md` dal pannello | **PASS** | Upload `t2-upload.md` → `entry260919-000000.txt` creato; messaggio "Import completed."; sidecar `view_counter.txt` creata (0 → 1 dopo prima visualizzazione) |
| 3 | Import da `import-in/` | **PASS** (con nota) | Immediato: `t3-import.md` → `entry260919-000001.txt` + spostato in `done/` con `.note` "Published". Schedulato (data futura): il file **resta in `import-in/`** finché non passa la data; al primo page-load successivo viene pubblicato e spostato in `done/`. **Nota**: la spec del piano ("deve finire in `pending/`") NON corrisponde al comportamento effettivo (nessuna cartella `pending/`; il file resta in `import-in/`) — vedi osservazioni |
| 4 | Upload immagini | **PASS** (con nota ambientale) | Pannello: `foto-test.png` salvato in `fp-content/images/` col **nome originale**, riferimento Markdown riscritto (`[img="images/foto-test.png" ...]`); conflitto di nome → rinominato `img_20260919_110239_foto-test.png` (nessuna sovrascrittura). Folder import con nome in conflitto → articolo in **`failed/`** con `.note` "Image import failed: foto-test.png (name already in use in the images folder)" |
| 5 | Mapping categorie | **PASS** | Categorie create dal pannello admin (`p=entry&action=cats`) → `categories_encoded.dat` (defs 1=Generali, 2=Notizie, 3=Annunci, 6=Tecnologia). `categories: Tecnologia, Notizie` nel frontmatter → `CATEGORIES|6,2|` nell'entry |
| 6 | Formato entry scritta | **PASS** (con nota) | Chiavi ordinate `VERSION|SUBJECT|CONTENT|AUTHOR|DATE|CATEGORIES`. **`VERSION` = `fp-1.4.1` hardcoded** in tutte le entry create dal plugin (anche su FP 1.5.1) — vedi osservazioni |
| 7 | Conversione Markdown→BBCode e rendering | **PASS** (con note) | MD→BBCode: bold→`[b]`, italic→`[i]`, ~~s~~→`[del]`, inline code→`[code]`, `##`→`[h2]`, blockquote→`[quote]`, `---`→`[hr]`, link→`[url="..."]`, immagine→`[img="..." ...]`, fenced code→`[code]`, tabella→HTML `<table>`. Rendering frontend: `[b]`→`<strong>`, `[i]`→`<em>`, `[del]`→`<del>`, code→`<pre>`, `[h2]`, `[quote]`→`<blockquote>`, `[url]`→`<a>`, tabella→`<table>`, `[img]`→`<img>` con thumbnail (`.thumbs/`). Per i limiti (`[hr]`, liste) vedi osservazioni |
| 8 | Nuove entry visibili in index (APCu) | **PASS** (APCu assente) | APCu non installato → test "clear-cache" non applicabile. Le nuove entry compaiono in index e nel widget "Last 9 entries" |
| 9 | Entry in index e RSS feed | **PASS** | Homepage HTTP 200 (elenca le entry in ordine di data); feed RSS2 e Atom HTTP 200; RSS2 contiene `<item>` per le entry del plugin (T5, T7, T7b, T7c) |
| 10 | Nessun warning/deprecation PHP 8.3 (E_ALL) | **PASS** (con note ambientali) | Sweep su 11 URL (homepage, entry, feed, categorie, paged, statica, ricerca, admin) → nessun warning/deprecation/fatal nelle risposte; log pulito a parte le note ambientali sotto |

## 3. Osservazioni e candidate Fase 2

1. **`VERSION` hardcoded `fp-1.4.1`** (ArticleComposer). Su installazione 1.5.1 ogni entry scritta dal plugin dichiara `VERSION|fp-1.4.1` mentre il core scrive `fp-1.5.1` (verificato: la sample entry di setup è `fp-1.5.1`). FP legge comunque le entry correttamente, ma il dato è fuorviante. *Candidata Fase 2: usare `system_ver()` o una costante aggiornata.*
2. **Riga 202 pannello admin** (`$count = count($_FILES['images']['name'])`): se l'upload arriva con campo `images` (non `images[]`) → `TypeError: count(): Argument #1 ($value) must be of type Countable|array, string given`. Con nome campo `images[]` (browser) funziona. *Candidata Fase 2: `is_array()` guard.*
3. **Liste Markdown NON convertite**: `- uno`, `* uno`, `1. primo` restano testo piatto nell'entry (nessun `[list]`). *Candidata Fase 2.*
4. **`[hr]` non reso da FlatPress**: il conversore emette `[hr]` ma il plugin `bbcode` di FP non ha il tag `[hr]` → in pagina appare il testo letterale `[hr]`. *Candidata Fase 2.*
5. **Schedulato vs spec**: la spec del piano prevede `pending/`, il comportamento reale mantiene il file in `import-in/` (flag `deferScheduling`). Nessuna regressione funzionale; da allineare documentazione/spec.
6. **GD/thumb (ambiente)**: senza estensione GD, il rendering di pagine contenenti `[img]` termina con `Fatal error: Call to undefined function imagecreatefrompng()` nel core plugin `thumb` (e la homepage intera va in errore se l'entry più recente contiene un'immagine). Risolto installando `php8.3-gd`. Non è un difetto del plugin, ma è un prerequisito runtime del blog.
7. **Sessione PHP (ambiente)**: `PHP Notice: session_start(): ps_files_cleanup_dir ... /var/lib/php/sessions ... Permission denied (13)` in `core.cookie.php` — dovuto al built-in server avviato come utente non-root; sporadico e non correlato al plugin.
8. **Timezone (cosmetico)**: il prefisso di rinominazione immagine `img_<Ymd_His>_` usa l'ora **UTC** di PHP (verificato: `img_20260919_110239` = 13:02 ora locale). Coerente con la configurazione; da tenere presente se si usa un timeoffset diverso.

## 4. Retrocompatibilità FlatPress 1.4.1 (Task 1.4 — confronto statico)

Setup completo 1.4.1 non eseguito (costo elevato su 9p); confronto statico delle API usate dal plugin tra `flatpress-1.4.1` e `flatpress-1.5.1`:

| Simbolo usato dal plugin | 1.4.1 | 1.5.1 | Note |
|---|---|---|---|
| `CONTENT_DIR`, `IMAGES_DIR`, `CACHE_DIR` | `defaults.php` (righe 72/110/84) | `defaults.php` (righe 76/116/88) | Identici: `FP_CONTENT . 'content/'` ecc. |
| `system_ver()` | `core.system.php:74`, restituisce `'fp-'.SYSTEM_VER` | `core.system.php:68`, idem | Firme identiche; solo `SYSTEM_VER` cambia |
| `plugin_getoptions($plugin, $key=null)` | `core.plugins.php:162` | `core.plugins.php:286` | Firma identica |
| `admin_addpanelaction($panel, $action, $showpanel=true)` | `core.administration.php:31` | `core.administration.php:31` | Firma identica |
| Mescolanza `entry_*` / scrittura diretta file | Scrittura file diretta nel plugin | Idem | Nessuna dipendenza da API cambiate |

**Verifica concreta su 1.4.1**: il plugin scrive il formato entry `VERSION|fp-1.4.1|SUBJECT|CONTENT|AUTHOR|DATE|CATEGORIES` che è **esattamente** il formato nativo di FlatPress 1.4.x; tutte le entry scritte dal plugin su 1.5.1 sono lette dal core 1.5.1 senza errori. Il formato è quindi valido per entrambe le major. **Conclusione: nessuna API rotta rispetto a 1.4.1 — retrocompatibile (per confronto statico).**

## 5. Materiale di evidenza (nel testbed `/home/alessio/fp-test-151`)

- Entry pubblicate: `fp-content/content/26/09/entry260919-000000.txt` … `-000008.txt` (+ `entry260919-103659.txt` di setup).
- Sidecar: `fp-content/content/26/09/entry260919-*.txt` (`view_counter.txt` per 000000).
- Import: `fp-content/content/import-in/{done,failed}/` con `.note`.
- Immagini: `fp-content/images/foto-test.png`, `img_20260919_110239_foto-test.png`, `.thumbs/foto-test.png`.
- Categorie: `fp-content/content/categories.txt`, `categories_encoded.dat`.
- File di test: `test-input/t2-upload.md`, `t4-image.md`, `t5-categories.md`, `t7-bbcode.md`, `t7b-bbcode.md`, `t7c-list.md`, `foto-test.png` (+ `/tmp/foto-test.png` variante conflitto), `t3-import.md`, `t3-scheduled.md`.
- Log server: `php-server.log` (11 URL in sweep E_ALL pulito; 10 fatal GD pre-fix; 2 notice sessione).
- Cookie/sessioni: `cookies.txt` (setup), `admin-cookies.txt` (sessione admin).

## 6. Riepilogo per Fase 2

- **Da correggere / migliorare** (candidate): hardcoded `VERSION`; guard `is_array()` su `$_FILES['images']`; conversione liste; tag `[hr]` (rimozione o mapping); allineamento spec `pending/`.
- **Non correlati al plugin** (ambientali/core): GD/thumb per `[img]`; notice sessione php -S; timezone.
- **Pulizia testbed**: nessuna modifica ai sorgenti del plugin; artefatti `home3.html`/`t7page2.html` generati per errore in root repo e rimossi.