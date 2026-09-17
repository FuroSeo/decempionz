# Decempionz — Runtime backup & recovery

Questo documento descrive il ciclo di backup dei dati server-side di produzione.

## Classificazione dati

### Durevoli — backup automatico prima di ogni modifica
- `hall-of-fame.json`
- `global-stats.json`
- `game-counter.json`
- `daily-scores/YYYY-MM-DD.json`
- `challenge-scores/YYYY-Www.json`

### Temporanei / condivisi — non inclusi nel backup durevole
- `drafts/`
- `duels/`

Questi ultimi hanno un ciclo di vita diverso: la retention dei draft e l'autorizzazione degli upload sono tracciate separatamente nell'issue #14. I Duel sono link/sessioni temporanee e non sono trattati come archivio storico.

## Dove vengono salvati i backup

Per default PHP usa una directory sorella di `public_html`:

```text
<home-account>/decempionz-runtime-backups/
```

Quindi i file non sono raggiungibili via HTTP e non vengono toccati dal deploy FTP.

La directory può essere sovrascritta, soprattutto per test o hosting particolari, tramite la variabile ambiente `DCZ_BACKUP_DIR`.

Se l'hosting non consente la scrittura nella directory padre di `public_html`, il gioco continua a funzionare e PHP registra l'errore nel log: il problema va però risolto prima di considerare i backup operativi.

## Politica di snapshot e retention

Lo snapshot avviene **prima** della modifica del JSON corrente e solo se il contenuto esistente è JSON valido.

Default:
- massimo 30 versioni per file/logical key;
- massimo 90 giorni;
- Daily/Weekly possono usare limiti più stretti impostati dal writer.

I nomi snapshot sono timestamp UTC + suffisso casuale, per evitare collisioni con richieste concorrenti.

## Verifica da terminale hosting

Dalla directory `public_html`:

```bash
php runtime-recovery.php health
php runtime-recovery.php list
php runtime-recovery.php list hall-of-fame main
php runtime-recovery.php list daily 2026-09-17
```

`health` deve riportare `Writable: yes`.

## Verifica snapshot

Prima di qualsiasi restore:

```bash
php runtime-recovery.php verify hall-of-fame main NOME_SNAPSHOT.json
```

Esempi di categorie/logical name:
- `hall-of-fame main`
- `global-stats main`
- `game-counter main`
- `daily 2026-09-17`
- `challenge 2026-W38`

## Restore

Il restore è volutamente CLI-only e richiede `--confirm`:

```bash
php runtime-recovery.php restore hall-of-fame main NOME_SNAPSHOT.json --confirm
```

Prima di sostituire il target, lo strumento prova a creare uno snapshot dello stato corrente. Il file ripristinato viene scritto tramite file temporaneo e rename, evitando di lasciare un target parzialmente scritto.

## Procedura consigliata in caso di corruzione

1. Non cancellare il file corrente.
2. Eseguire `health` e `list`.
3. Scegliere uno snapshot precedente al problema.
4. Eseguire `verify`.
5. Se possibile, copiare snapshot e file corrente in una directory di test e confrontarli.
6. Eseguire `restore ... --confirm` solo dopo la verifica.
7. Controllare l'endpoint/pagina interessata.
8. Conservare lo snapshot pre-restore finché il problema non è chiuso.

## Sicurezza

- `runtime-recovery.php` rifiuta l'esecuzione via web e funziona solo con PHP CLI.
- `runtime-backup-lib.php` e `runtime-recovery.php` sono inoltre bloccati da `.htaccess` per accesso HTTP diretto.
- I backup non contengono `hof-config.php`, `challenge-config.json` o altri segreti/configurazioni.
- I backup non vengono salvati nel repository GitHub.

## Test automatici

La CI esegue `scripts/test_runtime_backup.php`, che verifica:
- creazione snapshot;
- retention per numero versioni;
- health della directory;
- rifiuto di JSON invalidi.
