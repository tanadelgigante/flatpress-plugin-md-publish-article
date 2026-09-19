# WORKPLAN — Verifica/adattamento compatibilità FlatPress 1.5.1 + tag `[skip-ci]` nel CI

**Percorso:** `docs/WORKPLAN.md` (il piano di lavoro vive nella cartella documentazione insieme a `flatpress_plugin_docs.md` e agli altri documenti)
**Utente / Orchestratore:** Approvato con decisioni D1/D2/D3 (2026-09-19) + requisiti aggiuntivi (commenti codice, logging, wiki)
**Data stesura:** 2026-09-19
**Autore:** Designer (architetto software)
**Stato:** **IN ESECUZIONE** — Fase 0 completata (fix ci.yml, WORKPLAN committato, baseline PHPUnit registrata); version.ini aggiornato dall'utente a `1.0.0-SNAPSHOT` → release target **v1.0.0**. Prossime: Fase 1 (test compatibilità).

---

## 1. Obiettivo

1. **Verificare e adeguare** il plugin `publisharticle` (v0.9.0, version.ini `1.0.0-SNAPSHOT` aggiornato manualmente dall'utente → release target **v1.0.0**) perché funzioni correttamente su **FlatPress 1.5.1** (rilascio ufficiale installato in `/mnt/v/devel/flatpress-1.5.1`), **senza alterare la funzionalità esistente** né fare refactoring opportunistici. Il plugin oggi è validato su FlatPress 1.4.1.
2. **Mantenere la piena retrocompatibilità** con le versioni FlatPress già supportate (1.4.x e precedenti): ogni adeguamento per 1.5.1 deve essere **additivo e non distruttivo** (guard `function_exists`, fallback, `system_ver()` con fallback per ambienti senza FlatPress). Nessuna funzionalità esistente deve rompersi su 1.4.x.
3. **Introdurre il tag `[skip-ci]`** nel workflow CI (Gitea Actions, `.gitea/workflows/ci.yml`): un push il cui commit contiene il token `[skip-ci]` deve **saltare i job non necessari** (lint, test, ecc.). Caso d'uso primario: il commit di **bump di versione post-release** generato automaticamente dal job `release` (riga ~537: `git commit -m "Bump version to ... after release ..."`), che oggi ri-triggera inutilmente l'intera CI (lint+test) sul branch di default.
4. **Elevare la manutenibilità del codice** alla fine dell'implementazione: **ampi commenti** nel codice sorgente, **logging strutturato a livelli** (INFO/DEBUG configurabili; WARN/ERROR/FATAL sempre attivi) e **documentazione dettagliata** (wiki sul repository Gitea, oppure gerarchia di file Markdown in `docs/` come fallback).

**Esito finale atteso:** plugin compatibile e verificato su FlatPress 1.5.1 (entry marcate con versione corretta), retrocompatibile con 1.4.x, CI che non esegue lavoro inutile sui push "di servizio" (`[skip-ci]`), codice commentato, logging a livelli, documentazione completa (wiki o `docs/`), release v1.0.0 pubblicata in modo pulito.

---

## 2. Stato attuale / Baseline

### 2.1 Repo e versioni
| Voce | Valore |
| --- | --- |
| Branch | `main` (up to date con `origin/main`) |
| Ultimo tag | `v0.9.0` |
| HEAD | `a48dace` "Bump version to 0.9.1-SNAPSHOT after release main" (+ 2 commit locali Fase 0: `fix(ci): pass SHUTDOWN_TIMEOUT as -e env to testbed container` `5887229`, `docs: add WORKPLAN…` `440e5a3`) |
| version.ini | `1.0.0-SNAPSHOT` (aggiornato manualmente dall'utente PRIMA della Fase 0 → **release target: v1.0.0**) |
| .gitignore | `+ test-manual/` (aggiunta manuale utente, non committata al momento della stesura) |
| Storia recente | commit `release fix`, `no pending`, `PID/GID`, `release fix` (nessun tag intermedio) |
| Worktree | **modifica NON committata** su `version.ini` e `.gitignore` (modifiche manuali utente, intenzionali) |
| Sorgenti FlatPress disponibili (locali) | **1.5.1**: `/mnt/v/devel/flatpress-1.5.1` · **1.4.1**: `/mnt/v/devel/flatpress-1.4.1` → confronti API diretti possibili (vedi Fase 1) |

### 2.2 Modifica non committata in `.gitea/workflows/ci.yml` — RISOLTA in Fase 0 ✅
- Il file usa **fine riga CRLF** (preservato in tutto l'editing di Fase 0).
- La modifica nel working tree passava correttamente `-e SHUTDOWN_TIMEOUT="00:10:00" \` come opzione `docker run` (con il prefisso `-e`).
- Il commit `HEAD` originale conteneva invece la riga spezzata (senza `-e`): corretta in **Fase 0** nel commit `fix(ci): pass SHUTDOWN_TIMEOUT as -e env to testbed container` (`5887229`), che rimuove anche gli spazi finali su `--restart unless-stopped \` preservando i CRLF.
- **Modifiche residue in worktree dopo la Fase 0** (NON legate al CI, intenzionali dell'utente): `version.ini` → `1.0.0-SNAPSHOT` e `.gitignore` (+`test-manual/`). Decisione utente 👉 mantenute; la release target diventa **v1.0.0**.

### 2.3 Risultati dell'analisi esplorativa (base già verificata — 1.4.1 ↔ 1.5.1)
**API FlatPress usate dal plugin e risultate INVARIATE sui sorgenti 1.5.1:**
- `plugin_getoptions`, `plugin_addoption`, `plugin_saveoptions`, `plugin_getdir`, `add_action('init', ...)`
- `AdminPanelAction`, `admin_addpanelaction('plugin', 'publisharticle', true)`, risorse Smarty `plugin:publisharticle/...`, `$this->smarty->assign()`
- `CONTENT_DIR`, `IMAGES_DIR` (costanti in `defaults.php`/`core.filesystem.php` — invariate)
- `entry_dir($id)`, `entry_init()` + `entry_index::add($id, $entry, $del, $update_title)`, `do_action('publish_post', $id, $entry)` — firme invariate; in 1.5 `add()` ha migliorato la gestione dei lock stale (>120s)
- `utils_kexplode` (formato entry) — invariato
- Template Smarty: `{html_form enctype=...}`, `{include file="shared:errorlist.tpl"}`, `{$plang.*}`, `|escape`, `{if}`, `{foreach}` — sintassi classica, da confermare su Smarty 5 nel testbed

**Punti critici rilevati dall'analisi:**
1. **UNICA dipendenza hardcoded 1.4.1**: `publisharticle/ArticleComposer.php` — `const VERSION = 'fp-1.4.1';` e default `version => self::VERSION` in `buildEntry()` (righe 311). Le entry generate verrebbero marcate `fp-1.4.1`. Le entry vecchie restano leggibili (formato invariato), ma serve una **decisione** (vedi §4 Fase 2 e §7): (a) `system_ver()` dinamico (raccomandato) oppure (b) mantenere `fp-1.4.1` documentato. In FlatPress 1.5.1 `system_ver()` = `'fp-1.5.1'` (verificato su `core/core.system.php`: `define('SYSTEM_VER','1.5.1'); function system_ver(){return 'fp-'.SYSTEM_VER;}`) ed è il valore usato da `admin.entry.write.php`.
2. **Smarty 4.5.5 → 5.7.0** (nuova cartella `smarty-5.7.0`, mbstring obbligatorio, polyfill incluso): i `.tpl` del plugin usano sintassi classica → **da testare** (Fase 1).
3. **Nuova cache APCu** (`core.apcu.php`) + `entry_parse` con cache statica/APCu: il plugin scrive file fresh → impatto atteso nullo, da confermare.
4. **`SYSTEM_VER`**: `'1.4.1' → '1.5.1'`; `system_ver()` restituisce `fp-1.4.1` (1.4.1, `core.system.php:74-75`) e `fp-1.5.1` (1.5.1, `core.system.php:68-69`). **Firma identica** nelle due versioni → il guard `function_exists('system_ver')` con fallback è retrocompatibile.
5. **BBCode 2.0.x**: i tag generati dal plugin (`[code]`, `[h2]`…`[h6]`, `[quote]`, `[hr]`, `[b]`/`[i]`/`[del]`/`[s]`, `[url="..."]`, `[img="..." alt= width= height=]`) restano validi.
6. `is_https()` riscritto, security hardening vari: non toccano le API usate dal plugin.
7. **PHP**: il runner CI usa PHP 8.4 (`php84-*` su Alpine) → il codice è già eseguito/lintato su PHP 8.4; restano da valutare eventuali deprecation su PHP 8.5 nel testbed.
8. **Retrocompatibilità 1.4.1 (verifica preliminare fatta sui sorgenti locali)**: `system_ver()` (identica in entrambe), `entry_init()`/`entry_dir()`/`entry_index::add()` (stesse firme), `CONTENT_DIR`/`IMAGES_DIR` (stesse costanti). La differenza introdotta da 1.5 (cache APCu in `entry_parse`) è **isolata al lato lettura** e non impatta la scrittura del plugin → nessuna rottura attesa su 1.4.1.

**Test esistenti del plugin (baseline):**
- Suites PHPUnit: `ArticleComposerTest`, `ArticleParserTest`, `ArticleImporterTest`, `ArticleProcessorSchedulingTest`, `CronMatcherTest` (phpunit.xml, `tests/bootstrap.php` con stub delle API FlatPress, fixtures in `tests/fixtures/content/`).
- Dev deps Composer: `phpunit ^10 || ^11`; nessuna dipendenza runtime.
- `tests/bootstrap.php` **non stubba `system_ver()`** → rilevante se si sceglie l'opzione (a) per VERSION.
- Test che asseriscono la versione di default `fp-1.4.1`: `ArticleComposerTest.php` righe ~40, 66, 146 (da aggiornare in Fase 3). `ArticleParserTest.php` restituisce/asserisce `fp-1.4.1` solo su stringhe di test (lettura formato, indipendente dal default).

**Pacchetti/flusso CI esistente (.gitea/workflows/ci.yml):**
- Trigger: `push`, `pull_request`, `workflow_dispatch` (input `job=package|release`, `package_run_id`).
- Job: `lint` (php -l) → `test` (PHPUnit) → `package` (solo `workflow_dispatch` job=package, build zip + upload a Gitea Packages) → `testbed` (docker ephemeral FlatPress, solo dopo package) → `release` (su push di tag `refs/tags/v*` **o** dispatch job=release; crea Gitea Release, poi bump di version.ini) → `notify` (ntfy, `if: always()`).
- `release` alla fine fa: bump `version.ini` (removing `-SNAPSHOT`, +1 patch, re-add `-SNAPSHOT`), commit `Bump version to X.Y.Z-SNAPSHOT after release <ref>` e push su default branch → **oggi questo push ri-triggera una run CI (lint+test) superflua**. Questa è la motivazione operativa del requisito `[skip-ci]`.

---

## 3. Requisiti

### 3.1 Funzionali (compatibilità 1.5.1)
- R1. Il plugin si installa e abilita su FlatPress 1.5.1 senza errori.
- R2. Le funzionalità esistenti continuano a funzionare su 1.5.1: pannello admin (upload `.md`), import da `import-in/`, scheduling (`pending/` + pubblicazione al primo page-load), import immagini, mapping categorie, scrittura entry in formato FlatPress, view counter e refresh dell'indice.
- R3. Le entry generate sono marcate con la versione FlatPress **decisa** (cfr. Fase 2 e §7); le entry già esistenti (anche `fp-1.4.1`) restano leggibili su 1.5.1.
- R4. Nessuna regressione sulle versioni MiniFlatPress / FlatPress 1.4.x supportate in precedenza (obiettivo: compatibilità, non migrazione unidirezionale).

### 3.2 Tecnici
- R5. Nessun refactor opportunistico: solo adeguamenti necessari e documentati.
- R6. Suite PHPUnit (phpunit ^10||^11, PHP 8.4 in CI) mantenuta verde; `php -l` pulito su ogni file PHP modificato.
- R7. I template Smarty mantengono sintassi compatibile con Smarty 5 (verifica, non sostituzione di massa).
- R8. Tutte le modifiche passano dal normale flusso CI (nessun bypass, salvo il nuovo `[skip-ci]`).

### 3.5 Manutenibilità (requisiti aggiuntivi utente)
- **R18 — Commenti ampi nel codice**: al termine dell'implementazione, ogni classe/metodo/funzione del plugin riceve commenti chiari ed estesi (scopo, parametri, ritorni, casi limite, riferimenti alle API FlatPress usate e al perché). Nessuna funzionalità cambia: solo documentazione inline.
- **R19 — Logging strutturato a livelli**: il plugin logga gli eventi con un sistema a livelli: **DEBUG** e **INFO** (soglia **configurabile** nel pannello config / opzione plugin), mentre **WARN**, **ERROR** e **FATAL** sono **sempre attivi** (non disattivabili). Output su un canale di log (errore PHP/error_log o file dedicato, da decidere in Fase 5), con prefisso identificativo del plugin.
- **R20 — Documentazione completa**: al termine dell'implementazione la documentazione copre tutto in dettaglio. **Preferenza**: wiki sul repository Gitea. **Fallback accettato**: gerarchia di file Markdown in `docs/`. In ogni caso i documenti **si linkano tra loro** (compresi i file già presenti in `docs/`, es. `flatpress_plugin_docs.md` e questo WORKPLAN).

### 3.3 CI/CD — tag `[skip-ci]`
- R9. Token riconosciuto: **`[skip-ci]`** (occorenza nel messaggio del commit `head` per gli eventi push; per PR anche titolo o messaggio del commit di testa — *raccomandato*).
- R10. Con `[skip-ci]` il push su branch (default o feature) **non esegue** `lint` e `test` (e i job a valle: `package`/`testbed` già non girano su push).
- R11. `workflow_dispatch` **non è mai** skippabile (trigger manuale esplicito).
- R12. Push di tag `v*`: comportamento coerente con la scelta A/B presa (vedi §4 Fase 4 e §7) — **scelta: opzione B** (skip totale se il commit contiene `[skip-ci]`, release inclusa).
- R13. Il commit di **bump post-release** generato dal job `release` deve contenere `[skip-ci]` nel subject (aggiunto automaticamente dal passo "Bump version to next SNAPSHOT").
- R14. Il comportamento è **verificato** con una matrice di test (push normale / push con token / tag con e senza token / dispatch) prima di considerare chiusa la Fase 4.

### 3.4 Vincoli di processo
- R15. **Nessuna modifica al codice applicata prima dell'approvazione di questo WORKPLAN** (il prodotto di questa iterazione è il solo file `docs/WORKPLAN.md`).
- R16. Documentazione del plugin in inglese (README/CHANGELOG/docs); il WORKPLAN è il documento di lavoro in italiano.
- R17. Non inventare fatti: ogni affermazione qui presente deriva dall'analisi riportata nel brief e dalla lettura dei file del repo.

---

## 4. Fasi di lavoro

> Responsabili: **Coder** (C), **Tester** (T), **Writer** (W). Ogni fase ha deliverable e criteri di accettazione. L'ordine è sequenziale salvo indicazioni contrarie.

### FASE 0 — Preparazione e normalizzazione baseline (C, T) — ✅ COMPLETATA (2026-09-19)
**Obiettivo:** partire da un worktree pulito e da una baseline test verde.

- **Task 0.1 — Commit del fix CI non committato** (C): ✅ committato come `fix(ci): pass SHUTDOWN_TIMEOUT as -e env to testbed container` (`5887229`), CRLF preservati, spazi finali rimossi da `--restart unless-stopped \`.
- **Task 0.1b — Commit del WORKPLAN** (C): ✅ `docs: add WORKPLAN for FlatPress 1.5.1 compat and [skip-ci]` (`440e5a3`).
- **Task 0.2 — Baseline PHPUnit** (T): ✅ 97 test, 253 assertions, 0 failures/errors/risky/deprecation (PHP locale 8.3.6 vs 8.4 CI; PHPUnit 11.5.56). ⚠️ **1 warning pre-esistente** (`mkdir(): Permission denied` in `ArticleImporterTest::testImportFileWarnsWhenNothingCanBeMoved`, chmod 0555 sul fixtures) → con `failOnWarning=true` la run locale esce con codice 1; in CI (root/Alpine) il test risulta skip → verde. **Da gestire in Fase 3**.
- **Task 0.3 — Snapshot baseline** (C): ✅ HEAD dopo Fase 0 `440e5a3`; ultimo tag `v0.9.0`; `version.ini` nell'ambiente di lavoro è poi passato a `1.0.0-SNAPSHOT` (modifica manuale utente, intenzionale; non committata perché fuori scope Fase 0).

**Esito atteso/raggiunto:** worktree SENZA diff residui su `ci.yml`; PHPUnit funzionalmente verde (warning ambientale noto); CRLF preservati. Modifiche residue in worktree: `version.ini` e `.gitignore` (modifiche utente intenzionali, da normalizzare prima della release).

### FASE 1 — Test di compatibilità su FlatPress 1.5.1 (T, con C in supporto) — ✅ COMPLETATA (2026-09-19)
**Obiettivo:** dimostrare (o smentire) la piena compatibilità funzionale su 1.5.1 senza modifiche.

- **Task 1.1 — Allestimento ambiente**: ✅ eseguito con installazione locale su filesystem ext4 (`/home/alessio/fp-test-151`) + `php -S localhost:8017` (PHP 8.3.6 CLI), setup web completato, plugin attivato (copia in `fp-plugins/publisharticle`). *(Testbed docker CI non usato: richiede vars non disponibili localmente.)*
- **Task 1.2 — Checklist funzionale manuale**: ✅ **10/10 PASS** su 1.5.1 (dettagli nel report `test-manual/fp151-compat-report.md`).
  1. Pannelli admin (publisharticle + pubartcfg) renderizzano con Smarty 5 — HTTP 200, nessun errore.
  2. Upload `.md` → entry creata, sidecar `view_counter` ok.
  3. Import da `import-in/` → file in `done/`; schedulato resta in `import-in/` (comportamento reale: **non va in `pending/`** finché non scade — spec da chiarire, vedi issue 5).
  4. Upload immagini ok (nome originale, no overwrite, conflitto→`failed/`).
  5. Categorie: `categories: Tecnologia, Notizie` → `CATEGORIES|6,2|` ok.
  6. Formato entry corretto; **`VERSION|fp-1.4.1|` hardcoded** (baseline documentata).
  7. MD→BBCode ok (strong/em/del/pre/h2/quote/link/tabella/img); `[hr]` resta letterale (issue 4).
  8. APCu assente → non applicabile; entry visibili in index.
  9. Homepage, RSS2 e Atom ok (`<item>` presenti).
  10. Sweep E_ALL su 11 URL senza warning/deprecation PHP/ Smarty.
- **Task 1.3 — Report**: ✅ `test-manual/fp151-compat-report.md` completo con evidenze e issues.
- **Task 1.4 — Check retrocompatibilità su 1.4.1**: ✅ **confronto statico** (API identiche: `CONTENT_DIR`/`IMAGES_DIR`, `system_ver()`, `plugin_getoptions()`, `admin_addpanelaction()`); formato entry scritto = nativo 1.4.x, letto senza errori da 1.5.1 → **retrocompatibile**.

**Issue emerse → Fase 2:**
1. `VERSION` hardcoded `fp-1.4.1` (Task 2.1 la risolve).
2. Pannello `admin.plugin.panel.publisharticle.php:202`: `count($_FILES['images']['name'])` → `TypeError` se il campo arriva come `images` e non `images[]` (robustezza → valutare in Task 2.2).
3. Liste Markdown (`-`, `*`, `1.`) non convertite in `[list]` (miglioramento → documentare/valutare).
4. `[hr]` emesso ma non supportato da FlatPress bbcode → resta letterale (miglioramento → documentare/valutare).
5. Spec `pending/` vs comportamento reale (schedulato resta in `import-in/`): chiarire spec/documentazione, non bug funzionale.

**Criterio di accettazione Fase 1:** ✅ raggiunto (10/10 PASS + retrocompat documentata).

### FASE 2 — Adeguamenti codice (C) — *solo se Fase 1 lo richiede + punto VERSION*
**Obiettivo:** interventi minimi e documentati.

- **Task 2.1 — Tag VERSION in `ArticleComposer.php` (PRIMARIO)**. **Scelta D1 = (a) dinamico** (approvata).
  - **Opzione (a) — SCELTA**: usare `system_ver()` quando disponibile (runtime FlatPress), con fallback per l'ambiente test:
    `$version = isset($entry['version']) ? $entry['version'] : (function_exists('system_ver') ? system_ver() : self::FALLBACK_VERSION);`
    con nuova costante esplicita (es. `const FALLBACK_VERSION = 'fp-1.5.1';`). L'override per-entry via frontmatter `version:` resta invariato. **Nota**: in FlatPress 1.5.1 `system_ver()` = `fp-1.5.1` (verificato su `core.core.system.php:66-69`), coerentemente con `admin.entry.write.php:179`. **Retrocompatibilità**: `system_ver()` esiste con firma identica anche in 1.4.1 (`core.system.php:74-75`) → il fallback scatta solo in ambienti senza FlatPress (test) e mai su FlatPress 1.4.x/1.5.x.
  - **Opzione (b) — mantieni `fp-1.4.1`** *(NON scelta)*: mantenuta come riferimento storico nel piano.
  - La scelta impatta Fase 3 (test) e Fase 5 (documentazione/README).
- **Task 2.2 — Fix richiesti dalla Fase 1** (se presenti): es. deprecation Smarty 5, deprecation PHP 8.4/8.5, compatibilità cache APCu, dettagli UI pannello. **Nessun refactor opportunistico**; ogni fix isolato e motivato e **verificato anche su 1.4.1** (nessuna regressione).
  - **Issue 1 (Fase 1) — `VERSION` hardcoded**: risolta dal Task 2.1.
  - **Issue 2 (Fase 1) — robustezza `$_FILES['images']`** nel pannello (`admin.plugin.panel.publisharticle.php:202`): gestire il caso in cui il campo arrivi come `images` (non `images[]`) o manchi del tutto, evitando `TypeError` su `count()`. **Da fissare in Task 2.2** (fix minimale).
  - **Issue 3–4 (Fase 1) — liste `[list]` e `[hr]`**: miglioramenti del convertitore Markdown→BBCode. Da valutare: fix minimale in Task 2.2 **oppure** decisione di documentare come limitazione nota (formato `[hr]` non supportato dal BBCode FlatPress su 1.5.x) — la decisione va riportata nel report; nessuna regressione su 1.4.1.
  - **Issue 5 (Fase 1) — spec `pending/` vs comportamento reale**: aggiornare README/docs (Fase 5) per descrivere il comportamento vero (file schedulato resta in `import-in/` finché non scade). Nessuna modifica funzionale.
- **Task 2.3 — Aggiornamento include_path/compatibilità** minima se la scelta (a) richiede l'accesso a `system_ver()` nel bootstrap dei test (in realtà è solo stub test-side, vedi Fase 3).

**Deliverable:** diff minimale su `publisharticle/ArticleComposer.php` (+ eventuali file fix Smarty/PHP) e nota di design in `docs/`.
**Criteri di accettazione:** Fase 1 re-eseguita (o mitigazione documentata) verde; PHPUnit verde dopo Fase 3; issue 2 risolta; issue 3–4 decise e documentate.

### FASE 3 — Aggiornamento test PHPUnit (T)
**Obiettivo:** coprire il cambiamento VERSION e i fix Fase 2; nessuna regressione.

- **Task 3.1 — `tests/bootstrap.php`**: se opzione (a), aggiungere stub `system_ver()` che restituisce una versione deterministica (es. `fp-1.5.1`) per simulare il runtime FlatPress (oggi manca — rilevato in §2.3).
- **Task 3.2 — `ArticleComposerTest.php`**: aggiornare gli assert del default versione (righe ~40, 66, 146). Nuovi casi:
  - default con `system_ver` stub → `fp-1.5.1` (o valore deciso);
  - fallback senza `system_ver` (se previsto) → `FALLBACK_VERSION`;
  - override frontmatter `version:` preservato;
  - legacy: un'entry `fp-1.4.1` serializzata è ancora letta correttamente dal parser (caso "formato invariato").
- **Task 3.3 — Esecuzione suite**: `vendor/bin/phpunit --testdox` su PHP 8.4 (CI) e, se disponibile, PHP 8.5 locale; `php -l` su tutti i file modificati.
- **Task 3.4 — Test manuali di regressione** su testbed (1.5.1) per i flussi chiave modificati dal tag VERSION (controllo valore VERSION nell'entry scritta).

**Deliverable:** test aggiornati + report di esecuzione.
**Criteri di accettazione:** suite verde (phpunit ^10||^11, PHP 8.4); nessun test `risky`/`warning` (phpunit.xml ha `failOnRisky="true"` e `failOnWarning="true"`); valore VERSION nell'entry verificato su testbed.

### FASE 4 — CI/CD: implementazione tag `[skip-ci]` (C, T)
**Obiettivo:** push con `[skip-ci]` non esegue job superflui; nessuna interruzione delle release.

**4.1 Progettazione (da approvare con questo piano — dettaglio esecutivo per il Coder)**

Meccanismo **esplicito e robusto** (indipendente da eventuale supporto nativo di Gitea Actions, non documentato in modo affidabile — cfr. issue `go-gitea/gitea#28020`): un **gate job** `ci-skip-check` che calcola `should_skip` e i job a valle lo consumano via `needs` + `if:`. Centralizza la logica in un punto solo e propaga lo skip in modo transitivo.

- **Nuovo job `ci-skip-check`** (prima di `lint`):
  - nessun `needs`;
  - **output** `should_skip` (`true|false`);
  - logica (**Opzione B — SCELTA**, R12; assenza di eccezioni per i tag):
    1. `event_name == 'workflow_dispatch'` → `false` (R11: mai skippabile);
    2. `event_name == 'push'` → ispeziona `github.event.head_commit.message` (e, opzione robustezza, `join(github.event.commits.*.message, '\n')` per push multi-commit): se contiene `[skip-ci]` → `true`; **vale anche per i push di tag `v*`** (opzione B: se il commit del tag contiene il token, tutto viene saltato, release inclusa);
    3. `event_name == 'pull_request'` → ispeziona `github.event.pull_request.title` e il messaggio del commit head del PR: se contiene `[skip-ci]` → `true`;
    4. altrimenti → `false`.
  - Tutte le occorrenze `${{ github.event.* }}` trattano il caso `null` (es. `|| ''`) per non rompere gli eventi che non popolano il campo.
- **Condizioni sui job esistenti**:
  - `lint`, `test`: `needs: [ci-skip-check]` + `if: needs.ci-skip-check.outputs.should_skip != 'true'`.
  - `package`: mantiene il suo `if` (workflow_dispatch) e aggiunge `needs: [lint, test, ci-skip-check]` — su push non parte comunque, ma è protetto anche in caso di futuri trigger.
  - `testbed`: invariato nella logica (`needs: [package]`); eredita lo skip se `package` è saltato.
  - `release` (**opzione B**): aggiungere `needs: [lint, test, ci-skip-check]` + `if: needs.ci-skip-check.outputs.should_skip != 'true' && (startsWith(github.ref, 'refs/tags/v*') || (github.event_name == 'workflow_dispatch' && github.event.inputs.job == 'release'))` → il tag di release non è mai bloccato a meno che il commit non contenga `[skip-ci]` (in tal caso release solo via `workflow_dispatch`).
  - `notify`: `if: always()` + `needs` tutti i job; i job saltati riportano risultato `skipped` → la notifica resta coerente (messaggio "skipped" opzionale via `needs.*.result`).
- **Modifica al commit di bump post-release** (job `release`, riga ~537 — richiesto dall'utente, R13):
  `git commit -m "Bump version to $NEXT after release $GITHUB_REF_NAME [skip-ci]"` (token aggiunto automaticamente dal passo "Bump version to next SNAPSHOT").
- **Coerenza con eventuale supporto nativo**: se il runner Gitea in uso riconoscesse nativamente i token skip, il doppio controllo è innocuo (run non creata o job skippati in entrambi i casi).

**4.2 Opzioni sul perimetro dello skip — DECISA: opzione B (vedi §7)**
- **Opzione A (non scelta)**: `[skip-ci]` salta `lint`/`test` (+ transitivamente `package`/`testbed`) su push/PR; i push di tag `v*` e `workflow_dispatch` non sono mai saltati. La release continua a essere automatica sul tag.
- **Opzione B (SCELTA)**: `[skip-ci]` salta **tutto**, inclusa la release su tag; la release può comunque avvenire su un tag il cui commit **non** contiene il token, oppure via `workflow_dispatch` (`job=release`, con `package_run_id`). Il token è aggiunto automaticamente al commit di bump post-release dal job `release`.

**4.3 Verifica del comportamento (matrice di test — accettazione Fase 4)** (T)
| # | Scenario | Atteso |
| --- | --- | --- |
| 1 | Push su `main` di commit normale ("fix: ...") | `lint`+`test` eseguiti; `package`/`testbed` saltati (come oggi); `release` non parte |
| 2 | Push su `main` di commit con subject `chore: bump ... [skip-ci]` | Run creata con `ci-skip-check` ok e `lint`/`test` **skipped**; `notify` ne dà conto; nessuna esecuzione docker |
| 3 | Push multi-commit (uno contiene `[skip-ci]`) | Allo stesso modo dello scenario 2 (se si implementa la scansione multi-commit) |
| 4 | Push di tag `v1.0.0` con commit di rilascio normale (senza token) | `lint`+`test`+`release` eseguiti; release creata; bump post-release con `[skip-ci]` |
| 5 | Push di tag `v1.0.0` il cui commit contiene `[skip-ci]` (opzione B) | **Tutto saltato** (`ci-skip-check` = true), release compresa; eventuale release di recupero via `workflow_dispatch` `job=release` con `package_run_id` |
| 6 | `workflow_dispatch` job=package / job=release | Sempre eseguito (mai skippato) |
| 7 | PR il cui titolo contiene `[skip-ci]` (se supportato) | `lint`/`test` skipped |
Nota: la matrice si verifica sull'istanza Gitea (UI + API), non solo localmente.

**Deliverable:** `ci.yml` aggiornato + matrice di test compilata con esiti.
**Criteri di accettazione:** tutti gli scenari della matrice corrispondono agli attesi; il push del bump post-release di una release reale **(Fase 6)** non produce una run CI superflua.

### FASE 5 — Manutenibilità: commenti, logging e documentazione (C, T, W)
**Obiettivo:** rendere il codice mantenibile e tutto documentato, come richiesto dall'utente (R18–R20). Nessuna modifica funzionale: è un'opera di rifinitura al termine dell'implementazione.

- **Task 5.1 — Commenti ampi nel codice** (C, R18): per ogni classe, metodo e funzione del plugin (tutti i file in `publisharticle/`) aggiungere/estendere commenti: docblock con scopo, parametri, ritorno, eccezioni/errori, riferimenti alle API FlatPress usate (`system_ver()`, `entry_dir()`, `entry_init()`, `plugin_getoptions`, ecc.) e note per i casi limite. Nessuna modifica di logica.
- **Task 5.2 — Sistema di logging a livelli** (C, T, R19): introdurre una utility di log interna al plugin (es. `PublishArticleLog` o funzione `publisharticle_log($level, $message, $context=[])`) con:
  - livelli: `DEBUG`, `INFO`, `WARN`, `ERROR`, `FATAL`;
  - **soglia configurabile**: opzione plugin (panello config) `log_level` che ammette `debug`/`info` (disattivare anche `INFO` per ridurre il rumore); `WARN`/`ERROR`/`FATAL` **sempre attivi**;
  - canale di output: `error_log()` di PHP (prefissato es. `[publisharticle] livello:`) — nessuna dipendenza esterna, retrocompatibile 1.4.x/1.5.x;
  - logging puntuale nei flussi chiave: import progress (DEBUG), entrata/uscita fasi principali del processore (INFO), errori di scrittura/upload/parse (ERROR/FATAL), warning su cartelle non scrivibili (WARN);
  - test (T): unit test della utility (filtro per soglia configurata, formato riga) e smoke test della configurazione `log_level` da pannello.
- **Task 5.3 — Documentazione completa · wiki (W, R20)**:
  - **Preferenza: wiki sul repository Gitea** (se disponibile per il repo `publisharticle`): creare la wiki con pagine collegate (Home, Installazione, Configurazione, Flusso di lavoro, CI/CD `[skip-ci]`, Compatibilità FlatPress 1.4.x/1.5.x, FAQ).
  - **Fallback accettato: gerarchia Markdown in `docs/`** con indice centrale `docs/README.md` (o `docs/index.md`) che **linka tutti i file** già presenti e nuovi: questo WORKPLAN, `flatpress_plugin_docs.md`, il BBCode wiki salvato, nuove pagine di compatibilità e di CI/CD.
  - In ogni caso tutti i documenti **si collegano tra loro**; la documentazione copre anche README (inglese) e release note.
- **Task 5.4 — README.md** (W): aggiornare frontmatter `version` (default dinamico `system_ver()`), sezione compatibilità FlatPress (1.4.x + 1.5.1), sezione `[skip-ci]` (convenzione, eventi coperti, esempio `Bump version to ... [skip-ci]`), sezione logging (`log_level`).

**Deliverable:** codice commentato; utility log + test; wiki su Gitea **oppure** gerarchia `docs/` con indice e link incrociati; README aggiornato.
**Criteri di accettazione:** R18–R20 soddisfatti: ogni classe documentata, log a livelli con soglia configurabile e WARN/ERROR/FATAL sempre attivi, documentazione linkata e completa; PHPUnit ancora verde dopo l'aggiunta dei test di logging; `php -l` pulito.

### FASE 6 — Release v1.0.0 (C, con approvazione utente)
**Flusso (Opzione B + VERSION dinamico, coerentemente con le decisioni approvate):**
1. Preparare il commit di release: version.ini `1.0.0` (senza `-SNAPSHOT`); subject es. `Release v1.0.0` (**senza** `[skip-ci]`, così lint/test/release girano e il tag viene validato).
2. Tag `v1.0.0` e push del tag → CI esegue `lint`+`test`+`release`; release pubblicata su Gitea con asset zip.
3. Il job `release` fa il bump a `1.0.1-SNAPSHOT` col commit che **contiene `[skip-ci]` aggiunto automaticamente** dal passo "Bump version to next SNAPSHOT" → il push sul default branch non ri-triggera il workflow (R13).
4. Verifica finale: nessuna run CI superflua; entry di esempio marcata `fp-1.5.1` su FlatPress 1.5.1 e lettura corretta anche su 1.4.1 (retrocompatibilità).

**Deliverable:** tag `v1.0.0`, release su Gitea, `version.ini` = `1.0.1-SNAPSHOT`.
**Criteri di accettazione:** release pubblicata; ultimo push (bump con `[skip-ci]`) non produce lavoro CI; Fase 4 matrice scenario 4-6 confermato in produzione.

---

## 5. Rischi e mitigazioni

| # | Rischio | Impatto | Mitigazione |
| --- | --- | --- | --- |
| R1 | Tag `VERSION` hardcoded `fp-1.4.1` | Entry nuove "firmate" come vecchie; ambiguità futura | Decisione esplicita (§7): opzione (a) dinamica raccomandata; entry vecchie comunque leggibili; override per-entry via frontmatter |
| R2 | Smarty 5 incompatibilità nei `.tpl` (sintassi classica) | Pannello admin non renderizza | Fase 1 verifica mirata; eventuali fix minimi e isolati in Fase 2; niente migrazione di massa |
| R3 | Cache APCu + entry_parse statica | Entry pubblicata non subito visibile | Fase 1 test con cache attiva/clear-cache; se necessario aggiornare note operative |
| R4 | Lock `entry_index` (stale >120s migliorato in 1.5) | Scritture concorrenti | Nessuna azione prevista; coperto da test scheduling e import multi-file in Fase 1 |
| R5 | Deprecation PHP 8.4/8.5 | Warning/E_NOTICE in runtime | CI già su PHP 8.4; Fase 1 con E_ALL; eventuali fix in Fase 2 |
| R6 | CRLF in `ci.yml` | Diff sporchi / corruzione file | Editing che preserva CRLF (Task 0.1); git `core.autocrlf` verificato |
| R7 | Payload Gitea `github.event.head_commit.message` non popolato in certi eventi | Gate sbagliato (skip non rilevato o falso positivo) | Espressioni con `|| ''`; alternativa robusta nel gate: `git log -1 --pretty=%B` dopo checkout; matrice di test su istanza reale |
| R8 | `[skip-ci]` troppo aggressivo (blocca release su tag) | Release mancate | Opzione B (scelta): la release va su tag senza token o via `workflow_dispatch`; documentazione della convenzione; scenario 5 in matrice |
| R9 | Modifica non committata in `ci.yml` persa | Comportamento testbed diverso da HEAD | Task 0.1 committa subito il fix SHUTDOWN_TIMEOUT |
| R10 | Refactor opportunistici non richiesti | Regressioni non necessarie | Vincolo R5; review in Fase 2 con diff minimale |
| R11 | Logging mal implementato (troppo verboso/pericoloso in produzione) | Log pieni di DEBUG o gap informativi | Soglia `log_level` configurabile; default `info` o `warn`; WARN/ERROR/FATAL sempre attivi; test dedicati in Fase 5 |
| R12 | Wiki non disponibile sul repo Gitea | Requisito di documentazione non soddisfatto | Fallback previsto: gerarchia Markdown in `docs/` con indice e link incrociati (R20) |

---

## 6. Criteri di fine lavoro / Definition of Done

1. **Compatibilità 1.5.1 verificata**: checklist Fase 1 completata, tutti i flussi chiave (admin, import, scheduling, immagini, categorie, view counter, index, cache) funzionanti su FlatPress 1.5.1; eventuali fix (Fase 2) verificati.
2. **Retrocompatibilità 1.4.x preservata**: Task 1.4 verde (o confronto statico documentato); nessuna regressione funzionale sulle versioni già supportate (R4); gli adeguamenti per 1.5.1 sono additivi (guard `function_exists`, fallback) e non modificano il formato entry.
3. **Decisioni prese e implementate**: tag VERSION (opzione (a) in §7) e `[skip-ci]` (opzione B in §7) implementati come da piano.
4. **Qualità**: `php -l` pulito; suite PHPUnit verde (phpunit ^10||^11, PHP 8.4, niente risky/warning); test aggiornati per la nuova versione di default e la compatibilità legacy.
5. **CI `[skip-ci]`**: gate job attivo (opzione B); matrice di test (7 scenari) compilata e conforme; commit di bump post-release contiene il token aggiunto automaticamente dal passo "Bump version to next SNAPSHOT"; nessuna run CI superflua dopo il bump reale della Fase 6.
6. **Manutenibilità (R18–R20)**: codice ampiamente commentato (R18); logging a livelli INFO/DEBUG configurabili e WARN/ERROR/FATAL sempre attivi (R19) con test dedicati; documentazione completa sotto forma di **wiki su Gitea** o, in alternativa, **gerarchia di Markdown in `docs/` con indice e link incrociati** ai file già presenti (R20).
7. **Release**: `v1.0.0` pubblicata su Gitea con asset; `version.ini` a `1.0.1-SNAPSHOT`; worktree pulito.
8. **Perimetro**: nessun refactor opportunistico; nessuna modifica oltre quelle previste dal piano.

---

## 7. Approvazioni e decisioni richieste

### Decisioni da prendere PRIMA dell'avvio (richieste all'utente/orchestratore)

**D1 — Tag VERSION delle entry (Fase 2).** ✅ **Scelta: (a) Dinamico.**
- **(a) Dinamico — SCELTA**: `system_ver()` nel plugin (fallback `fp-1.5.1` per i test); entry marcate `fp-1.5.1` su FlatPress 1.5.1, come `admin.entry.write.php`.
- Verifica fatta: `flatpress-1.5.1/fp-includes/core/core.system.php` righe 66/69 definiscono `SYSTEM_VER='1.5.1'` e `system_ver(){return 'fp-'.SYSTEM_VER;}` → `fp-1.5.1`; `admin/panels/entry/admin.entry.write.php:179` usa `$arr['version']=system_ver();`. ✅ Compatibile.
- L'utente ha specificato: "da una grep trovo `flatpress-1.5.1/fp-includes/core/core.system.php:define('SYSTEM_VER', '1.5.1')`; controlla se va bene" → **confermato**.

**D2 — Perimetro di `[skip-ci]` (Fase 4).** ✅ **Scelta: Opzione B.**
- **(Opzione B — SCELTA)**: `[skip-ci]` salta **tutto** (incluso il job `release` su tag `v*`); la release può comunque avvenire su un tag `v*` il cui commit **non** contiene il token, oppure via `workflow_dispatch` (`job=release`, con `package_run_id`).
- **Il token va aggiunto automaticamente dal passo "Bump version to next SNAPSHOT" del job `release`** (riga ~537): il messaggio del commit di bump diventa `Bump version to $NEXT after release $GITHUB_REF_NAME [skip-ci]` → quel push sul default branch non ri-triggera lint/test/release.

**D3 — Conferma operativa.** ✅ **Sì / Sì.**
- ✅ Committerò in Fase 0 (come fix autonomo) la modifica non committata su `ci.yml` (`-e SHUTDOWN_TIMEOUT=...`).
- ✅ Supporto `[skip-ci]` anche su **PR** (titolo/commit head).

**D4 — Documentazione (Fase 5).** ✅ Requisito utente: **wiki** se possibile, altrimenti **docs/**.
- ✅ Preferenza: **wiki sul repository Gitea** (verificare in Fase 5 se il repo dei plugin è pubblico/abilitato alle wiki).
- ✅ Fallback accettato: **gerarchia di Markdown in `docs/`** con indice centrale (`docs/index.md`) che linka tutti i file (inclusi WORKPLAN.md, `flatpress_plugin_docs.md`, il BBCode salvato e le nuove pagine).
- Nota: il WORKPLAN è stato spostato in `docs/WORKPLAN.md`.

**D5 — Logging (Fase 5).** ✅ Requisito utente (R19).
- ✅ Livelli `DEBUG`/`INFO` **configurabili** (opzione plugin `log_level`, default `info`); `WARN`/`ERROR`/`FATAL` sempre attivi e mai disattivabili.
- ✅ Output su `error_log()` PHP con prefisso `[publisharticle]` (nessuna dipendenza esterna, retrocompatibile).

### Firme
| Ruolo | Nome | Data | Esito (approvato / corretto / cestinato) |
| --- | --- | --- | --- |
| Utente / Orchestratore | | 2026-09-19 | Approvato con D1-D5 |
| Coder | | | |
| Tester | | | |
| Designer | | | |

*Dopo l'approvazione (o dietro indicazioni di correzione), il piano viene eseguito dalle fasi 0→6 nell'ordine; qualsiasi scostamento richiede una revisione di questo documento.*