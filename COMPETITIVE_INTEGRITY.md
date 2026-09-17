# Decempionz — Competitive Integrity

Last reviewed: 2026-09-17

This document records what the backend can actually prove for user-submitted competitive/community data. It intentionally distinguishes structural validation from authoritative game verification.

## Trust model

| Surface | Current result trust | Current squad/run trust | Notes |
| --- | --- | --- | --- |
| Duel 1v1 | **server-authoritative** for newly finalized duels | **client-submitted** squads, structurally validated | The server generates and stores the best-of-3 atomically when B commits. Client-reported scores are ignored. |
| Daily leaderboard | client-reported | client-reported | Date/range/rate-limit/storage validation makes abuse harder but does not prove the browser played the exact run. |
| Weekly Challenge | client-reported | client-reported | Score/config consistency and rate limiting are validation layers, not cryptographic proof of play. |
| Hall of Fame | client-reported showcase | client-reported | Sanitization, throttling and admin removal protect the service; entries are not an anti-cheat competitive record. |

## Duel 1v1 — server-authoritative result

For duels created/completed after the server-authoritative engine rollout:

1. Player A creates a duel and commits a sanitized squad.
2. Player B drafts without seeing A's full squad, then submits a sanitized squad.
3. Under the same exclusive server lock, the backend generates fresh server randomness, computes the best-of-3 with `duel-engine.php`, stores B's squad and stores the authoritative result.
4. Only after that atomic commit does the endpoint return A's squad to B. New duels therefore move directly from `waiting` to `done`.
5. The existing browser may still calculate a local series for compatibility, but `duel-result.php` **does not trust or use that client result**.
6. The browser's follow-up `phase=result` request receives the already-finalized state and redirects to the canonical Duel page, preventing a different locally generated series from being displayed as authoritative.
7. The stored record is marked `integrity.result = server-authoritative` and records the server engine version.

The server engine intentionally mirrors the current Duel coefficients: positional penalties, formation/tactic interactions, star/rating-10 bonuses, draw pull, Poisson goals and penalties. Moving authority to the server is not intended to rebalance Duel.

### Remaining Duel limitation

Squad provenance is still **client-submitted**. The backend rejects malformed structures, unknown formations/tactics, unsupported positions and ratings outside the dataset-compatible 6–10 range, but it does not yet prove that every submitted XI came from the exact offers shown by the browser. Player display names are not treated as canonical identities because the same historical label can legitimately occur in multiple squads/eras.

A future high-assurance design should use a server-issued draft session (or signed deterministic seed + choice history) and validate every accepted card against the canonical dataset using stable player/source identifiers. That is a larger architecture change and should be implemented without adding account friction.

## Backward compatibility

- Already completed historical duels remain readable and are exposed as `legacy-client-reported` when no integrity metadata exists.
- A historical duel left in `simulating` state is migrated on its next finalization request: the server generates and stores a fresh authoritative result from the already committed squads.
- Public Duel URLs and create/join flows remain unchanged.
- The current B-side animated replay is temporarily replaced by redirecting to the canonical result page after authoritative finalization. The redirect restores B's local Duel history through a short-lived HttpOnly marker. Restoring the in-app animation safely requires the client to consume the server result rather than a locally generated result.

## Daily / Weekly / Hall of Fame

These modes currently use a casual integrity model: strict request validation, payload/range checks, throttling and locked/fail-safe storage. Those controls protect availability and obvious manipulation, but they do not prove the entire gameplay history.

If these surfaces become materially competitive (prizes, public rankings with stakes, etc.), use server-issued run tokens/deterministic seeds and replayable choice/result history before treating them as verified records.

## Security boundary

No client-controlled result should be described as verified merely because it passed schema/range validation. When adding a competitive surface, document separately:

- who chooses randomness;
- who computes the result;
- what the server can replay or verify;
- whether squads/choices are authoritative or client-submitted;
- migration behavior for existing records.
