# Decempionz — Dataset Integrity

Last reviewed: 2026-09-18

`game-data.js` is the canonical football dataset used by the browser and by server-side Duel verification.

## Current baseline

| Family | Teams | Players | Tournament groups |
| --- | ---: | ---: | ---: |
| UCL | 83 | 1326 | 10 |
| Copa Libertadores | 51 | 653 | 5 |
| World Cup | 87 | 1030 | 7 |

All current tournament references resolve to an existing team and every team is referenced by at least one tournament group.

Ratings are integers in the supported 6–10 range. Every roster has at least 11 players.

## Canonical identity

A raw `teamId` is **not globally unique across tournament families**.

The known example is:

- UCL `atm_1314` → Atletico Madrid 2013-14
- Copa `atm_1314` → Atlético Mineiro 2013

Therefore server code must treat the canonical team key as:

`<tournament family> + <teamId>`

The Duel dataset parsers are intentionally grouped by `ucl`, `copa` and `wc` so one family cannot overwrite another.

## CI validation

`scripts/test_game_data.js` evaluates the plain data file in an isolated Node VM and checks:

- required team metadata;
- safe team/club/tournament identifiers;
- roster size bounds;
- supported positions;
- integer ratings in the 6–10 range;
- nationality-code format when present;
- missing tournament references;
- duplicate references inside a tournament;
- orphan teams not used by any tournament;
- duplicate player names inside one roster;
- cross-family `teamId` collisions.

Known legacy anomalies are explicit warnings. Any **new** same-name collision or new cross-family short-ID collision fails CI until reviewed.

## Known legacy warnings

The following same-name entries already exist in the Copa data and require historical review before editing:

- `sao_0506 / Danilo` — includes one exact duplicate record;
- `sao_0506 / Junior` — appears twice with different listed roles/ratings;
- `arj_8485 / Borghi` — appears twice with different ratings.

These warnings are allowlisted only to make the current baseline testable. They are not considered verified-correct data.

Nationality (`nat`) remains optional because the recovered World Cup dataset does not currently store it consistently. When present, it must be a 2–3 letter uppercase code (the historical dataset uses values such as `WLS` and `NIR`).

## Editing rule

For future dataset changes:

1. edit through the Dataset Editor or a reviewable branch;
2. update the `game-data.js?v=...` revision if the runtime data changes;
3. run `python _build_rose.py` if generated squad pages are affected;
4. run `node scripts/test_game_data.js`;
5. run `python scripts/test_generators.py`;
6. run `python scripts/validate_project.py`;
7. inspect generated/content diffs before opening the PR.
