# Decempionz — Developer Manual

**Version:** 4.8.9
**Last updated:** 2026-06-13
**File:** `index.html` (single-file game, ~4500 lines)
**Live:** [decempionz.com](https://decempionz.com) (GitHub Pages, custom domain)
**Repo:** [github.com/FuroSeo/decempionz](https://github.com/FuroSeo/decempionz)

---

## Version History

| Version | Date | Notes |
|---------|------|-------|
| 4.8.9 | 2026-06-13 | Fix theme-switch name color: tier backgrounds via CSS classes, not inline styles |
| 4.8.8 | 2026-06-13 | Remove duplicate showTrophy(); fix doubled CSS selector |
| 4.8.7 | 2026-06-13 | Replace CSS ::before SVG pitch lines with JS DOM injection (injectPitchLines) |
| 4.8.6 | 2026-06-13 | Fix match timer (neutral events get min property); fix group elim pitch render |
| 4.8.5 | 2026-06-13 | Fix dev panel mock screens (Result nav, Trophy/GameOver guards) |
| 4.8.4 | 2026-06-13 | Add pitch SVG field lines; fix dark-theme player name visibility on end screens |
| 4.8.3 | 2026-06-13 | Fix _diffLabel() missing; fix knockout elimination crash |
| 4.8.2 | 2026-06-13 | Fix showGroupElimination() using stale IDs from old redesign |
| 4.8.1 | 2026-06-13 | Fix missing pieces of v4.8.0 redesign (HTML, CSS, JS, i18n, showTrophy, _diffLabel) |
| 4.8.0 | 2026-06-13 | Endgame screen redesign: unified .end-* CSS classes, pitch on gameover/trophy |
| 4.7.0 | 2026-06-12 | Sprite system: ghost/colored/complete (filledSlotHTML, ghostSprite, targetSprite) |
| 4.6.4 | 2026-06-12 | Fix home subtitle; misc UI polish |
| 4.6.3 | 2026-06-12 | FORMATIONS with attack/balanced/defend variants; fmtPositions/fmtRows helpers |
| 4.6.2 | 2026-06-12 | Simplify flow: coach in draft, remove campaign screen, tactic in result |
| 4.6.1 | 2026-06-12 | Split modal Fan Project / Privacy; fix Close; remove Ko-fi |
| 4.6.0 | 2026-06-12 | "Gioca subito" quick-start + "Personalizza" full flow; home redesign |
| 4.5.3 | 2026-06-11 | Dev panel: stress test, coach, tactic, mock screens |
| 4.5.2 | 2026-06-11 | Update OG meta tag (58 → 66 squads) |
| 4.5.1 | 2026-06-11 | Add press/tap feedback on draft cards; improve era/format card hover |
| 4.5.0 | 2026-06-11 | Fix light theme: logo gradient, era-sel, tour cards, tc-team cells |
| 4.4.0 | 2026-06-10 | AC Milan 1988-89; Abedi Pelé; RM/LM reclassification; SLOT_COMPAT tightening |
| 4.3.4 | 2026-06-10 | Game manual added; coach section updated |
| 4.3.3 | 2026-06-10 | Share: full lineup, coach, lang, no double URL |
| 4.3.0 | 2026-06-10 | Coach system: post-draft pick, formDMA/coachCompat, xG boost |
| 4.2.0 | 2026-06-10 | i18n system: STRINGS, t(), toggleLang(), data-i18n, default Italian |
| 4.1.0 | 2026-06-10 | Versioning, sprite graphics, draft polish, Dev Panel |
| 4.0.0 | 2026 | Draft 3-card pick-1 + Reroll mechanic |
| 3.0.0 | 2026 | Rebranding Golacticos → Decempionz; light/dark theme; compliance |
| 2.0.0 | 2026 | 3 tournament formats + era picker + SLOT_COMPAT |
| 1.0.0 | 2026 | First complete game: blind draft, positional slots, group + knockout |

---

## Architecture

Single HTML file. No build step, no dependencies, no backend. All game logic is vanilla JS inside a `<script>` block. CSS is inlined in `<style>`. Game state is held in two global objects: `G` (campaign) and `M` (active match).

**Key globals:**

| Variable | Type | Purpose |
|----------|------|---------|
| `G` | Object | Persistent campaign state (squad, coach, results, momentum, format…) |
| `M` | Object | Active match state (scores, log, xG, penalties…) |
| `P` | Object | Penalty shootout state |
| `_pendingMatch` | Object | Tactic + opponent data bridging `playRound()` → `_runMatch()` |

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

`P(score) = 0.76 − 0.04 × (round − 1)` for rounds 1–5, then `0.76` in sudden death.

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
| Jump to Round | per-round buttons | Sets G.phase + round directly |
| Force Score | `_DEV_FORCE_SCORE = [my, opp]` | Overrides next Poisson draw, cleared after use |
| Stress Test | `_devStressTest()` | N simulated matches, W/D/L bar + avg goals |
| Mock Result | `_devMockResult()` | Sets up match elements, navigates to screen-match |
| Mock Trophy | `_devMockTrophy()` | Sets G.phase='done', calls showTrophy() |
| Mock GameOver | `_devMockGameOver()` | Sets M.eliminated=true + mock group data, calls showGameOver() |

`_devEnsureSquad()` always returns `true`. Dev panel bypasses coach draft, calls `setupCampaign()` directly.

---

## Versioning

```js
const GAME_VERSION = '4.8.9';
```

Displayed via `<span class="gv"></span>` (populated on DOMContentLoaded). 5× rapid clicks → dev panel.

**Bump rules:** patch (bug fix / tweak) · minor (new mechanic / screen) · major (redesign)

---

## Development Workflow

```bash
# Clone fresh each session (/tmp is ephemeral)
cd /tmp && rm -rf repo4
git clone https://furini31:<TOKEN>@github.com/FuroSeo/decempionz.git repo4

# Edit via Python str.replace() — NEVER use the Edit tool directly on index.html
# Workspace: /sessions/epic-gracious-johnson/mnt/Golacticos/index.html
# Repo:      /tmp/repo4/index.html
# Work on /tmp/repo4/index.html, then cp to workspace

# Validate JS syntax
python3 -c "
import re, subprocess
with open('/tmp/repo4/index.html') as f: src=f.read()
scripts=re.findall(r'<script(?:\s[^>]*)?>(.*?)</script>',src,re.DOTALL)
open('/tmp/check.js','w').write('\n'.join(scripts))
r=subprocess.run(['node','--check','/tmp/check.js'],capture_output=True,text=True)
print('SYNTAX OK' if r.returncode==0 else r.stderr)
"

# Copy and push
cp /tmp/repo4/index.html /sessions/epic-gracious-johnson/mnt/Golacticos/index.html
cd /tmp/repo4
git config user.email "furini31@gmail.com" && git config user.name "FuroSeo"
git add index.html GAME_MANUAL.md
git commit -m "vX.X.X — description"
git push origin main
```

**Rules:** always Python str.replace() for edits · always node --check before commit · always bump GAME_VERSION · always cp to keep workspace and