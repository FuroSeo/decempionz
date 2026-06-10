# Decempionz — Developer Manual

**Version:** 4.1.1  
**Last updated:** 2026-06-10  
**File:** `index.html` (single-file game, ~3400 lines)  
**Live:** [decempionz.com](https://decempionz.com) (GitHub Pages, custom domain)  
**Repo:** [github.com/FuroSeo/decempionz](https://github.com/FuroSeo/decempionz)

---

## Version History

| Version | Date | Notes |
|---------|------|-------|
| 4.1.1 | 2026-06-10 | Code cleanup: 77 unused CSS classes, 11 dead JS functions, 3 unused constants removed. Null-byte corruption fixed, modal legale ricostruito, banner disclaimer ripristinato |
| 4.1.0 | 2026-06-10 | Versioning introduced. Sprite graphics, 3-card draft polish, text overhaul, mobile fix, GAME_MANUAL |
| 4.0.0 | 2026 | 3-card pick-1 draft + Reroll mechanic (full core overhaul) + Sensible Soccer sprites |
| 3.0.0 | 2026 | Rebranding Golacticos → Decempionz, light/dark theme, compliance, trophy redesign |
| 2.0.0 | 2026 | 3 tournament formats (Old Cup, Classic, New Format) + era picker + SLOT_COMPAT |
| 1.0.0 | 2026 | First complete game: blind draft, position slots, formations, group + knockout |

---

## Architecture

Single HTML file. No build step, no dependencies, no backend. All game logic is vanilla JS inside a `<script>` block. CSS is inlined in `<style>`. The game state is held in a global object `G` and a match state object `M`.

**Key globals:**

| Variable | Type | Purpose |
|----------|------|---------|
| `G` | Object | Persistent campaign state (squad, results, momentum, format…) |
| `M` | Object | Active match state (scores, log, xG, penalties…) |
| `P` | Object | Penalty shootout state |
| `_pendingMatch` | Object | Tactic + opponent data bridging `playRound()` → `_runMatch()` |

---

## Game Flow

```
Home → Format Picker → Era Picker (optional) → Formation/Difficulty
     → Draft → Campaign screen → [Match loop] → Trophy / Elimination
```

**Format determines structure:**

| Format | Era pool | Phase structure | Matches |
|--------|----------|-----------------|---------|
| Old Cup (coppa) | 1956–1992 | Pure knockout from R1 | 5 |
| Classic | 1992–2024 | Group (3) + Knockout (4) | 7 |
| New Format (nuovo) | 1992–2024 | League phase (6) + Knockout (4) | 10 |

---

## Player Data Model

Every player in `TEAMS[teamId].players[]`:

```js
{ n: 'Futre', p: 'RW', r: 10 }
```

At draft time players are enriched with `club` (team name) and `teamId` (the key into `TEAMS`).

**Position groups** (used for strength calculation):

| Group | Positions |
|-------|-----------|
| GK | GK |
| DEF | CB, RB, LB |
| MID | CM, CDM, CAM, RM, LM |
| FWD | ST, CF, SS, RW, LW, RWB, LWB |

`posGroup()` handles the mapping. `pgClass()` returns the CSS class for badge color.

**Rating tiers** (visual only, also affects xG bonus):

| Tier | Rating | Border | Label |
|------|--------|--------|-------|
| ★ Legendary | ≥ 9.5 | Gold solid | ★ prefix |
| Gold | ≥ 9.0 | Gold thin | — |
| Silver | ≥ 8.5 | Grey | — |
| Bronze | < 8.5 | Tan | — |

`slotTierCss(r)` returns the inline CSS string for card/slot styling, theme-aware.

**Jersey colors:** `JERSEY_COLORS[teamId]` → hex color used for the Sensible Soccer-style sprite inside draft cards and pitch slots.

---

## Draft System

### Pool Construction (`initDraft`)

For each team in the era pool:
- Sort players by rating descending
- Take the top-rated player guaranteed
- Take up to 4 random players from the rest
- Flatten and shuffle the pool → `G.draftPool[]`
- `G.draftIdx` is a pointer that advances through the pool (never resets mid-draft)

Keyed by `teamId` (not club name) so multiple seasons of the same club each get independent representation.

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
finalizeDraft() → 1.5s delay → setupCampaign() → showCampaign()
```

`drawDraftCards()` only adds a card if `compatibleEmptySlots(p).length > 0`. Cards incompatible with all remaining empty slots are silently skipped. If fewer than 3 compatible cards remain in the pool, the hand may have 1 or 2 cards.

**Reroll:** `draftReroll()` discards current 3 cards and calls `drawDraftCards()` again. Costs 1 from `G.passes`. `G.passes` is set from `DIFF_CFG[difficulty].passes` at game start.

```js
DIFF_CFG = {
  easy:   { passes: 5, oppMod: -0.20 },
  normal: { passes: 3, oppMod:  0.15 },
  hard:   { passes: 2, oppMod:  0.40 },
}
```

### Slot Compatibility (`SLOT_COMPAT`)

Defines which player positions can fill each formation slot:

```
GK  → [GK]
CB  → [CB, RB, LB]
RB  → [RB, LB, CB]
LB  → [LB, RB, CB]
CDM → [CDM, CM]
CM  → [CM, CDM, CAM, RM, LM]
CAM → [CAM, CM, SS]
RM  → [RM, LM, RW, LW]
LM  → [LM, RM, LW, RW]
RW  → [RW, LW, RM, LM, CAM]
LW  → [LW, RW, LM, RM, CAM]
ST  → [ST, CF, SS]
CF  → [CF, ST, SS]
SS  → [SS, ST, CF, CAM]
RWB → [RWB, RB, LB, LWB]
LWB → [LWB, LB, RB, RWB]
```

`draftPick(i)` uses `compatibleEmptySlots(p)` to find candidate slots, then sorts them by preference (exact position match first, same group second, other last) and fills the best one.

### Finalization

If the pool is exhausted before 11 players are filled, `finalizeDraft()` force-fills remaining slots from the pool (position-preference order). The squad is then stored in `G.squad[]`.

---

## Match Simulation

### Opponent Strength

`teamStrength(teamId)` = average of all player ratings.

Effective opponent strength applied in `_runMatch()`:

```
effectiveOppStr = max(5, rawStr + diffCfg.oppMod + roundBonus)
```

**Round bonus (knockout only):**

| Format | R1 | R2/R16 | QF | SF | Final |
|--------|----|---------|----|-----|-------|
| Classic | — | +0 | +0.25 | +0.55 | +0.90 |
| Old Cup | +0 | +0 | +0.25 | +0.55 | +0.90 |

### My Team Strength

Players split into groups; composite attack and defense values:

```
atk = avg(FWD ratings) × 0.65 + avg(MID ratings) × 0.35
def = avg(DEF ratings) × 0.70 + avg(GK ratings)  × 0.30
```

### xG Calculation

Base xG before modifiers:

```
myXG  = max(0.13, ((atk  - oppStr × 0.84) × 0.36 + 0.56) × tactMod.myXG  × counterMod[0])
oppXG = max(0.10, ((oppStr - def × 0.80) × 0.42 + 0.32)  × tactMod.oppXG × counterMod[1])
```

**Star player bonus:**

```
starBonus = max(0, (topPlayerRating - 8.5) × 0.06)
myXG = min(myXG + starBonus, 2.6)
```

**Momentum modifier** (G.momentum ranges −3 to +3):

```
myXG  = clamp(myXG  + momentum × 0.03, 0.10, 2.6)
oppXG = clamp(oppXG - momentum × 0.03 × 0.35, 0.08, 2.6)
```

**Draw-pull** (reduces extreme scorelines when teams are evenly matched):

```
if |myXG - oppXG| < 0.35:
  pull = 0.05 × (0.35 - diff) / 0.35
  myXG  -= pull
  oppXG -= pull
```

### Goal Simulation

Goals are drawn from a **Poisson distribution**:

```js
function poisson(lambda) {
  let L = exp(-lambda), k = 0, p = 1;
  do { k++; p *= random() } while (p > L);
  return k - 1;
}
```

`myG = poisson(myXG)`, `oppG = poisson(oppXG)`

### Tactic System

Three tactics; internal keys map to display labels:

| Key | Label |
|-----|-------|
| `attack` | High Press |
| `balanced` | Possession |
| `defend` | Low Block |

**Rock-paper-scissors counter logic:**

| My Tactic | Beats | Loses to |
|-----------|-------|----------|
| High Press | Low Block | Possession |
| Possession | High Press | Low Block |
| Low Block | Possession | High Press |

**Tactic modifiers** (applied multiplicatively to xG):

Base (`TACT_MOD`):

| Tactic | myXG | oppXG |
|--------|------|-------|
| attack | ×1.10 | ×1.08 |
| balanced | ×1.00 | ×1.00 |
| defend | ×0.92 | ×0.88 |

Counter matrix (`COUNTER_MOD`) — `[myXGmul, oppXGmul]`:

| My \ Opp | attack | balanced | defend |
|----------|--------|----------|--------|
| attack | [1.00, 1.00] | [0.90, 1.08] | [1.12, 0.88] |
| balanced | [1.08, 0.90] | [1.00, 1.00] | [0.90, 1.06] |
| defend | [1.06, 0.88] | [1.04, 0.90] | [1.00, 1.00] |

Opponent tactic is drawn probabilistically based on their strength:

| Opponent strength | P(attack) | P(balanced) | P(defend) |
|------------------|-----------|-------------|-----------|
| ≥ 8.5 (strong) | 50% | 35% | 15% |
| < 7.0 (weak) | 20% | 40% | 40% |
| Otherwise | 33% | 34% | 33% |

### Momentum

Updated after each match result:

```
WIN  → momentum = min(momentum + 1, +3)
LOSS → momentum = max(momentum - 1, -3)
DRAW → no change
```

Momentum carries across all matches in a campaign (group + knockouts).

### Penalty Shootout

Triggered when a knockout match finishes level. Best of 5 rounds, alternating home/away. Each kick:

```
P(score) = 0.76 − 0.04 × (round − 1)   (slightly harder as shootout progresses)
```

If still level after 5 rounds, sudden death continues with fixed P(score) = 0.76. Winner is the side with more successful kicks.

---

## Tournament Formats

### Old Cup (coppa)

Pure knockout, 5 rounds. Opponents drawn from `COPPA_OPPS` (era-appropriate historical squads). No group stage. Every match is elimination. `G.knockRound` indexes 0–4.

### Classic

Group stage (3 matches) + knockout (4 rounds: R16 → QF → SF → Final).

Group standings use W/D/L/GD/GF. User must finish top 2 out of 4 to advance.
Simulated group matches (other teams) are generated via `calcStandings()` which fills in head-to-head results between non-user teams.

### New Format (nuovo)

League phase (6 matches, all vs. random teams from the full squad pool). User must reach ≥ 8 points to qualify. Then 4 knockout rounds.

---

## Scoring & Stars

Per match:

| Result | Stars |
|--------|-------|
| Win by 2+ goals | ⭐⭐⭐ |
| Win by 1 goal | ⭐⭐ |
| Draw (group) | ⭐ |
| Win on penalties | ⭐ |
| Loss / eliminated | 0 |

Match display stats (cosmetic — derived from xG, not simulation):
- **Possession:** `round(myXG / (myXG + oppXG) × 100)` ± random noise, clamped 28–72%
- **Shots:** xG-proportional random in range
- **Shot on target:** subset of shots

---

## Campaign Stats

Tracked in `G` throughout the run:

| Field | Meaning |
|-------|---------|
| `G.campaignGF` / `G.campaignGA` | Total goals for/against |
| `G.campaignCleanSheets` | Matches with 0 goals conceded |
| `G.campaignBestWin` | Biggest winning margin |
| `G.campaignScorers` | Map of player name → goal count |
| `G.campaignMatchRatings` | Per-match player rating snapshots |

---

## Versioning

The game version is defined in two places:

1. **JS constant** at the top of the `<script>` block:
   ```js
   const GAME_VERSION = '1.0.0';
   ```
2. **Footer HTML** (displayed in-game):
   ```html
   <span id="game-version">v1.0.0</span>
   ```

**Bump rules:**
- **Patch** (x.x.+1): bug fixes, text updates, balance tweaks
- **Minor** (x.+1.0): new mechanics, new screens, significant UI changes
- **Major** (+1.0.0): complete redesign or format change

Update `GAME_VERSION`, the footer, and this manual's version table on every meaningful change.

---

## Development Workflow

```bash
# Clone (or re-clone each session — /tmp is ephemeral)
cd /tmp && rm -rf decempionz
git init decempionz && cd decempionz
git remote add origin https://FuroSeo:<TOKEN>@github.com/FuroSeo/decempionz.git
git fetch --depth=1 origin main && git checkout main

# Edit the live file directly
# C:\Users\furin\OneDrive\Desktop\Golacticos\index.html
# (bash path: /sessions/epic-gracious-johnson/mnt/Golacticos/index.html)

# Validate JS syntax
python3 -c "
import re
with open('index.html') as f: html = f.read()
scripts = re.findall(r'<script[^>]*>(.*?)</script>', html, re.DOTALL)
js = '\n'.join(scripts)
open('/tmp/check.js','w').write('const window={},document={getElementById:()=>({}),querySelectorAll:()=>([]),body:{getAttribute:()=>null,setAttribute:()=>{},classList:{add:()=>{},remove:()=>{}}},addEventListener:()=>{}},localStorage={getItem:()=>null,setItem:()=>{}},Math=globalThis.Math,console=globalThis.console;\n' + js)
"
node --check /tmp/check.js

# Copy to repo and push
cp /sessions/epic-gracious-johnson/mnt/Golacticos/index.html /tmp/decempionz/index.html
cp /sessions/epic-gracious-johnson/mnt/Golacticos/GAME_MANUAL.md /tmp/decempionz/GAME_MANUAL.md
cd /tmp/decempionz
git add index.html GAME_MANUAL.md
git commit -m "description of change"
git push origin main
```

**Cache:** GitHub Pages caches aggressively. After a push, users must hard-refresh (Ctrl+Shift+R) or use `?v=N` in the URL to bypass cache.

---

## File Structure (repo)

```
decempionz/
├── index.html        ← entire game
├── GAME_MANUAL.md    ← this file
└── og-image.png      ← social preview image
```

---

## Known Constraints

-