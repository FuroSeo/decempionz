# Decempionz — Dataset Integrity

Last reviewed: 2026-09-18

`game-data.js` is the canonical football dataset used by the browser and by server-side Duel verification.

## Current baseline

| Family | Teams | Players | Tournament groups |
| --- | ---: | ---: | ---: |
| UCL | 83 | 1326 | 10 |
| Copa Libertadores | 51 | 651 | 5 |
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

Same-name duplicates inside a single roster are now a hard CI failure. There is no legacy allowlist for duplicate player names.

The 2026-09-18 review removed three verified duplicate entries:
- one duplicate Danilo and one duplicate Junior from São Paulo 2004-05;
- one duplicate Borghi from Argentinos Juniors 1984-85.

Historical evidence used for the duplicate review:
- São Paulo FC 2005 Libertadores registration: https://www.saopaulofc.net/enciclopedia-jogadores-listas-de-inscritos/
- São Paulo FC 2005 Libertadores campaign/final: https://www.saopaulofc.net/campeao-da-conmebol-libertadores-2005/

### Argentinos Juniors 1984-85 verified rebuild

The former `arj_8485` entry mixed players from unrelated teams/seasons. It was rebuilt on 2026-09-18 as a compact **13-player representative Libertadores squad**, matching the size/style of other Decempionz historical rosters rather than pretending to be a complete registration list.

Composition rules:
- the core XI comes from the documented 1985 Libertadores campaign;
- Jorge Pellegrini and Renato Corsi are included because both are documented in decisive Copa matches, including the final series;
- players not supported by the 1985 Argentinos evidence were removed;
- positions are based on historical role sources, then mapped to the nearest Decempionz position code;
- ratings are game-design values, not historical-source claims. They were reviewed against neighboring Copa champion squads so the rebuild does not receive an arbitrary power spike.

Verified sources:
- Argentinos Juniors, deciding final history (official club): https://argentinosjuniors.com.ar/noticias/depto-de-historia/el-futbol-de-la-paternal-hecho-poesia/
- Argentinos Juniors, Libertadores title history (official club): https://argentinosjuniors.com.ar/el-club/titulos/
- Argentinos Juniors first Libertadores match + squad/substitutes (RSSSF): https://www.rsssf.org/tablesa/argjuniors.html
- Copa Libertadores 1985 results/scorers (RSSSF): https://www.rsssf.org/sacups/copa85.html
- Adrián Domenech profile/role (official club): https://argentinosjuniors.com.ar/noticias/depto-de-historia/tradicion-el-ruso-domenech/
- Sergio Batista profile (official club): https://argentinosjuniors.com.ar/noticias/actualidad/una-historia-de-amor/
- Argentinos Juniors academy/historical player index, including Renato Corsi (official club): https://argentinosjuniors.com.ar/semillero-del-mundo/
- Renato Corsi historical position: https://www.bdfa.com.ar/cronologico-RENATO-CORSI-3065.html
- José Antonio Castro historical position: https://www.bdfa.com.ar/jugadores-JOSE-ANTONIO-CASTRO-964.html

The CI fixture now requires the verified `arj_8485` names/roles and explicitly rejects the contaminated legacy names that were removed.

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
