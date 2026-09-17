## Scopo

Descrivi in poche righe cosa cambia e perché.

## Impatto sul Game Manual

Se la modifica cambia gameplay, regole, modalità, scoring, tattiche, Draft, Chemistry, Coach, Momentum, Daily/Weekly, Challenge, Duel, Dynasty, progressione o altri comportamenti visibili all'utente, aggiornare `GAME_MANUAL.md` nella stessa PR.

Se non serve aggiornare il manuale, indicare esplicitamente:

`No manual impact`

## Checklist

- [ ] Il cambiamento è limitato a un solo obiettivo logico
- [ ] Non sono presenti modifiche accidentali o file non correlati
- [ ] La validazione automatica della PR è verde
- [ ] `GAME_MANUAL.md` è stato aggiornato se la modifica ha impatto documentabile, oppure è stato dichiarato `No manual impact`
- [ ] Se `game-data.js` è cambiato, è stata aggiornata la sua revisione `?v=` in `index.html`
- [ ] Se cambia ciò che gli utenti riconoscono come release, è stato valutato l'aggiornamento di `GAME_VERSION`
- [ ] Se cambiano asset/cache del Service Worker, è stato valutato l'aggiornamento del namespace `CACHE`
- [ ] Gli endpoint PHP e i dati dinamici restano esclusi dalla cache del Service Worker
- [ ] I file di stato server-side non vengono sovrascritti dal deploy FTP

## Test manuali

Indicare i test eseguiti prima del merge e quelli da eseguire dopo il deploy.

- [ ] Desktop
- [ ] Mobile
- [ ] Flusso interessato dalla modifica

## Rollback

Indicare il modo più semplice per tornare alla versione precedente se il deploy causa regressioni.
