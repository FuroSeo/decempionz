# Decempionz — Development and release process

This document defines the safe workflow for changes to Decempionz.

## Production branch

`main` is production. A push to `main` triggers `.github/workflows/deploy.yml`, which uploads the repository to `/public_html/` via FTP.

Do not develop directly on `main`.

## Branch naming

Use one branch per logical change:

- `fix/...` for bug fixes
- `feature/...` for user-facing features
- `refactor/...` for structural changes without intended behavior changes
- `chore/...` for tooling, CI and maintenance

## Pull request flow

1. Create a branch from current `main`.
2. Keep the change focused on one logical purpose.
3. Run repository validation and syntax checks.
4. Open a pull request against `main`.
5. Review the diff and confirm that unrelated files are unchanged.
6. Update `GAME_MANUAL.md` in the same PR when the change affects gameplay, rules, modes, scoring, datasets, Daily/Weekly, Duel, Dynasty, progression or significant UX. For purely technical changes, explicitly state `No manual impact` in the PR.
7. Merge only after the change is explicitly approved for production.
8. Confirm the FTP deploy workflow completes successfully.
9. Perform a smoke test on the live site.

## Canonical documentation

`GAME_MANUAL.md` is the canonical functional record of Decempionz. It must describe the behavior that actually exists in the current code, not planned behavior.

`DEVELOPMENT.md` documents the engineering/release process. `LOCAL_SETUP.md` documents the Windows working-copy setup. `RECOVERY_INVENTORY.md` records what was recovered from the legacy Claude-era workspace.

Because this repository is public, none of these files may contain credentials, private tokens or passwords.

## Version meanings

Decempionz currently has three separate revision concepts. They are intentionally not required to have the same value.

### App version

`GAME_VERSION` in `app.js` is the public Decempionz release version shown in the interface and included in exported backups.

Increment it when the public game release changes in a way users should recognise as a new Decempionz version.

### Service Worker cache version

`CACHE` in `sw.js` identifies the browser cache namespace, for example `decempionz-v5.16.4`.

Increment it whenever cached shell/assets must be invalidated. A cache hotfix does not automatically require an app-version bump.

### Game-data asset revision

The query string in `game-data.js?v=...` invalidates the long-lived browser cache configured for `game-data.js`.

Increment it whenever `game-data.js` changes in production.

A future architecture refactor may move these values into a shared release manifest. Until then, their roles must remain explicit and validated.

## Local working copy

The old `C:\Projects\decempionz` workspace and `_push.bat` workflow are retired. `_push.bat` cloned GitHub to a temporary folder, copied a partial file list and pushed directly to `main`, bypassing the current PR/CI process.

A normal local workspace must be a real Git clone of `FuroSeo/decempionz`. Follow `LOCAL_SETUP.md` to migrate safely while preserving the historical folder as an archive.

Recovered developer tools such as `dataset-editor.html`, `_studio.html`, `_build_rose.py` and `_build_i18n.py` are versioned but excluded from FTP deployment. They are still visible in the public GitHub repository, so they must remain free of secrets.

## Required smoke test

Before a large change is considered complete, verify at minimum:

- home opens on desktop
- home opens on mobile
- tournament configuration opens
- draft starts and a player can be selected
- coach selection works
- a match starts and finishes
- game-over or trophy screen renders
- Daily screen loads
- Hall of Fame screen loads
- no blocking JavaScript error appears in the browser console

Feature-specific changes require additional checks for the affected feature.

### Automated browser smoke test

`scripts/e2e_smoke.js` runs in CI on every pull request. It serves the repository as a static site, opens it in headless Chromium and plays complete UCL, Copa Libertadores and World Cup campaigns on a mobile viewport plus a desktop UCL campaign (Quick Draft, coach, group and knockout matches, end screen). PHP endpoints answer 404 and third-party hosts are blocked, so it never touches production data. It fails on uncaught JavaScript errors, unexpected console errors, a stuck UI, a campaign that never finishes, or visible `NaN` / `undefined` / `[object` text.

Run it locally with `npm install --no-save playwright`, `npx playwright install chromium` and `node scripts/e2e_smoke.js` (`E2E_ONLY=ucl` runs a single scenario; `PW_CHROMIUM=/path/to/chrome` reuses an installed browser). It does not replace the manual smoke test above: Daily, Duel, Hall of Fame and visual checks still need a human pass on the live site.

### Automated PHP end to end test

`scripts/e2e_php.js` runs in CI on every pull request, after the PHP unit tests. Unlike `e2e_smoke.js`, which answers every `.php` request with 404, this test starts a real `php -S` server on a throw-away copy of the repository (the working tree is never touched) and drives the actual client against it, so it exercises the browser and the PHP backend together.

It covers Daily (validation, one entry per network and nickname per day, ranking, percentile, HTML escaping, concurrent submissions) and Duel (player A drafts and creates the duel with a server-verified draft session, player B opens the invite link, drafts and the server plays the best-of-3 series, player A replays the verdict, and the public duel pages never leak a squad that has not been revealed yet). It fails on a PHP warning/notice/fatal, an unexpected JavaScript error or dialog, a stuck UI, or a check that does not hold.

Run it locally the same way as `e2e_smoke.js`, with `node scripts/e2e_php.js` (`php` must be in `PATH`; `E2E_ONLY=daily-api,daily,duel,duel-api` runs a subset; `PW_CHROMIUM=/path/to/chrome` reuses an installed browser).

## Dynamic data safety

The Service Worker must never cache PHP endpoints or community/runtime data such as Daily scores, Hall of Fame, Challenge or Duel state.

Files generated or maintained by the server must remain excluded from FTP deployment where replacing them would destroy production state.

## Rollback

Prefer reverting the production merge commit rather than force-pushing `main`.

After a revert reaches `main`:

1. confirm the deploy workflow succeeds;
2. verify the live site;
3. if a Service Worker/cache change is involved, bump the cache namespace when needed so browsers receive the rollback cleanly.

## Definition of done

A change is complete only when:

- the intended code is merged to `main`;
- automated validation passes;
- production deployment succeeds;
- the live feature is smoke-tested;
- `GAME_MANUAL.md` is updated when the change has functional impact, or the PR explicitly says `No manual impact`;
- any relevant roadmap item is updated as completed.
