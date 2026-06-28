# Decempionz — Developer Manual

**Version:** 5.5.55
**Last updated:** 2026-06-28
**File:** `index.html` (single-file game, ~5900 lines)
**Live:** [decempionz.com](https://decempionz.com) (GitHub Pages, custom domain)
**Repo:** [github.com/FuroSeo/decempionz](https://github.com/FuroSeo/decempionz)

---

## Version History

| Version | Date | Notes |
|---------|------|-------|
| 5.5.55 | 2026-06-28 | Fix howto.p4 IT/EN/ES: descrizione draft aggiornata al sistema slot-type |
| 5.5.54 | 2026-06-28 | Draft: GK reserve — GK escluso dai target finché ci sono ≥3 altri tipi feasibili, per evitare consumo anticipato |
| 5.5.53 | 2026-06-28 | Draft: pre-filtro tipi feasibili (solo tipi con ≥1 candidato in qualsiasi tier entrano nei target) |
| 5.5.52 | 2026-06-28 | Draft: fix break condition nella selezione per tipo slot (var _prevLen) |
| 5.5.51 | 2026-06-28 | Draft: tier fallback per tipo slot — se tier primario non ha candidati per un ruolo, cerca nei tier adiacenti |
| 5.5.50 | 2026-06-28 | Draft v2: sistema slot-type selection — 1 carta per tipo di slot rimasto unico, no-return su scarto, GK cap → 4 |
| 5.5.48 | 2026-06-27 | Fix display nome squadra in knockout: ricostruzione da DB con `opp.id` → formato "Ajax '95" invece di "Ajax 1994-95" |
| 5.5.29 | 2026-06-25 | Penalità posizionale in simulazione (`slotPenalty`), bonus r=10, badge ⚠️/⚡/🛡️ sulla draft card |
| 5.5.24 | 2026-06-24 | Fix Copa/WC coppa maxKnock=4; fix group stage elimination off-by-one; bump release |
| 5.5.19 | 2026-06-22 | Fix: banner "reroll esauriti" i18n IT/EN/ES; auto-hide log partita dopo 1s per portare il pulsante Kick Off in vista |
| 5.5.18 | 2026-06-22 | **RELEASE** Tier draft system (pool partizionato r≥10/r=9/r=8/r≤7; 3 carte sempre stesso tier); blocco reroll ultimi 3 slot (Normal/Hard); counter pool sotto cards i18n; ribilanciamento rating WC (45 downgrade r=10→r=9) e Copa (11 upgrade r=9→r=10); +7 rose Copa (24→31); sitemap.xml multi-pagina; ucl.html, copa.html, worldcup.html aggiornati |
| 5.5.17 | 2026-06-22 | Tier draft system: pool partizionato per tier (r=10/9/8/≤7); 3 carte sempre stesso tier; reroll cambia tier; counter pool sotto cards con label i18n |
| 5.5.14 | 2026-06-22 | Pagine storia competizioni: ucl.html, copa.html, worldcup.html (IT/EN/ES, rose espandibili, curiosità, link da card home e footer) |
| 5.5.12 | 2026-06-22 | Documentario campagna: racconto testuale algoritmico generato dai dati reali, visibile su trophy screen e gameover screen (IT/EN/ES) |
| 5.5.11 | 2026-06-22 | Commento post-partita: testo dinamico con varianti per ogni scenario (vittoria netta, rimonta, clean sheet, sconfitta…) — IT/EN/ES |
| 5.5.9  | 2026-06-22 | Draft Random: 11ª card formazione (🎲 estratta a sorpresa), blocco selezione "Random" esplicita nel picker |
| 5.3.0  | 2026-06-20 | Lingua spagnola (ES): STRINGS.es completo, toggle 3 lingue, data-i18n aggiornati in tutte le schermate e pagine statiche |
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
| `'ucl'` | UCL Legends | `TEAMS` (69 rose, 29 club) | `KNOCKOUT_OPPS` / `COPPA_OPPS` |
| `'copa'` | Copa Libertadores | `COPA_TEAMS` (40 rose) | `COPA_KNOCKOUT_OPPS` / `COPA_COPPA_OPPS` |
| `'wc'` | World Cup Legends | `WC_TEAMS` (~72 nazionali) | `WC_KNOCKOUT_OPPS` / `WC_COPPA_OPPS` |

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

At draft time players are enriched with `club` (team name) and `teamId` (key into `TEAMS`). UCL dataset: **69 teams / 29 clubs**.

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
- 65% chance top-rated player is the guaranteed pick; 35% chance second player is (variety without losing quality)
- Up to 3 random players from the rest
- Flatten, deduplicate by name (highest-rated version kept)
- **GK cap:** keep only the 4 highest-rated GKs, discard the rest
- Shuffle the full deduped pool, then partition into `G.draftTiers` by rating:

| Tier | Rating | Weight |
|------|--------|--------|
| 10 (Elite) | r = 10 | 3 |
| 9 (High) | r = 9 | 2 |
| 8 (Mid) | r = 8 | 2 |
| 7 (Base) | r ≤ 7 | 1 |

Each tier pool is shuffled independently at init. **AllTime cap:** max 3 versions per club, weighted-random favouring stronger seasons.

### Draft Loop (Slot-Type System, v5.5.50+)

```
drawDraftCards()
  ↓ (1 card per unique remaining slot type, up to 3)
renderThreeCards()
  ↓ (user taps one)
draftPick(i)
  ↓ (fills best slot, discards unchosen — no return to pool)
repeat until filledCount() === 11
  ↓
finalizeDraft() → 1.5s delay → showCoachDraft()
  ↓ (user picks coach)
pickCoach(i) → setupCampaign() → showCampaign()
```

### `drawDraftCards()` — How It Works

1. **Collect unique remaining slot types** — from unfilled slots in `fmtPositions(G.formation, G.tactic)`. If CB has 2 open slots, CB appears only once as a target type.
2. **Pick tier** — weighted random among tiers that have ≥1 compatible player (`G.draftTiers[t]`). GK is excluded from targets while ≥3 non-GK types are still feasible (GK reserve mechanic — prevents GK pool exhaustion).
3. **Filter to feasible types** — only types with ≥1 candidate in any tier (pre-filter).
4. **Non-GK target pool** — if ≥3 non-GK types are feasible, GK is excluded; otherwise GK is included to ensure enough options.
5. **Shuffle and pick up to 3 targets** — at most 3 unique slot types per pick.
6. **Find 1 player per target type** — primary tier first; if no candidate there, fall back to adjacent tiers by distance. Players already drawn in this pick are skipped.
7. **Pad to 3** — if fewer than 3 players were found, draw additional players from any feasible type until 3 or exhaustion.
8. **Permanently remove drawn players** from `G.draftTiers` at draw time (no-return policy — unchosen players do not go back to pool).

**Reroll:** `draftReroll()` discards current cards (they stay removed from pool) and calls `drawDraftCards()` again. Costs 1 from `G.passes`.

### Reroll Block

On **Normal** and **Hard** difficulty, reroll is locked when ≤ 3 slots remain empty:

```js
var _emptySlots = G.slotPlayers.filter(p => p === null).length;
if ((G.difficulty || 'normal') !== 'easy' && _emptySlots <= 3) return;
```

**Easy** difficulty is always exempt.

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
CB  → [CB, LB, RB, LWB, RWB]
RB  → [RB, RWB, LB, LWB, CB]
LB  → [LB, LWB, RB, RWB, CB]
CDM → [CDM, CM, CB]
CM  → [CM, CDM]
CAM → [CAM, SS, RW, LW]
RM  → [RM, LM, RW, LW]
LM  → [LM, RM, LW, RW]
RW  → [RW, LW, RM, LM, CAM, SS]
LW  → [LW, RW, LM, RM, CAM, SS]
ST  → [ST, CF, SS]
CF  → [CF, ST, SS]
SS  → [SS, ST, CF, CAM, RW, LW]
```

`draftPick(i)` fills the best compatible slot: exact position match first, same group second.

### Positional Penalty (`slotPenalty`)

When a player fills a slot outside their native position, `slotPenalty(nativePos, slotPos)` returns a penalty multiplier applied to xG in simulation:

| Distance | Example | Penalty |
|----------|---------|---------|
| Exact | CB → CB slot | 0 |
| Adjacent (same group) | RB → CB slot | −0.05 |
| Cross-group (tactical) | CDM → CB slot | −0.10 |
| Major mismatch | ST → CB slot | −0.25 |

A ⚠️ badge appears on the draft card if `slotPenalty > 0`. The penalty is shown visually but applied only at simulation time.

### r=10 Star Bonus

Players with `r = 10` grant an additional xG bonus in simulation, applied by role group:

| Group | Badge | xG effect |
|-------|-------|-----------|
| FWD | ⚡ | `myXG += bonus` |
| DEF | 🛡️ | `oppXG -= bonus` |
| MID/GK | ⚡ | `myXG += bonus × 0.5` |

Formula: `bonus = max(0, (r - 8.5) × 0.06)`, capped so `myXG ≤ 2.6`.

### Finalization

`finalizeDraft()` — called when all 11 slots are filled. Uses the flat remaining pool (`draftTiers` merged + `draftCards`) to force-fill any unfilled slots if pool was exhausted. Squad stored in `G.squad[]`.

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

Default: **Italian**. Toggle via flag button (top-right, fixed) — cycles IT → EN → ES.

```js
const STRINGS = {
  it: { 'key': 'valore' },
  en: { 'key': 'value' },
  es: { 'key': 'valor' },
}
function t(key) { return STRINGS[G.lang || 'it'][key] || STRINGS.it[key] || key }
```

`applyLang()` updates all `data-i18n` (textContent) and `data-i18n-html` (innerHTML) elements, re-renders formation grid if visible. Language stored in `G.lang` and `localStorage['dcz_lang']`.

All 3 language sets are complete for: howto, format labels, difficulty descriptions, tactic names, match commentary, campaign documentary, tournament page content (ucl.html / copa.html / worldcup.html).

---

## Post-Match Commentary (v5.5.11+)

After each match result a short algorithmically generated text is shown, contextualising the outcome. The text varies based on: win/loss/draw, goal margin, clean sheet, penalties, momentum, and whether it was a group or knockout match.

Generated by `buildMatchComment(result, isKnockout)` → returns a string in `G.lang`. Displayed inline on the result screen, above the stats grid.

---

## Campaign Documentary (v5.5.12+)

At the end of a campaign (trophy screen and gameover screen) a multi-paragraph narrative is generated from the actual campaign data:

```js
buildCampaignDocumentary(G)  // returns HTML string
```

Pulls from: `G.campaignGF`, `G.campaignGA`, `G.campaignCleanSheets`, `G.campaignBestWin`, `G.campaignScorers`, `G.knockResults[]`, `G.squad`, `G.coach`, `G.momentum`, `G.difficulty`, `G.era`. The text adapts to the language (`G.lang`) and references specific players and matches by name.

---

## Tournament Pages (v5.5.14+)

Three static HTML pages with tournament history, format explanation, curiosities, and expandable squad lists:

| File | Tournament | Squads |
|------|-----------|--------|
| `ucl.html` | UCL Legends | 66 rose, 29 club |
| `copa.html` | Copa Libertadores | 31 rose |
| `worldcup.html` | World Cup Legends | 65 nazionali |

Each page is trilingual (IT/EN/ES), linked from the home tournament cards and the site footer. The squad list uses `toggleSquad(n)` for expandable rows.

`copa.html` era groups: Los Pioneros 1960–1975 · Años Dorados 1976–1992 · Era Clásica 1992–2007 · Era Moderna 2008–2024.

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
const GAME_VERSION = '5.5.55';
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
├── index.html           ← intero gioco (~6200 lines)
├── about.html           ← pagina SEO statica (trilingue IT/EN/ES)
├── ucl.html             ← pagina torneo UCL Legends (IT/EN/ES)
├── copa.html            ← pagina torneo Copa Libertadores — 31 rose (IT/EN/ES)
├── worldcup.html        ← pagina torneo World Cup Legends (IT/EN/ES)
├── GAME_MANUAL.md       ← questo file
├── hall-of-fame.json   