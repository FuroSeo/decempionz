# Decempionz — Developer Manual

**Version:** 5.6.2
**Last updated:** 2026-06-29
**File:** `index.html` (single-file game, ~6200 lines)
**Live:** [decempionz.com](https://decempionz.com) (GitHub Pages, custom domain)
**Repo:** [github.com/FuroSeo/decempionz](https://github.com/FuroSeo/decempionz)

---

## Version History

| Version | Date | Notes |
|---------|------|-------|
| 5.6.2 | 2026-06-29 | Animated journey replay su tutte le end screen; separatore gironi/KO nel percorso; `_animateJourney()` helper; GAME_VERSION ora aggiornato direttamente in index.html (non solo in sw.js) |
| 5.6.1 | 2026-06-29 | Fix bandiere WC: `nat` iniettato nell'entry dal `t.country` del team; `natFlag()` ora supporta emoji pass-through (codici >2 chars); fix `pen.shootout_title` i18n (IT: Rigori, EN: Penalty Shootout, ES: Tanda de Penaltis); fix groupMax classic=3 nel bracket; avversario corrente mostrato nel chip GRP |
| 5.6.0 | 2026-06-29 | `natFlag()` helper (bandiere emoji in draft card e pitch slot); bracket visuale campagna `renderCampaignBracket()` tra "Prossima Partita" e "Statistiche"; fix rose (LB/RB mancanti in val_0001, riv_9596, vas_9899, pen_6061, pal_9900, boc_0304; +2 giocatori a oli_9091, cal_0304, sao_0506, arj_8485, ldu_0708, atl_1617); aggiornamento conteggi rose in copa.html (46), ucl.html (74), worldcup.html (79) |
| 5.5.55 | 2026-06-28 | Fix howto.p4 IT/EN/ES: descrizione draft aggiornata al sistema slot-type |
| 5.5.54 | 2026-06-28 | Draft: GK reserve — GK escluso dai target finché ci sono ≥3 altri tipi feasibili |
| 5.5.53 | 2026-06-28 | Draft: pre-filtro tipi feasibili |
| 5.5.52 | 2026-06-28 | Draft: fix break condition nella selezione per tipo slot |
| 5.5.51 | 2026-06-28 | Draft: tier fallback per tipo slot |
| 5.5.50 | 2026-06-28 | Draft v2: sistema slot-type selection — 1 carta per tipo di slot rimasto unico, no-return su scarto, GK cap → 4 |
| 5.5.48 | 2026-06-27 | Fix display nome squadra in knockout |
| 5.5.29 | 2026-06-25 | Penalità posizionale in simulazione, bonus r=10, badge ⚠️/⚡/🛡️ |
| 5.5.24 | 2026-06-24 | Fix Copa/WC coppa maxKnock=4; fix group stage elimination off-by-one |
| 5.5.19 | 2026-06-22 | Fix banner "reroll esauriti" i18n; auto-hide log partita |
| 5.5.18 | 2026-06-22 | **RELEASE** Tier draft system; sitemap multi-pagina; pagine torneo aggiornate |
| 5.5.12 | 2026-06-22 | Documentario campagna algoritmico IT/EN/ES |
| 5.5.11 | 2026-06-22 | Commento post-partita dinamico |
| 5.5.9  | 2026-06-22 | Draft Random: 11ª card formazione 🎲 |
| 5.3.0  | 2026-06-20 | Lingua spagnola (ES) completa |
| 5.2.0 | 2026-06-17 | **RELEASE** Sistema trofei 15 achievement, screen-trophies, about.html |
| 5.1.0-dev | 2026-06-17 | World Cup Legends completo (79 nazionali) |
| 5.0.0-dev | 2026-06-17 | Copa Libertadores completo (46 rose) |
| 4.9.5 | 2026-06-15 | PWA: manifest + service worker |
| 4.9.3 | 2026-06-15 | Hall of Fame: schermata + fetch JSON da GitHub |
| 4.8.x | 2026-06-13 | Endgame screen redesign; pitch SVG; sprite system |
| 4.6.x | 2026-06-12 | Coach system; quick-start; formazioni 3-tactic |
| 4.2.0 | 2026-06-10 | i18n system: STRINGS, t(), toggleLang() |
| 4.1.0 | 2026-06-10 | Versioning, sprite graphics, Dev Panel |
| 1.0–4.0 | 2026 | Draft, formazioni, gironi, knockout, light/dark theme, rebranding |

---

## Architecture

Single HTML file. No build step, no dependencies, no backend. All game logic is vanilla JS inside a `<script>` block. CSS is inlined in `<style>`. Game state is held in two global objects: `G` (campaign) and `M` (active match).

**Key globals:**

| Variable | Type | Purpose |
|----------|------|---------|
| `G` | Object | Persistent campaign state (squad, coach, results, momentum, format, gameMode…) |
| `M` | Object | Active match state (scores, log, xG, penalties…) |
| `P` | Object | Penalty shootout state |
| `_pendingMatch` | Object | Tactic + opponent data bridging `playRound()` → `_runMatch()` |

**Tournament routing — `G.gameMode`:**

| Value | Tournament | Dataset | Squads | Opps |
|-------|-----------|---------|--------|------|
| `'ucl'` | UCL Legends | `TEAMS` (74 rose, 29 club) | 74 | `KNOCKOUT_OPPS` / `COPPA_OPPS` |
| `'copa'` | Copa Libertadores | `COPA_TEAMS` (46 rose) | 46 | `COPA_KNOCKOUT_OPPS` / `COPA_COPPA_OPPS` |
| `'wc'` | World Cup Legends | `WC_TEAMS` (79 nazionali) | 79 | `WC_KNOCKOUT_OPPS` / `WC_COPPA_OPPS` |

`_activeTeams()` e `_activeJerseyColors()` leggono `G.gameMode` e restituiscono il dataset corretto. Usare **sempre** queste funzioni, mai `TEAMS` direttamente.

`G.format` determina la struttura interna: `'classic'` (gironi+KO), `'coppa'` (solo KO), `'nuovo'` (lega+KO, solo UCL).

**Trophy system — `localStorage`:**

| Key | Valore | Scopo |
|-----|--------|-------|
| `dcz_trophies` | JSON object `{id: {unlocked, date}}` | 15 achievement personali |
| `dcz_formations_won` | JSON array di stringhe | Formazioni con cui si è vinto (per Formation Master) |

`checkTrophies(G)` — chiamata da `showTrophy()` — verifica tutte le condizioni, salva i nuovi unlock, mostra toast dorato.

---

## Game Flow

```
Home → Format Picker → Era Picker (optional) → Formation/Difficulty
     → Draft (11 players) → Coach Draft → [Match loop] → Trophy / Elimination
```

**Format determines structure:**

| Format | Era pool | Phase structure | Matches |
|--------|----------|-----------------|---------|
| Old Cup (coppa) | 1956–1992 | Pure knockout da R1 | 5 |
| Classic | 1992–2024 | Group (3) + Knockout (4) | 7 |
| New Format (nuovo) | 1992–2024 | League phase (6) + Knockout (4) | 10 |

**Quick-start path:** "Gioca subito" button on home sets defaults (Classic, random era, 4-3-3, Normal difficulty) and skips directly to the draft.

---

## Player Data Model

Every player in `TEAMS[teamId].players[]`:

```js
// UCL / Copa
{ n: 'Futre', p: 'RW', r: 10, nat: 'PT' }

// World Cup (nat assente nel dataset raw — viene iniettato al draft time)
{ n: 'Pelé', p: 'ST', r: 10 }
```

**Campo `nat`:** codice ISO-2 maiuscolo (es. `'IT'`, `'AR'`, `'BR'`). Eccezioni speciali gestite in `natFlag()`: `EN` → 🏴󠁧󠁢󠁥󠁮󠁧󠁿, `SC` → 🏴󠁧󠁢󠁳󠁣󠁴󠁿, `WLS` → 🏴󠁧󠁢󠁷󠁬󠁳󠁿, `NIR` → 🇬🇧. Per le squadre WC il `nat` non è presente sui singoli giocatori — viene iniettato al momento della costruzione dell'entry di draft come `nat: p.nat || t.country` dove `t.country` è già l'emoji (es. `'🇧🇷'`).

**`natFlag(code)`** — converte il codice in emoji bandiera:
- Stringa vuota / undefined → `''`
- Codici speciali (`EN`, `SC`, `WLS`, `NIR`) → bandiera regionale
- Codice ISO-2 standard → `String.fromCodePoint` con offset regionale
- Stringa >2 char (emoji già formata, es. dal WC `t.country`) → pass-through diretta

At draft time players are enriched with `club` (team name), `teamId` and `nat`. UCL dataset: **74 teams / 29 clubs**. Copa: **46 teams**. WC: **79 nazionali**.

**Position groups** (used for strength calculation):

| Group | Positions |
|-------|-----------|
| GK | GK |
| DEF | CB, RB, LB |
| MID | CM, CDM, CAM, RM, LM |
| FWD | ST, CF, SS, RW, LW, RWB, LWB |

**Rating tiers:**

| Tier | Rating | CSS Class | Label |
|------|--------|-----------|-------|
| ★ Legendary | ≥ 9.5 | `tier-elite` | ★ prefix |
| Gold | ≥ 9.0 | `tier-high` | — |
| Silver | ≥ 8.5 | `tier-mid` | — |
| Bronze | < 8.5 | `tier-low` | — |

---

## Draft System

### Pool Construction (`initDraft`)

Per ogni team nell'era pool:
- 65% chance top-rated player è il pick garantito; 35% il secondo (varietà senza perdere qualità)
- Fino a 3 giocatori random dal resto
- Flatten, dedup per nome (versione con rating più alto mantenuta)
- **GK cap:** solo i 4 GK con rating più alto
- **`nat` injection:** ogni `entry` viene costruita come `{...p, club: t.name, teamId: id, nat: p.nat || (t.country || '')}` — così i giocatori WC ereditano automaticamente la bandiera emoji dal team

Shuffle del pool completo, poi partizionato in `G.draftTiers`:

| Tier | Rating | Weight |
|------|--------|--------|
| 10 (Elite) | r = 10 | 3 |
| 9 (High) | r = 9 | 2 |
| 8 (Mid) | r = 8 | 2 |
| 7 (Base) | r ≤ 7 | 1 |

### Draft Loop (Slot-Type System, v5.5.50+)

```
drawDraftCards()
  ↓ (1 card per unique remaining slot type, up to 3)
renderThreeCards()       ← mostra natFlag(p.nat) sotto il nome
  ↓ (user taps one)
draftPick(i)
  ↓ (fills best slot, discards unchosen — no return to pool)
repeat until filledCount() === 11
  ↓
finalizeDraft() → 1.5s delay → showCoachDraft()
  ↓
pickCoach(i) → setupCampaign() → showCampaign()
```

### Reroll Block

Normal/Hard: bloccato quando ≤ 3 slot vuoti. Easy: sempre libero.

```js
DIFF_CFG = {
  easy:   { passes: 5, oppMod: -0.20 },
  normal: { passes: 3, oppMod:  0.15 },
  hard:   { passes: 2, oppMod:  0.40 },
}
```

### Slot Compatibility (`SLOT_COMPAT`)

```
GK → [GK]     CB → [CB,LB,RB,LWB,RWB]   RB → [RB,RWB,LB,LWB,CB]
LB → [LB,LWB,RB,RWB,CB]   CDM → [CDM,CM,CB]   CM → [CM,CDM]
CAM → [CAM,SS,RW,LW]   RM → [RM,LM,RW,LW]   LM → [LM,RM,LW,RW]
RW → [RW,LW,RM,LM,CAM,SS]   LW → [LW,RW,LM,RM,CAM,SS]
ST → [ST,CF,SS]   CF → [CF,ST,SS]   SS → [SS,ST,CF,CAM,RW,LW]
```

### Positional Penalty (`slotPenalty`)

| Distance | Example | Penalty |
|----------|---------|---------|
| Exact | CB → CB slot | 0 |
| Adjacent (same group) | RB → CB slot | −0.05 |
| Cross-group (tactical) | CDM → CB slot | −0.10 |
| Major mismatch | ST → CB slot | −0.25 |

⚠️ badge su draft card se `slotPenalty > 0`. La bandiera `natFlag(p.nat)` appare sotto il nome su ogni carta draft e su ogni slot riempito nel pitch.

---

## Campaign Bracket Panel (v5.6.0+)

`renderCampaignBracket()` — chiamata da `showResult()` dopo ogni partita campagna, popola `#m-bracket-panel` (posizionato tra la sezione "Prossima Partita" e il pulsante "Statistiche" in `screen-match`).

**Logica stato per ogni step:**
- `'done'` — `G.knockResults.length > i` → mostra risultato con colore verde/rosso
- `'current'` — `G.phase==='knockout' && i===G.knockRound` OPPURE `gDone && G.phase==='group' && i===0` (R16 evidenziato subito dopo l'ultima partita di gruppo)
- `'future'` — default, dimmed

**Struttura round per formato:**

| Format | Rounds | Abbreviazioni |
|--------|--------|---------------|
| Classic/Nuovo | R16, QF, SF, F | R16 QF SF F |
| Coppa UCL | T1, T2, QF, SF, F | 5 step |
| Coppa Copa/WC | T1, QF, SF, F | 4 step |

Il chip **GRP** (fase a gironi, solo Classic/Nuovo) mostra:
- In corso: `played/max` + nome avversario corrente da `G.groupTeams[G.groupRound].team.name`
- Completato: punti totali + record V/P/S
- `groupMax`: classic = 3, nuovo = 6

---

## Animated Journey Replay (v5.6.2+)

`_animateJourney(elId)` — helper globale che staggera la comparsa delle righe `.end-j-row` con un delay di 280ms per riga (slide-in da sinistra via CSS).

Chiamata da:
- `showGroupElimination()` — dopo aver settato `#go-journey` innerHTML
- `showGameOver()` — idem
- `showTrophy()` — dopo aver settato `#t-journey` innerHTML

**CSS:** `.end-j-row` parte con `opacity:0; transform:translateX(-12px)`. La classe `.ej-vis` (aggiunta con delay da `_animateJourney`) porta a `opacity:1; transform:translateX(0)`.

**Separatore gironi/KO:** `<div class="end-j-sep">` inserito tra `gRows` e `kRows` quando entrambi sono non-vuoti, in tutte e tre le funzioni end screen.

---

## Penalty Shootout i18n (v5.6.1+)

Il titolo della schermata rigori usa ora `data-i18n="pen.shootout_title"` invece della stringa hardcoded `⚽ PENALTY SHOOTOUT`.

| Lingua | Valore |
|--------|--------|
| IT | `⚽ Rigori` |
| EN | `⚽ Penalty Shootout` |
| ES | `⚽ Tanda de Penaltis` |

---

## Formation System

10 formazioni, ognuna con 3 varianti tactic (`attack`, `balanced`, `defend`).

**Available formations:** 4-3-3, 4-4-2, 4-2-3-1, 3-5-2, 4-1-4-1, 3-4-3, 5-3-2, 4-5-1, 5-4-1, 3-6-1

`fmtPositions(formation, tactic)` → flat array of 11 position strings
`fmtRows(formation, tactic)` → array of rows (GK→FWD)

---

## Sprite System

| State | Function | Visual |
|-------|----------|--------|
| Empty | `ghostSprite()` | Translucent grey silhouette |
| Target | `targetSprite(grp)` | Colored silhouette + pulse |
| Filled | `filledSlotHTML(player, grp)` | Jersey-colored sprite + nome + rating + `natFlag(player.nat)` |

`filledSlotHTML` mostra la bandiera sotto il nome del giocatore (font-size `.6rem`). Nelle draft card (`renderThreeCards`) la bandiera appare sotto il nome solo se non in blind mode.

---

## Coach System

3 random coach cards da `COACHES` (30 allenatori storici). No rerolls.

**xG Boost:**

| Matching lines | Boost |
|---------------|-------|
| 3/3 | ×1.08 |
| 2/3 | ×1.05 |
| 1/3 | ×1.02 |
| 0/3 | ×1.00 |

---

## Match Simulation

### Opponent Strength
```
effectiveOppStr = max(5, rawStr + diffCfg.oppMod + roundBonus)
```
Round bonus (knockout): QF +0.25 · SF +0.55 · Final +0.90

### xG Calculation
```
myXG  = max(0.13, ((atk  - oppStr × 0.84) × 0.36 + 0.56) × tactMod.myXG  × counterMod[0])
oppXG = max(0.10, ((oppStr - def × 0.80) × 0.42 + 0.32)  × tactMod.oppXG × counterMod[1])
```
Star bonus · Momentum · Coach boost · Draw-pull

### Tactic System

**TACT_MOD:** attack (myXG ×1.10, oppXG ×1.08) · balanced (×1.00) · defend (myXG ×0.92, oppXG ×0.88)

**COUNTER_MOD `[myXGmul, oppXGmul]`:**

| My \ Opp | attack | balanced | defend |
|----------|--------|----------|--------|
| attack | [1.00, 1.00] | [0.90, 1.08] | [1.12, 0.88] |
| balanced | [1.08, 0.90] | [1.00, 1.00] | [0.90, 1.06] |
| defend | [1.06, 0.88] | [1.04, 0.90] | [1.00, 1.00] |

### Momentum
WIN +1 · LOSS −1 · DRAW no change. Clamped −3/+3.

### Penalty Shootout
`P(score) = 0.76` home / `0.73` away. `startPenalties()` anima kick-by-kick. Skip con ⏭ → `_simPenaltiesInstant()`.

---

## Tournament Formats

| Format | Gironi | Knockout | Totale partite |
|--------|--------|----------|----------------|
| Old Cup (coppa) | — | 5 round | 5 |
| Classic | 3 match | 4 round | 7 |
| New Format (nuovo) | 6 match (≥8pt per qualificarsi) | 4 round | 10 |

---

## End Screens

### screen-gameover

Popolato da `showGroupElimination(pos, pts)` (eliminazione gironi) e `showGameOver()` (eliminazione KO).

**Key IDs:** `go-round-lbl`, `go-sub-lbl`, `go-sc`, `go-stats`, `go-journey`, `go-extra`, `go-pitch`, `go-documentary`

### screen-trophy

Popolato da `showTrophy()`.

**Key IDs:** `t-pitch`, `t-journey`, `t-confetti`

### Journey Section (v5.6.2+)

In entrambe le end screen il percorso mostra le partite con slide-in animato (280ms/riga). Separatore visivo `end-j-sep` tra gironi e KO. `_animateJourney(elId)` è il trigger.

---

## Scoring & Stars

| Result | Stars |
|--------|-------|
| Win by 2+ goals | ⭐⭐⭐ |
| Win by 1 goal | ⭐⭐ |
| Draw (group) / Win on penalties | ⭐ |
| Loss / eliminated | 0 |

---

## Campaign Stats

| Field | Meaning |
|-------|---------|
| `G.campaignGF` / `G.campaignGA` | Gol totali per/contro |
| `G.campaignCleanSheets` | Partite senza subire gol |
| `G.campaignBestWin` | Margine vittoria massimo |
| `G.campaignScorers` | Giocatore → conteggio gol |
| `G.campaignMatchRatings` | Snapshot rating per partita |
| `G.coach` | `{name, pref, compat, boost}` |

---

## Internationalisation (i18n)

Default: **Italian**. Toggle via flag button → cicla IT → EN → ES.

```js
const STRINGS = { it: {...}, en: {...}, es: {...} }
function t(key) { return STRINGS[G.lang || 'it'][key] || STRINGS.it[key] || key }
```

`applyLang()` aggiorna tutti i `data-i18n` e `data-i18n-html`. Lingua in `G.lang` e `localStorage['dcz_lang']`.

---

## Post-Match Commentary (v5.5.11+)

`buildMatchComment(result, isKnockout)` — testo algoritmico contestuale (win/loss/draw, margine, clean sheet, rigori, momentum, gruppo/KO). Trilingual.

---

## Campaign Documentary (v5.5.12+)

`buildCampaignDocumentary(G)` — narrativa multi-paragrafo generata dai dati reali della campagna. Visibile su trophy screen e gameover screen.

---

## Tournament Pages

| File | Tournament | Rose |
|------|-----------|------|
| `ucl.html` | UCL Legends | 74 rose, 29 club |
| `copa.html` | Copa Libertadores | 46 rose |
| `worldcup.html` | World Cup Legends | 79 nazionali |

Ogni pagina è trilingue (IT/EN/ES), linked dalla home e dal footer.

---

## Dev Panel

Triggered by **5 rapid clicks** sul numero di versione (bottom home).

| Feature | Function |
|---------|----------|
| Quick Draft — Top XI | `_devQuickDraft('top')` |
| Quick Draft — Random XI | `_devQuickDraft('random')` |
| Jump to Round | `_devRenderJumpPanel()` — dinamico per gameMode+format |
| Force Score | `_DEV_FORCE_SCORE = [my, opp]` |
| Stress Test | `_devStressTest()` |
| Mock Result | `_devMockResult()` |
| Mock Trophy | `_devMockTrophy()` |
| Mock GameOver | `_devMockGameOver()` |

---

## Versioning

```js
const GAME_VERSION = '5.6.2';  // aggiornato sia in index.html che in sw.js
```

`sw.js`: `const CACHE = 'decempionz-v5.6.2'`

**Regola versione:** aggiornare **sempre entrambi** i file (index.html + sw.js) ad ogni push. `_push.bat` sincronizza GAME_VERSION da sw.js al repo durante il push, ma index.html locale deve già avere la versione corretta per verifiche pre-push.

Displayed via `<span class="gv"></span>`. 5× rapid clicks → dev panel.

**Bump rules:** patch (bug fix / tweak) · minor (nuova meccanica / schermata) · major (redesign)

---

## Development Workflow

```bash
# CRITICO — node --check DEVE usare il blocco JS più grande (non il primo)
python3 -c "
import re
with open('index.html') as f: html=f.read()
blocks=re.findall(r'<script[^>]*>([\s\S]*?)</script>', html)
main=max(blocks, key=len)   # il primo blocco è Google Analytics!
open('/tmp/check.js','w').write(main)
" && node --check /tmp/check.js && echo SYNTAX OK

# Tutte le edits JS via Python str.replace() — mai Edit tool direttamente su index.html
```

**Regole:** sempre Python str.replace() · sempre `node --check` con `max(blocks, key=len)` · aggiornare GAME_VERSION in index.html E sw.js · usare `_push.bat` per il deploy.

**Cache:** GitHub Pages caches aggressively. Hard-refresh (Ctrl+Shift+R) o `?v=N` per bypassare.

---

## File Structure (repo)

```
decempionz/
├── index.html              ← intero gioco (~6200 lines)
├── about.html              ← pagina SEO statica (trilingue IT/EN/ES)
├── ucl.html                ← torneo UCL Legends — 74 rose (IT/EN/ES)
├── copa.html               ← torneo Copa Libertadores — 46 rose (IT/EN/ES)
├── worldcup.html           ← torneo World Cup Legends — 79 nazionali (IT/EN/ES)
├── sw.js                   ← service worker PWA (version string: decempionz-vX.Y.Z)
├── _push.bat               ← script deploy: clone → copia files → sync GAME_VERSION → push
├── GAME_MANUAL.md          ← questo file
├── manifest.json           ← PWA manifest
├── hof-admin.php           ← admin Hall of Fame
├── hof-submit.php          ← submit HoF (ritorna id)
├── hof-status.php          ← status HoF
├── draft-save.php          ← salvataggio draft
├── game-counter.php        ← contatore partite
├── challenge-config.json   ← configurazione sfide settimanali
├── challenge-config.php    ← lettura config sfida corrente
├── challenge-submit.php    ← submit punteggio sfida
├── challenge-scores.php    ← classifica sfida
├── sfide.html              ← pagina sfide settimanali
├── draft.html              ← viewer draft salvati
├── sitemap.xml             ← sitemap multi-pagina
└── .github/workflows/deploy.yml
```
