# Privacy data inventory

This document maps the privacy copy in `index.html` to the client behavior it
describes. It is an implementation inventory, not a replacement for the
user-facing privacy notice.

## Browser storage

| Key or prefix | Purpose |
| --- | --- |
| `gl-theme` | Light/dark theme preference |
| `ucl_lang`, `dcz_lang` | Language preferences on the database pages and game |
| `dcz_disclaimer`, `dcz_tut` | Dismissed disclaimer and completed tutorial flags |
| `dcz_stats` | Local game statistics and progression |
| `dcz_trophies`, `dcz_formations_won` | Unlocked trophies and winning formations |
| `dcz_daily`, `dcz_daily_run`, `dcz_daily_sent` | Daily challenge history, active day and submission flag |
| `dcz_retro_*` | Completed retro challenge badges |
| `dcz_last_nick`, `dcz_duel_nick` | Nicknames remembered for optional online submissions |
| `dcz_duels` | References and status for up to 20 recent Duels |
| `dcz_hof_pending` | Pending Hall of Fame moderation identifier |

The in-game backup exports all `dcz_*` entries to a file chosen by the user.
Import restores those entries locally. Browser storage stays on the device
unless the user submits data through one of the online features below.

## Optional online features

The client sends data only when the related feature is used:

- Daily and weekly challenge leaderboards send the chosen nickname and game
  result data.
- Hall of Fame submissions send the chosen nickname, campaign configuration,
  result, top scorer and lineup.
- Draft links send the campaign configuration, drafted team and share card
  image when available.
- Duels send the chosen nicknames, mode/configuration, canonical drafted teams,
  draft history and result data required to create, join and resolve a Duel.
- Aggregate counters and game telemetry send gameplay events without an account.

The public privacy notice deliberately describes categories instead of endpoint
schemas so it remains readable. Any new browser key, submitted field category or
third-party service must update this inventory and the IT/EN/ES notice together.

## Analytics

The homepage loads Google Analytics 4 with `anonymize_ip`, Google Signals disabled
and ad-personalization signals disabled. It also loads Microsoft Clarity for
heatmaps and session recordings. These third-party services may process technical
and usage data under their own privacy policies.
