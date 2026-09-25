# Fix Release v1.0.3 — Upload immagine dal pannello di pubblicazione diretta

| Campo | Valore |
| --- | --- |
| **Data** | 2026-09-25 |
| **Stato** | Release pianificata; fix pronta, in attesa di release. |
| **Riferimento WORKPLAN** | [`docs/WORKPLAN.md`](WORKPLAN.md), non aggiornato di proposito; vedi [Nota finale](#nota-finale). |

## Sintomo

Dal pannello di pubblicazione diretta, il caricamento dell'immagine allegata all'articolo non salvava il file nella cartella media di FlatPress. L'operazione non produceva alcun errore visibile per l'utente.

## Causa (diagnosi)

- Il pannello usava la costante `IMAGES_DIR` di FlatPress così com'è. In FlatPress 1.4.x/1.5.x è relativa: `IMAGES_DIR = FP_CONTENT . 'images/'`, ossia `fp-content/images/`, ed è definita in `defaults.php`. I core la risolvono invece sempre come `ABS_PATH . IMAGES_DIR`. Usata senza il prefisso, funzionava solo se il working directory del processo PHP era la root del blog.

- Nel ramo publish/draft di `_handleUpload()`, il fallimento di `move_uploaded_file()` era silenzioso: non vi erano un ramo `else`, un log o un fallback. Il file spariva senza segnalazione.

- La regressione ha origine nel commit `139c60d` (`fix: correct images directory path using IMAGES_DIR or fp-content/images`), incluso nella v1.0.0.

- La normalizzazione di `$_FILES['images']` introdotta in Fase 2 nel commit `a769672` **non è la causa**: il form usa `images[]`.

## Fix applicata

### Risoluzione unificata del percorso

In `publisharticle/ImageUploader.php` sono stati aggiunti i metodi pubblici `resolveImagesDir()` e `getImagesDir()`:

- `resolveImagesDir()` usa `ABS_PATH` come prefisso quando è disponibile e `IMAGES_DIR` è relativo.
- Rileva ed evita un doppio prefisso quando `IMAGES_DIR` è già assoluto.
- Se `ABS_PATH` manca, applica il fallback legacy per la retrocompatibilità con FlatPress 1.4.x.

La risoluzione è ora condivisa dal pannello, dal folder importer e dal cron.

### Salvataggio e feedback nel pannello

In `publisharticle/panels/admin.plugin.panel.publisharticle.php`, il ramo publish/draft usa ora `ImageUploader::getImagesDir()`. Inoltre:

- è stato aggiunto un ramo `else` per il fallimento di `move_uploaded_file()`;
- il pannello tenta `copy()` e, se il fallback riesce, rimuove il file temporaneo con `@unlink`, mantenendo comunque registrata l'immagine;
- in caso di fallimento totale, registra l'evento con `publisharticle_log('warn', ...)`.

## Verifica

| Controllo | Esito |
| --- | --- |
| Analisi sintattica | `php -l` pulito. |
| Suite PHPUnit | Verde: **123 tests / 316 assertions, exit 0**. |
| Baseline precedente | 114 test / 306 assertions. |
| Nuovi test | 9 test in `tests/ImageUploaderTest.php`. |

I 9 test coprono la costruzione del percorso per:

- `ABS_PATH` con `IMAGES_DIR` relativo;
- slash finale;
- `IMAGES_DIR` già assoluto;
- drive Windows;
- assenza delle costanti richieste.

## Impatto compatibilità

Il comportamento è identico su FlatPress 1.4.x e 1.5.x, che definiscono entrambe `IMAGES_DIR` come costante relativa e `ABS_PATH` come prefisso. Restano preservati i fallback se le costanti mancano.

## File coinvolti

| Stato | File |
| --- | --- |
| Modificato | `publisharticle/ImageUploader.php` |
| Modificato | `publisharticle/panels/admin.plugin.panel.publisharticle.php` |
| Nuovo | `tests/ImageUploaderTest.php` |
| Non toccato | `version.ini` |
| Non toccato | `docs/WORKPLAN.md` |

## Nota finale

Il WORKPLAN non è stato aggiornato di proposito, secondo la decisione dell'utente di mantenere le fix in un file separato. Il `docs/WORKPLAN.md` verrà eventualmente riallineato in un momento successivo oppure resterà indietro di proposito.
