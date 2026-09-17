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
4. Update `GAME_MANUAL.md` when the change affects gameplay, rules, modes, scoring, tactics, Daily/Weekly, Duel, Dynasty, player/coach behavior, historical content used by the game, or any user-facing mechanic that should be documented. If the change has no manual impact, state that explicitly in the PR.
5. Open a pull request against `main`.
6. Review the diff and confirm that unrelated files are unchanged.
7. Merge only after the change is approved for production.
8. Confirm the FTP deploy workflow completes successfully.
9. Perform a smoke test on the live site.

## Game Manual policy

`GAME_MANUAL.md` is the canonical functional record of how Decempionz works. It must evolve together with the game rather than being updated retrospectively.

Update it in the same PR whenever a change modifies any of the following:

- gameplay rules or match logic visible to players;
- draft behavior, formations, roles, chemistry, tactics, coaches, momentum or modifiers;
- Daily, Weekly, Challenge, Duel or Dynasty rules;
- scoring, grades, leaderboards, streaks, trophies or progression;
- relevant player/team/era data when the change affects the playable experience;
- onboarding, controls or user-facing flows whose explanation belongs in the manual.

Pure infrastructure, CI, deployment, internal refactors with no behavior change, or invisible bug fixes do not require a manual edit, but the PR must explicitly mark `No manual impact`.

A gameplay-affecting change is not considered complete until its manual documentation is updated and reviewed with the code.

## Version meanings

Decempionz currently has three separate revision concepts. They are intentionally not required to have the same value.

### App version

`GAME_VERSION` in `index.html` is the public Decempionz release version shown in the interface and included in exported backups.

Increment it when the public game release changes in a way users should recognise as a new Decempionz version.

### Service Worker cache version

`CACHE` in `sw.js` identifies the browser cache namespace, for example `decempionz-v5.16.4`.

Increment it whenever cached shell/assets must be invalidated. A cache hotfix does not automatically require an app-version bump.

### Game-data asset revision

The query string in `game-data.js?v=...` invalidates the long-lived browser cache configured for `game-data.js`.

Increment it whenever `game-data.js` changes in production.

A future architecture refactor may move these values into a shared release manifest. Until then, their roles must remain explicit and validated.

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
- `GAME_MANUAL.md` is updated when the change has manual impact, or the PR explicitly records `No manual impact`;
- any relevant roadmap item is updated as completed.
