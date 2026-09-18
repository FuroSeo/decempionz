# Decempionz — Competitive Integrity

Last reviewed: 2026-09-18

This document records what the backend can actually prove for user-submitted competitive/community data. Structural validation, canonical dataset validation and full proof of a gameplay run are deliberately kept separate.

## Trust model

| Surface | Result trust | Squad/run trust | Notes |
| --- | --- | --- | --- |
| Duel 1v1 | **server-authoritative** for newly finalized duels | **dataset-source-verified** squad data; exact draft history **client-unverified** | The server verifies every submitted player against `game-data.js`, chooses private randomness and computes the official best-of-3. |
| Daily leaderboard | client-reported | client-reported | Date/range/rate-limit/storage validation makes abuse harder but does not prove the browser played the exact run. |
| Weekly Challenge | client-reported | client-reported | Score/config consistency and rate limiting are validation layers, not cryptographic proof of play. |
| Hall of Fame | client-reported showcase | client-reported | Sanitization, throttling and admin removal protect the service; entries are not an anti-cheat competitive record. |

## Duel 1v1 — authoritative result and canonical squad sources

For new Duel submissions:

1. The browser sends each drafted player as `{n,p,r,teamId}`.
2. The backend parses the three canonical dataset families in `game-data.js` without executing JavaScript.
3. For every player it verifies the exact `teamId + name + position + rating` tuple against that historical squad.
4. Classic Duel sources must belong to the Duel tournament. Dynasty sources must belong to the submitted tournament family and the selected historical club.
5. Player B commits the second squad without seeing A's full XI.
6. Under the exclusive Duel-file lock, the backend generates a private random seed and computes the best-of-3 with `duel-engine.php`.
7. The seed and engine version remain private in the server JSON; the public API exposes only the official result and integrity metadata.
8. The browser consumes that server result for the normal in-app match animation. It no longer sends locally generated scores as the official result.
9. Any legacy `phase=result` payload is ignored by the backend.

The server engine mirrors the current Duel coefficients for positional penalties, tactic/counter interactions, star and rating-10 bonuses, xG, Poisson goals and penalties. Moving authority to the server is intended as an integrity change, not a balance change.

A stored private seed makes a newly generated Duel replayable by the server: the same teams, engine version and seed reproduce the same series.

### Remaining Duel limitation

The server now proves that the submitted players are real canonical dataset entries from allowed source squads, but it **does not yet prove the exact browser draft history**.

In particular, the current monolithic client does not send a server-issued draft session or signed choice history. A modified client could therefore try to assemble a different combination of otherwise valid players from the allowed tournament (or, in Dynasty, from the selected club) rather than only the cards actually offered during that run. Classic era/card-offer provenance is not yet cryptographically bound.

The higher-assurance follow-up for issue #13 is a server-issued draft seed/session with replayable offers and accepted-choice history. That is a larger architecture step and should be introduced without account friction.

## Backward compatibility

- Already completed historical duels remain readable and are exposed as `legacy-client-reported` when no integrity metadata exists.
- A historical duel left in `simulating` is finalized on its next request with a fresh server-authoritative result.
- A historical waiting duel whose A-side predates `teamId` can still finish; its integrity metadata is marked `mixed-legacy-a`.
- A stale browser tab running the old protocol cannot overwrite the official result; it is redirected to the canonical Duel page when it submits its local series.
- Public Duel URLs remain unchanged.

## Daily / Weekly / Hall of Fame

These modes currently use a casual integrity model: strict request validation, payload/range checks, throttling and locked/fail-safe storage. Those controls protect availability and obvious manipulation, but they do not prove the entire gameplay history.

If these surfaces become materially competitive (prizes, public rankings with stakes, etc.), use server-issued run tokens/deterministic seeds and replayable choice/result history before treating them as verified records.

## Security boundary

No client-controlled result should be described as verified merely because it passed schema/range validation. When adding a competitive surface, document separately:

- who chooses randomness;
- who computes the result;
- what the server can replay or verify;
- whether squad entries are canonical dataset records;
- whether the exact draft/run history is authoritative or client-unverified;
- migration behavior for existing records.
