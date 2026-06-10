# Decempionz — Developer Manual

**Version:** 4.3.4  
**Last updated:** 2026-06-10  
**File:** `index.html` (single-file game, ~3700 lines)  
**Live:** [decempionz.com](https://decempionz.com) (GitHub Pages, custom domain)  
**Repo:** [github.com/FuroSeo/decempionz](https://github.com/FuroSeo/decempionz)

---

## Version History

| Version | Date | Notes |
|---------|------|-------|
| 4.3.4 | 2026-06-10 | Manuale aggiornato, "Come si gioca" aggiornato con sezione allenatore |
| 4.3.3 | 2026-06-10 | Share: lineup completa per reparto, allenatore, lingua, nessun doppio URL |
| 4.3.2 | 2026-06-10 | Share text redesign: difficoltà, allenatore, i18n, rimosso doppio link |
| 4.3.1 | 2026-06-10 | Fix coach cards non cliccabili (pitch svuotato, scroll corretto) |
| 4.3.0 | 2026-06-10 | Sistema allenatore: coach draft post-XI, formDMA/coachCompat, boost xG |
| 4.2.4 | 2026-06-10 | i18n completo: howto, format, formazioni, era, bottoni, favicon ⚽ |
| 4.2.3 | 2026-06-10 | Fix Dev Panel: quick draft usa tutti i team, stress test fix, no blind nav |
| 4.2.0 | 2026-06-10 | Sistema i18n IT/EN: STRINGS, t(), toggleLang(), data-i18n, default italiano |
| 4.1.3 | 2026-06-10 | Hotfix definitivo: CSS ripristinato da v4.1.0 |
| 4.1.1 | 2026-06-10 | Code cleanup: dead JS/CSS rimosso |
| 4.1.0 | 2026-06-10 | Versioning, sprite grafici, draft polish, Dev Panel |
| 4.0.0 | 2026 | Draft 3-card pick-1 + Reroll mechanic |
| 3.0.0 | 2026 | Rebranding Golacticos → Decempionz, light/dark theme, compliance |
| 2.0.0 | 2026 | 3 formati torneo + era picker + SLOT_COMPAT |
| 1.0.0 | 2026 | Primo gioco completo: draft cieco, slot posizione, gironi + playoff |

---

## Architecture

Single HTML file. No build step, no dependencies, no backend. All game logic is vanilla JS inside a `<script>` block. CSS is inlined in `<style>`. The game state is held in a global object `G` and a match state object `M`.

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
     → Draft (11 players) → Coach Draft → Campaign → [Match loop] → Trophy / Elimination
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
finalizeDraft() → 1.5s delay → showCoachDraft()
  ↓ (user picks coach)
pickCoach(i) → setupCampaign() → showCampaign()
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

If the pool is exhausted before 11 players are filled, `finalizeDraft()` force-fills remaining slots from the pool (position-preference order). The squad is stored in `G.squad[]`. After 1.5s the coach draft phase begins.

---

## Coach System

### Coach Draft (`showCoachDraft`)

Triggered automatically after the 11th player is drafted. The pitch is cleared and 3 random coach cards are drawn from the `COACHES` array (30 historical managers). No rerolls — the player must pick one of the 3.

Each coach has:
```js
{ n: 'Pep Guardiola', pref: '4-3-3' }
```

### Formation Matching

`formDMA(key)` decomposes a formation string into `{d, m, a}`:
- `d` = first number (defenders)
- `m` = sum of middle numbers (midfielders)
- `a` = last number (attackers)

Examples: `4-3-3` → `{d:4, m:3, a:3}` · `4-2-3-1` → `{d:4, m:5, a:1}` · `4-1-4-1` → `{d:4, m:5, a:1}`

`coachCompat(coachPref, chosenForm)` counts matching lines (0–3).

### xG Boost

`coachBoostMul(compat)` returns a multiplier applied to `myXG` in `_runMatch()`:

| Matching lines | Boost |
|---------------|-------|
| 3/3 (perfect) | ×1.08 (+8%) |
| 2/3 | ×1.05 (+5%) |
| 1/3 | ×1.02 (+2%) |
| 0/3 | ×1.00 (no boost) |

The boost is applied after momentum and before the draw-pull:
```js
if (G.coach && G.coach.boost > 1) myXG = min(myXG * G.coach.boost, 2.6)
```

`G.coach` stores `{name, pref, compat, boost}`. Reset to `null` on `applyTournament()`.

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

**Coach boost** (applied after momentum):
```
if coach selected: myXG = min(myXG × coachBoostMul(compat), 2.6)
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

| Key | IT label | EN label |
|-----|----------|---------|
| `attack` | Pressione Alta | High Press |
| `balanced` | Possesso Palla | Possession |
| `defend` | Blocco Basso | Low Block |

**Rock-paper-scissors counter logic:**

| My Tactic | Beats | Loses to |
|-----------|-------|----------|
| High Press | Low Block | Possession |
| Possession | High Press | Low Block |
| Low Block | Possession | High Press |

**Tactic modifiers** (`TACT_MOD`, multiplicative on xG):

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
P(score) = 0.76 − 0.04 × (round − 1)
```

If still level after 5 rounds, sudden death with fixed P(score) = 0.76.

---

## Tournament Formats

### Old Cup (coppa)

Pure knockout, 5 rounds. Opponents from `COPPA_OPPS`. No group stage. Every match is elimination. `G.knockRound` indexes 0–4.

### Classic

Group stage (3 matches) + knockout (4 rounds: R16 → QF → SF → Final). User must finish top 2 of 4 to advance. Simulated group matches generated via `calcStandings()`.

### New Format (nuovo)

League phase (6 matches vs. random teams from full pool). User must reach ≥ 8 points to qualify. Then 4 knockout rounds.

---

## Scoring & Stars

| Result | Stars |
|--------|-------|
| Win by 2+ goals | ⭐⭐⭐ |
| Win by 1 goal | ⭐⭐ |
| Draw (group) | ⭐ |
| Win on penalties | ⭐ |
| Loss / eliminated | 0 |

Match display stats (cosmetic — derived from xG):
- **Possession:** `round(myXG / (myXG + oppXG) × 100)` ± noise, clamped 28–72%
- **Shots / OT:** xG-proportional random ranges

---

## Campaign Stats

| Field | Meaning |
|-------|---------|
| `G.campaignGF` / `G.campaignGA` | Total goals for/against |
| `G.campaignCleanSheets` | Matches with 0 goals conceded |
| `G.campaignBestWin` | Biggest winning margin |
| `G.campaignScorers` | Map of player name → goal count |
| `G.campaignMatchRatings` | Per-match player rating snapshots |
| `G.coach` | `{name, pref, compat, boost}` — null if not yet picked |

---

## Internationalisation (i18n)

Default language: **Italian**. Toggle via 🇮🇹/🇬🇧 button (top-right, fixed position).

```js
const STRINGS = { it: { 'key': 'valore' }, en: { 'key': 'value' } }
function t(key) { return STRINGS[G.lang || 'it'][key] || STRINGS.it[key] || key }
```

Language stored in `G.lang` and `localStorage['dcz_lang']`. `applyLang()` updates all elements with `data-i18n` (textContent) or `data-i18n-html` (innerHTML) attributes, and re-renders the formation grid if visible.

---

## Share Feature

`shareResult()` builds a multi-line text message:

```
✅ Grade B — Old Cup Winner!
🟡 Normale · 4-3-3 · 👔 Guardiola
                                    ← blank line
🧤 Zoff
🛡️ Cafu · Baresi · Maldini · R.Carlos
⚙️ Zidane · Pirlo · Vieira
⚡ Ronaldo · Van Basten · Cruyff
                                    ← blank line
⚽ 2V 3P 0S · 9-5 gol
🌟 Capocannoniere: Ronaldo (2g)
                                    ← blank line
Puoi fare meglio? → decempionz.com
```

Uses `navigator.share({text, title})` on mobile (no `url` parameter to avoid double link). Falls back to clipboard copy on desktop. Language-aware (IT/EN).

---

## Dev Panel

Triggered by **5 rapid clicks on the version number** (bottom of home screen). Hidden overlay accessible only to the developer.

| Feature | Function | Notes |
|---------|----------|-------|
| Quick Draft — Top XI | `_devQuickDraft('top')` | Uses all TEAMS as pool, fills squad, shows in panel |
| Quick Draft — Random XI | `_devQuickDraft('random')` | Same pool, random selection |
| Jump to Round | buttons per round | Sets G.phase + round directly |
| Force Score | `_DEV_FORCE_SCORE = [my, opp]` | Overrides next Poisson draw, cleared after use |
| Stress Test | `_devStressTest()` | N simulated matches, W/D/L bar + avg goals |
| Mock Screens | buttons | Navigate to result/trophy/gameover screens |

Quick Draft does **not** navigate away — shows drafted squad in panel with "▶ Campagna" button. Dev panel bypasses the coach draft (calls `setupCampaign()` directly).

---

## Versioning

The game version is defined in JS:
```js
const GAME_VERSION = '4.3.4';
```
Displayed on home screen via `<span class="gv"></span>` (populated on load) and triggers the dev panel on 5× click.

**Bump rules:**
- **Patch** (x.x.+1): bug fixes, text/balance tweaks, minor UI
- **Minor** (x.+1.0): new mechanics, new screens, significant features
- **Major** (+1.0.0): complete redesign

---

## Development Workflow

```bash
# Clone fresh each session (/tmp is ephemeral)
cd /tmp && rm -rf decempionz
git clone https://github.com/FuroSeo/decempionz.git decempionz

# Edit live file
# Windows: C:\Users\furin\OneDrive\Desktop\Golacticos\index.html
# Bash:    /sessions/epic-gracious-johnson/mnt/Golacticos/index.html

# Validate JS syntax
python3 -c "
import re, subprocess
with open('/sessions/epic-gracious-johnson/mnt/Golacticos/index.html','r') as f: html=f.read()
scripts=re.findall(r'<script(?:\s[^>]*)?>(.*?)</script>',html,re.DOTALL)
stubs='var document={querySelector:()=>null,querySelectorAll:()=>[],getElementById:()=>null,body:{classList:{add:()=>{},remove:()=>{},toggle:()=>{}},getAttribute:()=>null,style:{}},createElement:()=>({style:{},classList:{add:()=>{}},appendChild:()=>{}}),addEventListener:()=>{}};\nvar window={matchMedia:()=>({matches:false}),scrollTo:()=>{},location:{href:\"\"},addEventListener:()=>{}};\nvar localStorage={getItem:()=>null,setItem:()=>{}};\nvar navigator={share:()=>Promise.resolve(),clipboard:{writeText:()=>Promise.resolve()}};\n'
open('/tmp/validate.js','w').write(stubs+'\n'.join(scripts))
r=subprocess.run(['node','--check','/tmp/validate.js'],capture_output=True,text=True)
print('JS valid' if r.returncode==0 else r.stderr)
"

# Copy and push
cp /sessions/epic-gracious-johnson/mnt/Golacticos/index.html /tmp/decempionz/index.html
cp /sessions/epic-gracious-johnson/mnt/Golacticos/GAME_MANUAL.md /tmp/decempionz/GAME_MANUAL.md
cd /tmp/decempionz
git config user.email "furini31@gmail.com" && git config user.name "Furo"
git add index.html GAME_MANUAL.md
git commit -m "vX.X.X — description"
git push https://furini31:<TOKEN>@github.com/FuroSeo/decempionz.git main
```

**Cache:** GitHub Pages caches aggressively. Hard-refresh (Ctrl+Shift+R) or append `?v=N` to bypass.

---

## File Structure (repo)

```
decempionz/
├── index.html        ← entire game (~3700 lines)
├── GAME_MANUAL.md    ← this file
└── og-image.png      ← social preview image (1200×630)
```

---

## Known Constraints

- Single file — no module system, no tree-shaking. Keep globals minimal.
- `user-select: none` applied globally — intentional, prevents text cursor on tap.
- `localStorage` used only for `dcz_lang` and `dcz_theme` — no save state.
- GitHub Pages serves from `main` branch root. Deploy = push to main.
- `navigator.share` unavailable on desktop — falls back to clipboard copy silently.
