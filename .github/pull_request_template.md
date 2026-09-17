## Scopo

Descrivi in poche righe cosa cambia e perché.

## Game Manual

- [ ] `GAME_MANUAL.md` aggiornato perché la PR cambia comportamento/regole/modalità/dati/UX significativa
- [ ] Oppure: **No manual impact** — modifica puramente tecnica/operativa

> Selezionare una delle due opzioni sopra. Il manuale è il registro funzionale canonico del progetto.

## Checklist

- [ ] Il cambiamento è limitato a un solo obiettivo logico
- [ ] Non sono presenti modifiche accidentali o file non correlati
- [ ] La validazione automatica della PR è verde
- [ ] Se `game-data.js` è cambiato, è stata aggiornata la sua revisione `?v=` in `index.html`
- [ ] Se cambia ciò che gli utenti riconoscono come release, è stato valutato l'aggiornamento di `GAME_VERSION`
- [ ] Se cambiano asset/cache del Service Worker, è stato valutato l'aggiornamento del namespace `CACHE`
- [ ] Gli endpoint PHP e i dati dinamici restano esclusi dalla cache del Service Worker
- [ ] I file di stato server-side non vengono sovrascritti dal deploy FTP
- [ ] I tool/documenti interni restano esclusi dal deploy FTP e non contengono segreti

## Test manuali

Indicare i test eseguiti prima del merge e quelli da eseguire dopo il deploy.

- [ ] Desktop
- [ ] Mobile
- [ ] Flusso interessato dalla modifica

## Rollback

Indicare il modo più semplice per tornare alla versione precedente se il deploy causa regressioni.
