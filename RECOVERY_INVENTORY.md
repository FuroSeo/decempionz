# Decempionz — Inventario recupero working copy storica

Fonte audit: `C:\Projects\decempionz` (pacchetto `Sincronizzazione Decempionz.zip` ricevuto il 2026-09-17).

Obiettivo: preservare tutte le funzioni e gli strumenti utili della vecchia working copy senza sovrascrivere il codice di produzione corrente e senza pubblicare per errore materiale interno.

## Stato funzioni principali

- ✅ **Pannello dev** — verificato dentro `index.html` su GitHub/produzione, incluse le funzioni Feature & Storage per Daily, Chemistry, tutorial, trofei e duelli.
- ✅ **Dataset Editor** — recuperato come `dataset-editor.html`; carica/modifica/salva `game-data.js`, è escluso dal deploy FTP e il JavaScript inline è controllato dalla CI.
- ✅ **Decempionz Studio** — recuperato come `_studio.html`; conserva le 7 modalità storiche e l'export PNG, è escluso dal deploy FTP e il JavaScript inline è controllato dalla CI.
- 🟡 **Generatori i18n/rose** — `_build_i18n.py` e `_build_rose.py` sono preservati nella working copy/ZIP; da importare e testare contro il repository corrente nella seconda tranche.
- 🟡 **Game Manual** — `GAME_MANUAL.md` recuperato e analizzato; il contenuto funzionale è valido, ma versioning/workflow descrivono ancora il vecchio `_push.bat` e vanno aggiornati prima di renderlo canonico nel repository pubblico.

## Classificazione dei 17 file recuperati

| File | Byte | SHA-256 (prefisso) | Classificazione | Stato / azione |
|---|---:|---|---|---|
| `GAME_MANUAL.md` | 36819 | `4cfbf0199e70223e` | documentazione canonica storica | 🟡 aggiornare workflow/versioning e sanitizzare prima dell'import |
| `dataset-editor.html` | 20717 | `cc30c03f0584788a` | tool locale attivo | ✅ recuperato, CI syntax-check, escluso dal deploy |
| `_studio.html` | 29366 | `ab4f765b08bb197e` | tool grafico/social locale attivo | ✅ funzionalità recuperate, CI syntax-check, escluso dal deploy |
| `_build_i18n.py` | 7471 | `be27f0a5e0da0849` | build tool | 🟡 preservato; da importare e testare |
| `_build_rose.py` | 21072 | `375949d0f58a598d` | build tool | 🟡 preservato; da importare e testare |
| `ROADMAP.md` | 17399 | `7103de66273d1b29` | roadmap storica | preservata come fonte; consolidare con Roadmap Master corrente |
| `VADEMECUM.md` | 11835 | `c8c0524e93a6cc0f` | guida operativa legacy | preservata; aggiornare prima di un eventuale import pubblico |
| `CHECKLIST_TEST_5.6.2.md` | 8970 | `eba831416bc79ebe` | QA storico | preservata; estrarre i test ancora validi nella smoke-test suite corrente |
| `DATASET_ANALYSIS.md` | 19122 | `da9bb35f4717f0d5` | audit dataset storico | preservato come fonte per Block 5 |
| `ANALISI_SITO_2026-07.md` | 9602 | `5e1b1d0c6ec748c9` | audit storico | **non importare integralmente nel repo pubblico**: contiene un vecchio dato sensibile ormai compromesso/storico |
| `_launch_kit.md` | 5649 | `609b892df06b8e8b` | contenuto/marketing storico | preservato come riferimento |
| `_mock_home.html` | 74592 | `4c760b13691b2100` | mock UX | archivio di design; utile per Block 7 |
| `_mock_chem.html` | 9492 | `6e7e10b99b30dc05` | mock Chemistry | archivio di design; utile per Block 9 |
| `wc_build.py` | 28768 | `766d1158dfebf94f` | tool legacy World Cup | preservato; non considerare attivo senza test |
| `wc_constants.js` | 34219 | `9b7855b8bc4bc1c3` | dataset/tool legacy World Cup | preservato; confrontare con `game-data.js` prima di ogni riuso |
| `wc_patch.py` | 56955 | `7a99594e3568956e` | patch one-off legacy | preservato; contiene percorsi hardcoded del vecchio ambiente |
| `_sync_version.py` | 622 | `c7e25298cb17e924` | tool legacy versioning | **non riattivare**: lega app version e Service Worker cache, concetti ora separati |

## Regole di recupero

1. La working copy storica non viene più usata per deploy diretti.
2. `_push.bat` resta dismesso: bypassava branch, PR e CI facendo push diretto su `main`.
3. I tool locali recuperati devono essere esclusi dal deploy FTP.
4. I documenti che contengono segreti, vecchie credenziali o dettagli operativi sensibili non vanno committati integralmente in un repository pubblico.
5. Ogni tool recuperato va testato contro l'architettura corrente prima di essere marcato come attivo.
6. La copia originale locale e lo ZIP di recupero vanno conservati fino alla chiusura dell'audit.

## Prossimi passi

- aggiornare l'istruzione legacy `_push.bat` ancora presente nel messaggio post-salvataggio del Dataset Editor;
- importare/testare `_build_i18n.py` e `_build_rose.py` contro il repository corrente;
- ricostruire un `GAME_MANUAL.md` aggiornato al workflow branch → PR → CI → merge → deploy e al versioning separato app/cache/data;
- migrare i test ancora validi da `CHECKLIST_TEST_5.6.2.md` nella QA permanente;
- creare infine una clone locale pulita del repository e mantenere la cartella storica come archivio read-only.
