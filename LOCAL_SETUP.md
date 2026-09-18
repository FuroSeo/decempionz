# Decempionz — Local setup and working copy

Questa guida sostituisce il vecchio flusso `_push.bat`.

## Regola principale

GitHub `FuroSeo/decempionz` è la fonte di verità. `main` è produzione e ogni push/merge su `main` avvia il deploy FTP.

La vecchia cartella `C:\Projects\decempionz` va preservata come archivio storico finché l'audit di recupero non è concluso. Non usare più `_push.bat`.

## Prima migrazione su Windows

Chiudi editor, terminali e processi che stanno usando `C:\Projects\decempionz`. Poi apri Prompt dei comandi in `C:\Projects` e usa:

```bat
ren decempionz decempionz-legacy-2026-09-17
git clone https://github.com/FuroSeo/decempionz.git decempionz
cd decempionz
git remote -v
git branch --show-current
git status
```

Risultato atteso:

- `origin` punta a `https://github.com/FuroSeo/decempionz.git`;
- branch corrente `main`;
- working tree pulita.

Non cancellare `decempionz-legacy-2026-09-17` fino alla chiusura dell'audit.

## Lavorare a una modifica

```bat
git switch main
git pull --ff-only
git switch -c feature/nome-modifica
```

Dopo le modifiche:

```bat
python scripts\validate_project.py
git status
git diff
git add <file...>
git commit -m "descrizione breve"
git push -u origin feature/nome-modifica
```

Poi aprire una PR verso `main`. Non fare push diretto su `main`.

## Tool locali recuperati

- `dataset-editor.html`: apre/modifica/salva `game-data.js`;
- `_studio.html`: genera asset social e PNG;
- `_build_rose.py`: rigenera le pagine rose;
- `_build_i18n.py`: rigenera le pagine localizzate.

Questi file sono versionati ma esclusi dal deploy FTP. Essendo il repository pubblico, non devono contenere segreti.

## Modifiche dataset

Dopo una modifica a `game-data.js`:

1. aggiorna la revisione `game-data.js?v=...` in `index.html`;
2. rigenera le rose se necessario con `python _build_rose.py`;
3. esegui `python scripts\test_generators.py` per verificare in una copia temporanea entrambi i generatori recuperati;
4. esegui `python scripts\validate_project.py`;
5. controlla il diff dei file generati;
6. commit/push sul branch e PR.

## Produzione

Il deploy è automatico solo dopo il merge/push su `main`. Verificare sempre GitHub Actions e poi fare smoke test live.

## Rollback

Non usare reset/force-push su `main`. Revertire il merge/commit problematico e lasciare che il nuovo push su `main` ridistribuisca la versione precedente.
