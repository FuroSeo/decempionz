# Decempionz — Game Manual

**App version:** 5.16.3  
**Last updated:** 2026-09-17  
**Production:** `https://decempionz.com/`  
**Repository:** `FuroSeo/decempionz` (public)  
**Production branch:** `main`

This is the canonical functional record of the current Decempionz project. Update it in the same PR whenever gameplay, rules, modes, scoring, datasets, Daily/Weekly, Duel, Dynasty, progression or significant UX changes. Purely technical changes must state `No manual impact` in the PR.

The richer pre-migration manual recovered from `C:\Projects\decempionz` is preserved in the legacy archive; its SHA-256 prefix is recorded in `RECOVERY_INVENTORY.md`.

---

## 1. Current release notes

- **5.16.3** — emergency draft fill guarantees an XI of 11 even when slot-compatible players are exhausted; positional penalties still apply to emergency fills.
- **5.16.2** — teams used as draft sources are excluded from opponent selection; safety guard prevents drafted players appearing in the opponent XI.
- **5.16.1** — Dev Panel extended with Daily/Chemistry/tutorial/trophy/duel utilities; local `_studio.html` asset generator introduced.
- **5.16.0** — save export/import for all `dcz_*` localStorage keys and Chemistry explanation in How to Play.
- **Duel integrity 2026-09-18** — Duel results are generated server-side when player B commits the second squad. New squad submissions carry source `teamId` values and are checked against canonical `game-data.js` name/position/rating records and tournament/club scope. Exact browser draft-offer history remains client-unverified.
- **Infrastructure 2026-09-17** — Service Worker cache namespace moved to `decempionz-v5.16.4`; dynamic endpoints bypass cache; navigation timeout and controlled offline fallback added. This did **not** bump the public app version.
- **Workflow 2026-09-17** — branch/PR/CI workflow introduced; direct legacy `_push.bat` deployment retired; Dataset Editor and Studio recovered and versioned as non-deployed tools.

---

## 2. Architecture

Decempionz is a vanilla HTML/CSS/JavaScript browser game. The core UI and most game logic live in `index.html`; historical squads and tournament datasets live in `game-data.js`.

Backend PHP provides Hall of Fame, Daily, Weekly Challenge, Draft sharing, Duel and counters/statistics. Dynamic JSON/state is maintained on the server and must not be overwritten by normal deploys.

Important global state:

- `G` — persistent campaign state: squad, coach, results, momentum, format, game mode, etc.
- `M` — active match state.
- `P` — penalty shootout state.
- `_pendingMatch` — bridge between match setup/tactics and match execution.

Tournament routing uses `G.gameMode`:

- `ucl` → `TEAMS`
- `copa` → `COPA_TEAMS`
- `wc` → `WC_TEAMS`

Use the active-dataset helpers rather than hard-coding `TEAMS` when logic must work across tournaments.

`G.format` controls campaign structure:

- `classic` — group stage + knockout
- `coppa` — pure knockout
- `nuovo` — league phase + knockout, UCL-only where supported

---

## 3. Core game flow

Typical run:

`Home → tournament/format → era → formation/difficulty → 11-player draft → coach draft → match loop → trophy or elimination → stats/share/replay`

Secondary modes include Dynasty, Daily Puzzle, Weekly Challenges and asynchronous 1v1 Duel.

The core product loop remains: **draft legends → assemble an XI → play a tournament → see the result → play again**.

---

## 4. Player and squad data

Player objects use the compact historical model:

```js
{ n: "Player Name", p: "CM", r: 8, nat: "IT" }
```

Main fields:

- `n` — display name
- `p` — natural position
- `r` — rating
- `nat` — nationality when present/needed

Squads include name, season, country/club metadata and a player list. `game-data.js` is the source of truth for historical squad data.

When `game-data.js` changes, update the `game-data.js?v=...` revision in `index.html` because the asset is served with long-lived caching.

---

## 5. Draft system

The standard draft builds an XI around formation slots. Cards are generated from the active tournament/era pool and the slot-type system tries to offer choices that can fill remaining needs.

Key rules:

- natural-position/slot compatibility is defined centrally (`SLOT_COMPAT` / related helpers);
- off-role use is allowed where supported but applies a positional penalty through `slotPenalty`;
- rerolls depend on difficulty;
- discarded draft options do not automatically return to the pool;
- GK selection has reserve/cap safeguards so the draft does not become blocked;
- `finalizeDraft` contains an emergency second pass so a campaign always starts with 11 players even when strict compatibility is exhausted.

Blind Draft intentionally hides information according to the mode and must not accidentally reveal information added by normal Draft UX improvements.

---

## 6. Formations, coach and Chemistry

Formation determines XI slot layout and compatibility needs.

Coach draft offers a small pool of coaches. Coach compatibility with the chosen formation affects the team through an xG boost; compatibility/boost is stored in `G.coach`.

Chemistry is calculated by `calcChemistry(...)` and currently combines bonds such as nationality, club and department. It is capped and applied as an xG multiplier after the coach effect in the campaign match engine.

Important Chemistry rules are configured centrally (`CHEM_CFG`) and differ where necessary for tournament data distributions. World Cup nationality handling can derive nationality from the team when individual `nat` is absent.

Chemistry UI includes a segmented panel/chips and card badges. Chemistry is not a replacement for raw player quality or positional fit.

Known historical limitation to re-audit: Duel originally used a reduced `{n,p,r}` payload and therefore did not automatically receive the full campaign Chemistry context.

---

## 7. Match engine

The campaign engine derives team/opponent strength, xG and goals from player quality plus modifiers.

Tactics:

```js
TACT_MOD = {
  attack:   { myXG: 1.10, oppXG: 1.08 },
  balanced: { myXG: 1.00, oppXG: 1.00 },
  defend:   { myXG: 0.92, oppXG: 0.88 }
}
```

A counter matrix (`COUNTER_MOD`) provides rock-paper-scissors interactions between tactical choices.

Other match inputs include positional penalties, coach boost, Chemistry, difficulty/opponent modifiers and Momentum where applicable.

Drawn knockout matches can proceed to penalties. Penalty-shootout labels are localized.

The match engine must be treated as gameplay-critical: coefficient changes require simulation/balance testing, not visual testing only.

---

## 8. Tournament formats and end states

Supported tournament families:

- UEFA Champions League historical squads
- Copa Libertadores historical squads
- World Cup historical national teams

Campaign views include bracket/journey representation. End states include elimination/game-over and trophy/win screens.

The journey section and animated replay summarize the campaign path. Sharing, grade/score and campaign statistics are attached to end-of-run flows where relevant.

---

## 9. Difficulty

Difficulty configuration affects opponent strength and available draft resources/rerolls.

Current set includes Easy/Normal/Hard plus **Legend**. Legend is the highest difficulty and is tied to the `Immortale` achievement/trophy condition.

Difficulty values belong in central configuration and should not be duplicated across UI and simulation logic.

---

## 10. Dynasty mode

Dynasty constrains the draft around a selected historical club/national context rather than the normal open historical mix.

Dynasty has dedicated setup and pool-building helpers, supports the main tournament datasets, and is integrated with the Dev Panel for quick testing.

Club eligibility depends on the configured historical-squad threshold. Any changes to squad counts can therefore affect Dynasty availability and must be checked after dataset edits.

---

## 11. Daily Puzzle

Daily is deterministic from the local calendar date. The configuration is generated from a seeded PRNG so users on the same day should receive reproducible tournament/era/formation/difficulty conditions.

Key storage includes Daily history/streak and run/sent flags in `dcz_*` localStorage keys.

The first completed attempt is the one that counts for the daily result/streak; replay is available without rewriting the counted result.

Completion hooks cover trophy, knockout elimination and group-stage elimination.

Daily sharing uses a compact Wordle-like emoji result. Online ranking uses `daily-submit.php` and `daily-scores.php`, with server-side validation/rate limiting and date-scoped score storage.

The Dev Panel provides Daily reset and preview utilities for testing.

---

## 12. Weekly Challenges

Weekly Challenge configuration is served through challenge PHP endpoints. Challenge score submissions/ranking are stored separately from the normal campaign.

Production challenge configuration/state is server-maintained and excluded from normal FTP overwrite where required.

`sfide.html` provides the challenge-facing viewer/leaderboard experience.

---

## 13. Duel 1v1

Duel is asynchronous and uses PHP endpoints for create/join/result plus shared logic in `duel-lib.php` and the server-side simulation engine in `duel-engine.php`.

The protocol prevents player B from seeing player A's hidden squad before committing B's own squad. New Duel player payloads include `teamId` for every selected player. The backend verifies the exact `teamId + name + natural position + rating` tuple against `game-data.js`; Classic sources must belong to the Duel tournament, while Dynasty sources must belong to the selected club/tournament family.

After B's validated squad is accepted, the backend generates a private seed and computes the authoritative best-of-3 under the Duel-file lock. The server stores the official result, seed and engine version before returning the two squads and public result. The browser then uses that official result for the existing in-app match animation. Client-generated scores are not authoritative and legacy result payloads are ignored.

The Duel simulator evaluates both sides symmetrically and avoids campaign-only advantages such as user difficulty settings. The server engine mirrors the Duel coefficients for positional penalties, tactics/counters, star/rating-10 bonuses, xG, Poisson goals and penalties; the authority change is not intended as a balance change.

The private seed makes a newly generated result replayable by the server with the stored teams and engine version. Private `_server*` fields are never exposed by `duel-join.php`.

Historical completed duels remain readable as legacy client-reported records. Historical `simulating` duels can be finalized with a fresh server result, and a stale pre-upgrade client cannot overwrite that result.

Remaining integrity boundary: the server proves that submitted players are real canonical records from allowed source squads, but it does not yet prove the exact card offers/choices shown during the browser draft. A modified client could still choose a different combination of otherwise valid players within the allowed source scope. The detailed trust model and future server-issued draft-session direction are documented in `COMPETITIVE_INTEGRITY.md`.

Classic and Dynasty duel variants remain supported. Dynamic Duel pages provide share/invite/result metadata and are noindex where appropriate.

---
## 14. Hall of Fame, trophies and statistics

Hall of Fame uses PHP submission/status/admin endpoints plus server-side JSON state. Admin secrets/configuration must never be committed to the public repository.

Trophies/achievements are stored locally under `dcz_*` state and evaluated from campaign results. The Dev Panel can toggle trophy state for testing.

Statistics screens include campaign/personal data and save backup controls.

---

## 15. Save backup/import

Save export serializes all localStorage keys with the `dcz_*` prefix into a JSON payload containing app/version/date/data metadata.

Import validates the Decempionz payload/prefix, asks for confirmation, restores the keys and reloads the app.

This system should remain forward-compatible with future `dcz_*` keys and must be considered before storage migrations.

---

## 16. Internationalisation and content pages

The game UI uses an internal IT/EN/ES string system. Content pages and generated historical squad pages also exist in localized/static forms.

Recovered generators:

- `_build_i18n.py` — generates localized content pages from the maintained sources;
- `_build_rose.py` — generates squad/history SEO pages from current data.

Generated output must be diff-reviewed before merge. These scripts are developer tools and are excluded from FTP deployment themselves.

---

## 17. Onboarding and responsive layout

First-run onboarding uses coach marks stored through a `dcz_tut` flag.

Desktop Draft uses a two-column layout at large widths with the pitch kept visible while cards remain actionable. Mobile retains the single-column flow.

Windows flag rendering has a dedicated country-flag font fallback.

Coach-screen layout intentionally uses the established vertical-card design; do not redesign it accidentally while touching unrelated layout code.

---

## 18. Dev Panel

Open with **5 rapid clicks on the version number** on the home screen.

Verified capabilities include:

- Quick Draft Top XI / Random XI
- Dynasty quick launch for tournament/club testing
- jump to campaign round
- forced score
- stress test
- mock result / trophy / game-over
- reset today's Daily
- Daily preview
- live Chemistry report
- reset tutorial
- toggle trophy by id
- clear local duel data

The Dev Panel is part of `index.html` and must be included in regression checks after major refactors.

---

## 19. Local developer tools

### Dataset Editor — `dataset-editor.html`

Browser-local editor for `game-data.js`. It loads the data file, exposes UCL/Copa/WC teams and players, tracks a changelog and writes an updated `game-data.js`.

After saving data: update the `?v=` revision, regenerate affected generated pages, validate, commit on a branch and open a PR. **Do not use `_push.bat`.**

### Decempionz Studio — `_studio.html`

Local social/graphic asset creator reading `game-data.js`. Recovered modes:

- team vs team
- player comparison
- squad / guess-the-team
- guess the player
- Top XI
- “Veri fan”
- Top 5 squads

Exports PNG in 1:1, 9:16 and 16:9 formats through `html2canvas`.

Both HTML tools are versioned but excluded from the FTP deploy. Their inline JavaScript is syntax-checked by CI.

---

## 20. Service Worker / PWA

Current cache namespace: `decempionz-v5.16.4`.

Rules:

- same-origin GET handling only;
- `/sw.js` bypasses the app cache;
- PHP and dynamic Daily/Hall-of-Fame/Challenge/Duel endpoints bypass the Service Worker cache;
- navigations use network-first with timeout and controlled offline fallback;
- static assets use stale-while-revalidate;
- old `decempionz-*` cache namespaces are cleaned on activation.

`index.html` and `sw.js` are also configured no-cache/no-store at the HTTP layer.

---

## 21. Versioning

Three concepts are intentionally separate:

1. **App version** — `GAME_VERSION` in `index.html`; public release shown in UI/backups. Current: **5.16.3**.
2. **Service Worker cache version** — `CACHE` in `sw.js`; technical PWA cache namespace. Current: **5.16.4**.
3. **Game-data revision** — query value in `game-data.js?v=...`; invalidates the long-lived dataset cache.

Do not force these values to match. `_sync_version.py` belongs to the retired legacy workflow and must not be reactivated.

---

## 22. Development workflow

`main` is production. Every merge/push to `main` triggers the FTP deploy.

Standard flow:

1. update local `main`;
2. create a focused `fix/`, `feature/`, `refactor/` or `chore/` branch;
3. implement the change;
4. run `python scripts/validate_project.py` and feature-specific tests;
5. update this manual for functional changes, otherwise declare `No manual impact`;
6. open PR to `main`;
7. inspect diff and require green CI;
8. merge only after explicit production approval;
9. verify FTP deploy success;
10. smoke-test the live feature on desktop/mobile as relevant.

The old `C:\Projects\decempionz` + `_push.bat` flow is retired. Follow `LOCAL_SETUP.md` for the clean Git clone workflow.

---

## 23. File/storage boundaries

### Production code/content in Git

`index.html`, `game-data.js`, `sw.js`, public HTML pages, language/rose pages, PHP endpoints, manifest/robots/sitemap/htaccess and GitHub workflows.

### Developer files versioned in Git but excluded from FTP

`GAME_MANUAL.md`, `DEVELOPMENT.md`, `LOCAL_SETUP.md`, `COMPETITIVE_INTEGRITY.md`, `RECOVERY_INVENTORY.md`, `dataset-editor.html`, `_studio.html`, `_build_i18n.py`, `_build_rose.py` and other explicitly excluded dev artifacts.

The repository is public: FTP exclusion does **not** make a Git file private.

### Server-maintained state

Examples include Hall of Fame/config JSON, counters and runtime directories such as Daily scores, drafts, duels and challenge scores. Deployment exclusions must protect this state from replacement.

### Legacy archive only

The preserved old PC workspace contains retired one-off tools and documents such as `_push.bat`, `_sync_version.py` and historical World Cup build/patch scripts. Do not treat them as active without a fresh audit.