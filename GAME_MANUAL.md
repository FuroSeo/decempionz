# Decempionz — Developer Manual

**Version:** 5.2.0
**Last updated:** 2026-06-17
**File:** `index.html` (single-file game, ~5900 lines)
**Live:** [decempionz.com](https://decempionz.com) (GitHub Pages, custom domain)
**Repo:** [github.com/FuroSeo/decempionz](https://github.com/FuroSeo/decempionz)

---

## Version History

| Version | Date | Notes |
|---------|------|-------|
| 5.2.0 | 2026-06-17 | **RELEASE** Sistema trofei 15 achievement (localStorage), screen-trophies, about.html SEO, fix fine file troncata |
| 5.1.x-dev | 2026-06-17 | Fix dev panel: Jump to Round dinamico per gameMode/format; Copa/WC Classic con gironi; _devSetMode non forza più coppa |
| 5.1.0-dev | 2026-06-17 | World Cup Legends completo (65 nazionali, 4 ere 1930–2022, WC_TEAMS, WC_JERSEY_COLORS, routing gameMode=wc) |
| 5.0.x-dev | 2026-06-17 | Fix UI: nomi slot — wrap 2 righe; light mode slot opachi; fix WC country prefix rimosso; logo più grande; HoF form + i18n placeholder |
| 5.0.0-dev | 2026-06-17 | Copa Libertadores completo (24 rose, 4 ere 1960–2024, COPA_TEAMS, routing gameMode=copa, tema verde, i18n) |
| 4.9.9 | 2026-06-16 | Fix: ripristino share modal HTML rimosso in 4.9.7 |
| 4.9.8 | 2026-06-16 | Fix top scorer bug: safe pick + use ev.scorer directly |
| 4.9.5 | 2026-06-15 | PWA: web app manifest + service worker; installabile da smartphone |
| 4.9.4 | 2026-06-15 | Fix: Hall of Fame fetch puntava a `master` invece di `main` |
| 4.9.3 | 2026-06-15 | Hall of Fame: schermata + fetch JSON da GitHub; social share canvas |
| 4.9.0 | 2026-06-15 | Dataset audit: riduzione ★10 da 77 a 31; solo valutazioni intere |
| 4.8.x | 2026-06-13 | Endgame screen redesign; pitch SVG; sprite system; vari fix |
| 4.6.x | 2026-06-12 | Coach system; quick-start; formazioni 3-tactic; modal Fan Project/Privacy |
| 4.2.0 | 2026-06-10 | i18n system: STRINGS, t(), toggleLang(), data-i18n |
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

| Value | Tournament | Dataset | Opps |
|-------|-----------|---------|------|
| `'ucl'` | UCL Legends | `TEAMS` (66 rose) | `KNOCKOUT_OPPS` / `COPPA_OPPS` |
| `'copa'` | Copa Libertadores | `COPA_TEAMS` (24 rose) | `COPA_KNOCKOUT_OPPS` / `COPA_COPPA_OPPS` |
| `'wc'` | World Cup Legends | `WC_TEAMS` (65 nazionali) | `WC_KNOCKOUT_OPPS` / `WC_COPPA_OPPS` |

`_activeTeams()` e `_activeJerseyColors()` leggono `G.gameMode` e restituiscono il dataset corretto. Usare **sempre** queste funzioni, mai `TEAMS` direttamente.

`G.format` determina la struttura interna: `'classic'` (gironi+KO), `'coppa'` (solo KO), `'nouveau'` (lega+KO, solo UCL).

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
| Old Cup (coppa) | 1956–1992 | Pure knockout from R1 | 5 |
| Classic | 1992–2024 | Group (3) + Knockout (4) | 7 |
| New Format (nuovo) | 1992–2024 | League phase (6) + Knockout (4) | 10 |

**Quick-start path:** "Gioca subito" button on home sets defaults (Classic, random era, 4-3-3, Normal difficulty) and skips directly to the draft.

---

## Player Data Model

Every player in `TEAMS[teamId].players[]`:

```js
{ n: 'Futre', p: 'RW', r: 10 }
```

At draft time players are enriched with `club` (team name) and `teamId` (key into `TEAMS`). There are **66 teams** in the dataset.

**Position groups** (used for strength calculation):

| Group | Positions |
|-------|-----------|
| GK | GK |
| DEF | CB, RB, LB |
| MID | CM, CDM, CAM, RM, LM |
| FWD | ST, CF, SS, RW, LW, RWB, LWB |

`posGroup()` handles the mapping. `pgClass()` returns the CSS class for badge color.

**Rating tiers** (visual + xG bonus):

| Tier | Rating | CSS Class | Border | Label |
|------|--------|-----------|--------|-------|
| ★ Legendary | ≥ 9.5 | `tier-elite` | Gold solid + glow | ★ prefix |
| Gold | ≥ 9.0 | `tier-high` | Gold thin | — |
| Silver | ≥ 8.5 | `tier-mid` | Grey | — |
| Bronze | < 8.5 | `tier-low` | Tan | — |

`slotTierCss(r)` returns border, shadow and animation only (no background — theme-safe).
`slotTierClass(r)` returns the CSS class (`tier-elite` / `tier-high` / `tier-mid` / `tier-low`) added to the slot element. Backgrounds are defined in CSS with `[data-theme]` overrides and update automatically on theme switch with no re-render needed.

**Jersey colors:** `JERSEY_COLORS[teamId]` → hex color for the Sensible Soccer-style sprite.

---

## Draft System

### Pool Construction (`initDraft`)

For each team in the era pool:
- Sort players by rating descending
- Take the top-rated player guaranteed
- Take up to 4 random players from the rest
- Flatten and shuffle → `G.draftPool[]`
- `G.draftIdx` advances through the pool; never resets mid-draft

### 3-Card Pick Loop

```
drawDraftCards()
  ↓ (takes next 3 compatible cards from pool)
renderThreeCards()
  ↓ (user taps one)
draftPick(i)
  ↓ (fills best slot, draws next 3)
repeat until filledCount() === 11
  ↓
finalizeDraft() → 1.5s delay → showCoachDraft()
  ↓ (user picks coach)
pickCoach(i) → setupCampaign() → showCampaign()
```

`drawDraftCards()` only adds a card if `compatibleEmptySlots(p).length > 0`. The hand may have 1–2 cards if fewer compatible cards remain.

**Reroll:** `draftReroll()` discards current 3 cards and redraws. Costs 1 from `G.passes`.

```js
DIFF_CFG = {
  easy:   { passes: 5, oppMod: -0.20 },
  normal: { passes: 3, oppMod:  0.15 },
  hard:   { passes: 2, oppMod:  0.40 },
}
```

### Slot Compatibility (`SLOT_COMPAT`)

```
GK  → [GK]
CB  → [CB, RB, LB]
RB  → [RB, LB, CB]
LB  → [LB, RB, CB]
CDM → [CDM, CM]
CM  → [CM, CDM, CAM, RM, LM]
CAM → [CAM, CM, SS]
RM  → [RM, LM]          ← separated from wingers
LM  → [LM, RM]
RW  → [RW, LW, CAM]
LW  → [LW, RW, CAM]
ST  → [ST, CF, SS]
CF  → [CF, ST, SS]
SS  → [SS, ST, CF, CAM]
RWB → [RWB, RB, LB, LWB]
LWB → [LWB, LB, RB, RWB]
```

`draftPick(i)` sorts candidates by preference (exact match first, same group second) and fills the best slot.

### Finalization

If the pool is exhausted before 11 players are filled, `finalizeDraft()` force-fills remaining slots from the pool. Squad stored in `G.squad[]`.

---

## Formation System

10 formations, each with 3 tactic variants (`attack`, `balanced`, `defend`). Each variant has a `positions` array (11 slots) and a `rows` array for pitch rendering.

**Available formations:** 4-3-3, 4-4-2, 4-2-3-1, 3-5-2, 4-1-4-1, 3-4-3, 5-3-2, 4-5-1, 5-4-1, 3-6-1

`fmtPositions(formation, tactic)` → flat array of 11 position strings
`fmtRows(formation, tactic)` → array of rows (GK→FWD), used by pitch renderers

`G.tactic` = active tactic (set in draft screen). `G.draftTactic` = snapshot at draft time, used by end-screen pitch renderers to prevent formation mismatch if tactic changes mid-campaign.

---

## Pitch Rendering

### Draft Screen (`renderDraftPitch`)

Renders the live formation grid during draft. Slot states: empty (ghost sprite), target (pulsing, compatible with current card), filled (`filledSlotHTML`).

### End Screen Pitches

`renderGameOverPitch()` — renders filled formation on `#go-pitch` (game over / group elimination).
`renderTrophyPitch()` — renders filled formation on `#t-pitch` (trophy screen).

Both read from `G.slotPlayers` and `G.draftTactic`. Called on screen show and by `toggleTheme()`.

### SVG Field Lines (`injectPitchLines(el)`)

Injects a DOM SVG element (class `pitch-svg`, `z-index:0`) as first child of any `.draft-pitch` container. Draws center circle, two penalty areas, two six-yard boxes, center spot, two penalty spots. Stroke color is theme-aware (reads `data-theme` at call time). Called by `renderDraftPitch`, `renderGameOverPitch`, `renderTrophyPitch`, and `toggleTheme`.

---

## Sprite System

| State | Function | Visual |
|-------|----------|--------|
| Empty | `ghostSprite()` | Translucent grey silhouette |
| Target | `targetSprite(grp)` | Colored silhouette (group color) + pulse animation |
| Filled | `filledSlotHTML(player, grp)` | Jersey-colored sprite + name + rating |

`filledSlotHTML` adds both `tier-*` class (CSS background) and inline `slotTierCss()` (border/shadow/animation). Name color is CSS-only — `#eef2ff` dark / `#111827` light — so no re-render needed on theme switch.

---

## Coach System

### Coach Draft (`showCoachDraft`)

3 random coach cards from `COACHES` (30 historical managers) after the 11th pick. No rerolls.

```js
{ n: 'Pep Guardiola', pref: '4-3-3' }
```

### Formation Matching

`formDMA(key)` → `{d, m, a}`: d = defenders, m = sum of midfield numbers, a = attackers.
`coachCompat(coachPref, chosenForm)` → count of matching lines (0–3).

### xG Boost

| Matching lines | Boost |
|---------------|-------|
| 3/3 | ×1.08 |
| 2/3 | ×1.05 |
| 1/3 | ×1.02 |
| 0/3 | ×1.00 |

`G.coach` = `{name, pref, compat, boost}`. Reset to `null` on `applyTournament()`.

---

## Match Simulation

### Opponent Strength

```
effectiveOppStr = max(5, rawStr + diffCfg.oppMod + roundBonus)
```

Round bonus (knockout): QF +0.25 · SF +0.55 · Final +0.90

### My Team Strength

```
atk = avg(FWD) × 0.65 + avg(MID) × 0.35
def = avg(DEF) × 0.70 + avg(GK)  × 0.30
```

### xG Calculation

```
myXG  = max(0.13, ((atk  - oppStr × 0.84) × 0.36 + 0.56) × tactMod.myXG  × counterMod[0])
oppXG = max(0.10, ((oppStr - def × 0.80) × 0.42 + 0.32)  × tactMod.oppXG × counterMod[1])
```

Star bonus: `max(0, (topRating - 8.5) × 0.06)` added to myXG (cap 2.6)
Momentum: `myXG += G.momentum × 0.03`; `oppXG -= G.momentum × 0.03 × 0.35`
Coach boost: `myXG = min(myXG × coachBoostMul, 2.6)`
Draw-pull: if `|myXG - oppXG| < 0.35`, both pulled toward each other slightly

### Goal Simulation

Poisson distribution. `myG = poisson(myXG)`, `oppG = poisson(oppXG)`.

### Tactic System

| Key | IT | EN |
|-----|----|----|
| `attack` | Pressione Alta | High Press |
| `balanced` | Possesso Palla | Possession |
| `defend` | Blocco Basso | Low Block |

**TACT_MOD:** attack (myXG ×1.10, oppXG ×1.08) · balanced (×1.00) · defend (myXG ×0.92, oppXG ×0.88)

**COUNTER_MOD `[myXGmul, oppXGmul]`:**

| My \ Opp | attack | balanced | defend |
|----------|--------|----------|--------|
| attack | [1.00, 1.00] | [0.90, 1.08] | [1.12, 0.88] |
| balanced | [1.08, 0.90] | [1.00, 1.00] | [0.90, 1.06] |
| defend | [1.06, 0.88] | [1.04, 0.90] | [1.00, 1.00] |

**`_diffLabel()`** — returns difficulty string in current language. Used on end screens.

### Match Log (`buildMatchLog`)

Builds event array for `runLog()`. Every event has a `min` property so the timer updates at each event. Final event is always `min: 90`.

### Momentum

WIN +1 · LOSS −1 · DRAW no change. Clamped to −3 / +3. Carries across all campaign matches.

### Penalty Shootout

`P(score) = 0.76` home / `0.73` away per tutti i calci, inclusi i supplementari (sudden death).

`startPenalties()` anima la sequenza kick-by-kick. `finishPenalties()` setta `M.penResult = {homeWon, pw, pl}` e chiama `showResult()` dopo 2.2s.

**Skip durante i rigori:** se l'utente preme ⏭ mentre la sequenza è in corso, `skipToResult()` setta `P.done = true` (ferma i timeout animati) e chiama `_simPenaltiesInstant()` che simula l'intera sequenza in modo sincrono e setta `M.penResult` prima di chiamare `showResult()`. Senza questo, `showResult()` trovava `M.penResult === null` e rilanciava `startPenalties()` da capo.

---

## Tournament Formats

**Old Cup:** Pure knockout, 5 rounds, `COPPA_OPPS`.
**Classic:** Group (3) + 4 knockout rounds. Top 2 of 4 advance.
**New Format:** League phase (6 matches, need ≥ 8 pts) + 4 knockout rounds.

---

## End Screens

### Unified CSS Classes

```
.end-card          — main container card
.end-header        — top section (round label + score)
.end-sc            — score display
.end-stats         — stats grid
.end-journey       — match-by-match results
.end-pitch-wrap    — pitch container wrapper
.draft-pitch       — pitch element (shared with draft screen)
```

### Key Element IDs

`go-round-lbl`, `go-sub-lbl`, `go-sc`, `go-stats`, `go-journey`, `go-extra`, `go-pitch` (gameover/elim screen)
`t-pitch` (trophy screen)

`showGroupElimination(pos, pts)` and `showGameOver()` both populate the `screen-gameover` element. `showTrophy()` populates `screen-trophy`.

---

## Theme System

`toggleTheme()` on switch:
1. Toggles `data-theme="light"` on `<body>`
2. Saves to `localStorage['gl-theme']`
3. Re-renders `renderGameOverPitch()` or `renderTrophyPitch()` if that screen is active (so border/shadow recalculate)
4. Re-injects SVG pitch lines on all `.draft-pitch` elements

Slot backgrounds (`.tier-*`) are pure CSS with `[data-theme]` overrides — no JS re-render needed.

CSS variables: `--bg`, `--s1`, `--s2`, `--brd`, `--gold`, `--text`, `--mut`, `--grn2`, `--red2`

---

## Scoring & Stars

| Result | Stars |
|--------|-------|
| Win by 2+ goals | ⭐⭐⭐ |
| Win by 1 goal | ⭐⭐ |
| Draw (group) | ⭐ |
| Win on penalties | ⭐ |
| Loss / eliminated | 0 |

Possession: `round(myXG / (myXG + oppXG) × 100)` ± noise, clamped 28–72%.

---

## Campaign Stats

| Field | Meaning |
|-------|---------|
| `G.campaignGF` / `G.campaignGA` | Total goals for/against |
| `G.campaignCleanSheets` | Matches with 0 conceded |
| `G.campaignBestWin` | Biggest winning margin |
| `G.campaignScorers` | Player name → goal count |
| `G.campaignMatchRatings` | Per-match player rating snapshots |
| `G.coach` | `{name, pref, compat, boost}` — null until coach is picked |

---

## Internationalisation (i18n)

Default: **Italian**. Toggle via 🇮🇹/🇬🇧 button (top-right, fixed).

```js
const STRINGS = { it: { 'key': 'valore' }, en: { 'key': 'value' } }
function t(key) { return STRINGS[G.lang || 'it'][key] || STRINGS.it[key] || key }
```

`applyLang()` updates all `data-i18n` (textContent) and `data-i18n-html` (innerHTML) elements, re-renders formation grid if visible. Language stored in `G.lang` and `localStorage['dcz_lang']`.

---

## Dev Panel

Triggered by **5 rapid clicks on the version number** (bottom of home screen).

| Feature | Function | Notes |
|---------|----------|-------|
| Quick Draft — Top XI | `_devQuickDraft('top')` | Uses all TEAMS, fills squad, shows in panel |
| Quick Draft — Random XI | `_devQuickDraft('random')` | Same, random selection |
| Jump to Round | `_devRenderJumpPanel()` | Bottoni dinamici per fase/round; si adattano a gameMode+format corrente |
| Force Score | `_DEV_FORCE_SCORE = [my, opp]` | Overrides next Poisson draw, cleared after use |
| Stress Test | `_devStressTest()` | N simulated matches, W/D/L bar + avg goals |
| Mock Result | `_devMockResult()` | Sets up match elements, navigates to screen-match |
| Mock Trophy | `_devMockTrophy()` | Sets G.phase='done', calls showTrophy() |
| Mock GameOver | `_devMockGameOver()` | Sets M.eliminated=true + mock group data, calls showGameOver() |

`_devEnsureSquad()` always returns `true`. Dev panel bypasses coach draft, calls `setupCampaign()` directly.

---

## Versioning

```js
const GAME_VERSION = '5.2.0';
```

Displayed via `<span class="gv"></span>` (populated on DOMContentLoaded). 5× rapid clicks → dev panel.

**Bump rules:** patch (bug fix / tweak) · minor (new mechanic / screen) · major (redesign)

**Suffisso `-dev`:** usato su branch `dev`. Va rimosso prima del merge su `main`.

---

## Development Workflow

```bash
# Clone fresh each session (/tmp è effimero — serve ogni volta)
cd /tmp && rm -rf repodev
git clone https://furini31:<TOKEN>@github.com/FuroSeo/decempionz.git repodev
cd repodev
git checkout dev   # lavorare SEMPRE su dev, mai su main

# Edit via Python (NEVER Edit tool direttamente su index.html per evitare problemi di encoding)
# Workspace (persistente): /sessions/<id>/mnt/Golacticos/index.html
# Repo (effimero):         /tmp/repodev/index.html
# Lavorare su /tmp/repodev/index.html, poi cp verso workspace

# CRITICO — node --check DEVE usare il blocco JS più grande (non il primo)
python3 -c "
import re
with open('/tmp/repodev/index.html') as f: html=f.read()
blocks=re.findall(r'<script[^>]*>([\s\S]*?)</script>', html)
main=max(blocks, key=len)   # <-- NON usare findall()[0]: il primo è Google Analytics!
open('/tmp/_check.js','w').write(main)
" && node --check /tmp/_check.js && echo SYNTAX OK

# Copy to workspace e push su dev
cp /tmp/repodev/index.html /sessions/<id>/mnt/Golacticos/index.html
cd /tmp/repodev
git config user.email "furini31@gmail.com" && git config user.name "FuroSeo"
git add index.html
git commit -m "feat/fix: descrizione"
git push origin dev

# Merge su main SOLO per release
git checkout main
git checkout dev -- index.html about.html   # prende i file da dev (evita conflitti)
# Rimuovere suffisso -dev da GAME_VERSION prima del merge
sed -i "s/GAME_VERSION='X.X.X-dev'/GAME_VERSION='X.X.X'/" index.html
git add index.html && git commit -m "release: vX.X.X" && git push origin main
```

**Regole:** sempre Python str.replace() per le edits · sempre `node --check` con `max(blocks, key=len)` · rimuovi `-dev` dal version prima del merge su main · mai pushare direttamente su `main` · sempre `cp` per tenere workspace e repo in sync.

**Cache:** GitHub Pages caches aggressively. Hard-refresh (Ctrl+Shift+R) or `?v=N` to bypass.

---

## File Structure (repo)

```
decempionz/
├── index.html           ← intero gioco (~5900 lines)
├── about.html           ← pagina SEO statica (bilingue IT/EN)
├── GAME_MANUAL.md       ← questo file
├── hall-of-fame.json    ← HoF data (aggiornato manualmente)
├── manifest.json        ← PWA manifest
├── sw.js                ← service worker
├── icon-192.png         ← PWA icon 192×192
├── icon-512.png         ← PWA icon 512×512
├── og-image.png         ← social preview image (1200×630)
├── robots.txt
└── sitemap.xml
```

---

## Dataset

### Rating System (v4.9.0+)

**Solo valutazioni intere.** I rating sono numeri interi (1–10), nessun mezzo punto (es. 9.5) per evitare la cascata di mezzi punti su tutti i livelli.

**Target ★10:** 31 istanze su 1122 giocatori (~2.8%). Le ★10 sono riservate ai GOAT assoluti per stagione. Prima del v4.9.0 erano 77 (6.9%), rendendo il draft troppo facile.

**Tier visivi** (le soglie `slotTierCss` / `slotTierClass` non cambiano):

| Tier | Rating | Effetto |
|------|--------|---------|
| tier-elite | 10 (≥9.5) | Gold border + glow animation |
| tier-high | 9 (≥9.0) | Gold thin border |
| tier-mid | 8 (≥8.5) | Grey border |
| tier-low | ≤7 | Tan border |

**★10 confermati (31 istanze):** Puskas, Eusebio, Mazzola, Charlton, Best, Rivera, Cruyff, Beckenbauer, Muller G, Scirea, Platini, Baresi (×2), Maldini (×3), van Basten, Gullit, Zidane (×2), Ronaldo R9, Kaka, CR7 (×3), Iniesta (×2), Messi (×2), Modric (×2).

**Opponent str vs dataset:** I valori `str` in `KNOCKOUT_OPPS` e `COPPA_OPPS` sono **hardcodati manualmente** e NON corrispondono alla media dei rating della rosa. Sono calibrati per la curva di difficoltà del torneo e vanno tenuti separati dal dataset. Non aggiornare automaticamente in base alle medie.

---

## Sistema Trofei Personali

15 achievement salvati in `localStorage` (`dcz_trophies`). Sbloccati automaticamente al termine di ogni vittoria (`showTrophy()` → `checkTrophies(G)`).

**Tier Base (7)** — vinci il torneo:

| ID | Torneo | Condizione |
|----|--------|-----------|
| `ucl_classic` | UCL | format=classic |
| `ucl_oldcup` | UCL | format=coppa |
| `ucl_newwave` | UCL | format=nouveau |
| `copa_classic` | Copa | format=classic |
| `copa_oldcup` | Copa | format=coppa |
| `wc_classic` | WC | format=classic |
| `wc_oldcup` | WC | format=coppa |

**Tier Challenge (5):**

| ID | Nome | Condizione |
|----|------|-----------|
| `grade_s` | Perfezione | stars/max ≥ 90% |
| `hard_winner` | Iron Man | difficulty='hard' |
| `unbeaten` | Imbattuto | 0 sconfitte in tutta la campagna |
| `triple_crown` | Triple Crown | ≥1 vittoria per ciascuno dei 3 tornei |
| `formation_master` | Tattico Totale | vittorie con ≥5 formazioni diverse (`dcz_formations_won`) |

**Tier Elite (3):**

| ID | Nome | Condizione |
|----|------|-----------|
| `all_star` | All Stars | ≥3 giocatori con rating ≥9.5 in rosa |
| `clean_final` | Finale Pulita | ultimo `knockResults` con oppG=0 |
| `legend` | Leggenda | tutti e 7 i trofei base sbloccati |

**Schermata:** `screen-trophies` — griglia 3×5 con card colorate (unlocked) / dimmate (locked). Accessibile dal pulsante 🏅 Trofei in home.

**Toast:** `#trophy-toast` — appare in fondo alla schermata per 3.5s al momento dell'unlock.

---

## Hall of Fame

Schermata `screen-hof` accessibile dal pulsante 🏆 in home. Fetch del file `hall-of-fame.json` dal repo GitHub.

```js
function openHallOfFame()   // mostra screen-hof, fetcha JSON (una sola volta: _hofLoaded flag)
```

**URL fetch:** `https://raw.githubusercontent.com/FuroSeo/decempionz/main/hall-of-fame.json`
**Branch:** sempre `main` (non `master`).

**Struttura `hall-of-fame.json`:**

```json
{
  "entries": [
    {
      "nickname": "FuroSeo",
      "grade": "S",
      "era": "🏆 Anni d'Oro (1992–2004)",
      "formation": "4-3-3",
      "difficulty": "hard",
      "record": "6V 1P 0S",
      "goals": "18-4",
      "topScorer": "Ronaldo (9g)",
      "lineup": { "GK": [...], "DEF": [...], "MID": [...], "FWD": [...] },
      "date": "2026-06-15"
    }
  ]
}
```

Il file viene aggiornato **manualmente** (push al repo). Il gioco legge sempre la versione più recente su `main`.

---

## PWA (Progressive Web App)

Il gioco è installabile come app nativa su smartphone.

**File aggiunti al repo:**

| File | Scopo |
|------|-------|
| `manifest.json` | Definisce nome, icone, colori, `display: standalone` |
| `sw.js` | Service worker: cache-first per assets statici |
| `icon-192.png` | Icona 192×192 |
| `icon-512.png` | Icona 512×512 |

**Meta tag in `<head>`:**

```html
<link rel="manifest" href="/manifest.json">
<link rel="apple-touch-icon" href="/icon-192.png">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="apple