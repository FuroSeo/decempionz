#!/usr/bin/env python3
"""Lightweight repository validation for Decempionz.

The goal is to catch deployment-breaking mistakes before a PR reaches main,
without requiring a build system or external Python dependencies.
"""

from __future__ import annotations

import json
import re
import subprocess
import sys
import tempfile
from html.parser import HTMLParser
from pathlib import Path
from urllib.parse import unquote, urlsplit

ROOT = Path(__file__).resolve().parents[1]
ERRORS: list[str] = []
NOTES: list[str] = []


def fail(message: str) -> None:
    ERRORS.append(message)


def note(message: str) -> None:
    NOTES.append(message)


def read(path: str) -> str:
    target = ROOT / path
    if not target.exists():
        fail(f"Missing required file: {path}")
        return ""
    return target.read_text(encoding="utf-8")


def tracked_files(pattern: str) -> list[Path]:
    try:
        out = subprocess.check_output(
            ["git", "ls-files", pattern], cwd=ROOT, text=True
        )
    except (subprocess.CalledProcessError, FileNotFoundError) as exc:
        fail(f"Unable to list tracked files ({pattern}): {exc}")
        return []
    return [ROOT / line for line in out.splitlines() if line.strip()]


def validate_json() -> None:
    for path in tracked_files("*.json"):
        try:
            json.loads(path.read_text(encoding="utf-8"))
        except Exception as exc:  # noqa: BLE001 - report exact parse failure
            fail(f"Invalid JSON in {path.relative_to(ROOT)}: {exc}")


def validate_versions(index_html: str, sw_js: str) -> None:
    app_match = re.search(r"const\s+GAME_VERSION\s*=\s*['\"]([^'\"]+)['\"]", index_html)
    cache_match = re.search(r"const\s+CACHE\s*=\s*['\"]decempionz-v([^'\"]+)['\"]", sw_js)
    data_match = re.search(r"game-data\.js\?v=([A-Za-z0-9._-]+)", index_html)
    semver = re.compile(r"^\d+\.\d+\.\d+(?:[-+][A-Za-z0-9.-]+)?$")

    if not app_match:
        fail("GAME_VERSION not found in index.html")
    elif not semver.match(app_match.group(1)):
        fail(f"GAME_VERSION is not semantic-version shaped: {app_match.group(1)}")
    else:
        note(f"App version: {app_match.group(1)}")

    if not cache_match:
        fail("Service-worker cache version not found in sw.js")
    elif not semver.match(cache_match.group(1)):
        fail(f"Service-worker cache version is invalid: {cache_match.group(1)}")
    else:
        note(f"Service-worker cache version: {cache_match.group(1)}")

    if data_match:
        note(f"game-data.js asset revision: {data_match.group(1)}")
    else:
        fail("game-data.js cache-busting revision not found in index.html")


def validate_service_worker(sw_js: str) -> None:
    required_fragments = {
        "same-origin guard": "url.origin !== self.location.origin",
        "PHP bypass": "path.endsWith('.php')",
        "Daily dynamic bypass": "path.startsWith('/daily-scores/')",
        "Hall of Fame dynamic bypass": "path.includes('/hall-of-fame')",
        "Challenge dynamic bypass": "path.includes('/challenge-')",
        "Duel dynamic bypass": "path.includes('/duel-')",
        "navigation timeout": "NAV_TIMEOUT_MS",
        "old-cache cleanup": "key.startsWith('decempionz-')",
    }
    for label, fragment in required_fragments.items():
        if fragment not in sw_js:
            fail(f"Service worker guard missing: {label}")


def validate_deploy_workflow(deploy_yml: str) -> None:
    if "branches:\n      - main" not in deploy_yml:
        fail("Deploy workflow is not clearly restricted to pushes on main")

    for secret in ("FTP_SERVER", "FTP_USERNAME", "FTP_PASSWORD"):
        marker = "${{ secrets." + secret + " }}"
        if marker not in deploy_yml:
            fail(f"Deploy workflow must read {secret} from GitHub Secrets")

    deploy_guard_fragments = {
        "pull-request read permission": "pull-requests: read",
        "merged-PR verification step": "Verify main update came from merged PR",
        "commit-to-PR API lookup": 'commits/${{ github.sha }}/pulls',
        "main-base PR filter": '.base.ref == \"main\"',
        "direct-push deploy refusal": "Refusing production deploy: main update is not associated with a merged PR.",
    }
    for label, fragment in deploy_guard_fragments.items():
        if fragment not in deploy_yml:
            fail(f"Production deploy guard missing: {label}")

    protected_runtime_files = (
        "game-counter.json",
        "global-stats.json",
        "hall-of-fame-pending.json",
        "hall-of-fame.json",
        "challenge-config.json",
        "hof-config.php",
        "daily-scores/**",
        "challenge-scores/**",
        "drafts/**",
        "duels/**",
    )
    for filename in protected_runtime_files:
        if filename not in deploy_yml:
            fail(f"Deploy exclusion missing for runtime/server file: {filename}")

    local_only_files = (
        "GAME_MANUAL.md",
        "VADEMECUM.md",
        "ROADMAP.md",
        "RUNTIME_RECOVERY.md",
        "COMPETITIVE_INTEGRITY.md",
        "RECOVERY_INVENTORY.md",
        "dataset-editor.html",
        "_studio.html",
        "_mock_*.html",
        "_build_*.py",
        "_sync_version.py",
        "tools/**",
        "scripts/**",
    )
    for filename in local_only_files:
        if filename not in deploy_yml:
            fail(f"Deploy exclusion missing for local/internal file: {filename}")



class LocalReferenceParser(HTMLParser):
    """Collect static local file references from HTML tags."""

    def __init__(self) -> None:
        super().__init__()
        self.refs: list[tuple[str, str]] = []

    def handle_starttag(
        self, tag: str, attrs: list[tuple[str, str | None]]
    ) -> None:
        values = {key.lower(): value for key, value in attrs if value is not None}
        tag = tag.lower()
        if tag in {"script", "img", "source", "iframe"} and values.get("src"):
            self.refs.append((tag, values["src"]))
        elif tag == "link" and values.get("href"):
            self.refs.append((tag, values["href"]))
        elif tag == "meta" and values.get("content"):
            # Social preview images are absolute production URLs; map them to
            # the local file so a missing og-image.png fails CI.
            key = (values.get("property") or values.get("name") or "").lower()
            content = values["content"]
            prefix = "https://decempionz.com/"
            if key in {"og:image", "twitter:image"} and content.startswith(prefix):
                self.refs.append((tag, "/" + content[len(prefix):]))
        elif tag == "a" and values.get("href"):
            path = urlsplit(values["href"]).path
            if Path(path).suffix.lower() in {
                ".html",
                ".php",
                ".js",
                ".css",
                ".json",
                ".xml",
                ".png",
                ".jpg",
                ".jpeg",
                ".webp",
                ".svg",
                ".ico",
                ".webmanifest",
            }:
                self.refs.append((tag, values["href"]))


def resolve_local_reference(source: Path, ref: str) -> Path | None:
    ref = ref.strip()
    if not ref or ref.startswith(
        ("#", "data:", "javascript:", "mailto:", "tel:", "//")
    ):
        return None
    if any(token in ref for token in ("{{", "}}", "<%", "%>")):
        return None

    parts = urlsplit(ref)
    if parts.scheme or parts.netloc:
        return None

    raw_path = unquote(parts.path)
    if not raw_path:
        return None
    if raw_path == "/":
        return ROOT / "index.html"
    if raw_path.startswith("/"):
        return ROOT / raw_path.lstrip("/")
    return (source.parent / raw_path).resolve()


def validate_local_references() -> None:
    checked = 0
    for path in tracked_files("*.html"):
        try:
            html = path.read_text(encoding="utf-8")
        except UnicodeDecodeError as exc:
            fail(f"Unable to read HTML as UTF-8: {path.relative_to(ROOT)}: {exc}")
            continue

        parser = LocalReferenceParser()
        try:
            parser.feed(html)
        except Exception as exc:  # noqa: BLE001
            fail(f"Unable to parse HTML references in {path.relative_to(ROOT)}: {exc}")
            continue

        for tag, ref in parser.refs:
            target = resolve_local_reference(path, ref)
            if target is None:
                continue
            checked += 1
            if not target.exists():
                try:
                    display = target.relative_to(ROOT)
                except ValueError:
                    display = target
                fail(
                    f"Missing local file referenced by {path.relative_to(ROOT)} "
                    f"<{tag}>: {ref} -> {display}"
                )

    note(f"Static local HTML references checked: {checked}")


def validate_manifest_assets() -> None:
    path = ROOT / "manifest.json"
    try:
        manifest = json.loads(path.read_text(encoding="utf-8"))
    except Exception as exc:  # noqa: BLE001
        fail(f"Unable to parse manifest.json for asset validation: {exc}")
        return

    icons = manifest.get("icons")
    if not isinstance(icons, list) or not icons:
        fail("manifest.json must define at least one icon")
        return

    checked = 0
    for icon in icons:
        if not isinstance(icon, dict):
            fail("manifest.json contains an invalid icon entry")
            continue
        src = icon.get("src")
        if not isinstance(src, str) or not src.strip():
            fail("manifest.json icon is missing src")
            continue
        target = resolve_local_reference(path, src)
        if target is None:
            fail(f"manifest.json icon must be a local asset: {src}")
            continue
        checked += 1
        if not target.exists():
            fail(f"Missing manifest icon: {src}")

    start_url = manifest.get("start_url")
    if isinstance(start_url, str):
        target = resolve_local_reference(path, start_url)
        if target is not None and not target.exists():
            fail(f"manifest.json start_url does not resolve locally: {start_url}")

    note(f"Manifest assets checked: {checked}")


def validate_runtime_backup() -> None:
    htaccess = read(".htaccess")
    backup_lib = read("runtime-backup-lib.php")
    recovery_lib = read("runtime-recovery-lib.php")
    recovery = read("runtime-recovery.php")
    health_endpoint = read("runtime-health.php")
    backup_test = read("scripts/test_runtime_backup.php")

    if "PHP_SAPI !== 'cli'" not in recovery:
        fail("Runtime recovery tool must refuse web execution")

    for filename in (
        "runtime-backup-lib.php",
        "runtime-recovery-lib.php",
        "runtime-recovery.php",
    ):
        if filename.replace(".", "\\.") not in htaccess and filename not in htaccess:
            fail(f".htaccess must deny direct HTTP access to {filename}")

    if "dirname(__DIR__)" not in backup_lib or "decempionz-runtime-backups" not in backup_lib:
        fail("Runtime backups must default outside public_html")
    if "json_decode" not in backup_lib:
        fail("Runtime backup library must validate JSON before snapshotting")
    if "dcz_backup_status_summary" not in backup_lib:
        fail("Runtime backup library must expose a coarse status summary")

    recovery_guards = {
        "CLI-only runtime-root override": "PHP_SAPI === 'cli'",
        "isolated runtime-root override": "DCZ_RUNTIME_ROOT",
        "snapshot verification": "dcz_recovery_verify_snapshot",
        "restore primitive": "dcz_recovery_restore_snapshot",
        "pre-restore safety snapshot": "dcz_backup_snapshot($category, $logicalName, $currentRaw)",
        "corrupt-target quarantine": "dcz_recovery_quarantine_current",
        "atomic target replace": "@rename($tmp, $target)",
    }
    for label, fragment in recovery_guards.items():
        if fragment not in recovery_lib:
            fail(f"Runtime recovery guard missing: {label}")

    health_guards = {
        "GET-only health endpoint": "$_SERVER['REQUEST_METHOD'] !== 'GET'",
        "coarse backup summary": "dcz_backup_status_summary",
        "writable status": "'writable'",
        "snapshot-seen status": "'snapshotSeen'",
        "recent status": "'recent'",
    }
    for label, fragment in health_guards.items():
        if fragment not in health_endpoint:
            fail(f"Runtime health endpoint guard missing: {label}")
    if "dcz_backup_root(" in health_endpoint or "['root']" in health_endpoint or '"root"' in health_endpoint:
        fail("Runtime health endpoint must not expose the private backup path")

    restore_test_guards = {
        "isolated backup root": "DCZ_BACKUP_DIR",
        "isolated runtime root": "DCZ_RUNTIME_ROOT",
        "restore-on-copy": "dcz_recovery_restore_snapshot",
        "pre-restore snapshot assertion": "pre-restore target state not recoverable",
        "corrupt-target restore": "restore over corrupt target failed",
        "corrupt-target quarantine": "corrupt target was not quarantined",
    }
    for label, fragment in restore_test_guards.items():
        if fragment not in backup_test:
            fail(f"Runtime recovery test guard missing: {label}")

    writers = {
        "game-counter.php": "'game-counter', 'main'",
        "global-stats.php": "'global-stats', 'main'",
        "daily-submit.php": "'daily', $day",
        "challenge-submit.php": "'challenge', $weekId",
        "hof-submit.php": "'hall-of-fame', 'main'",
    }
    for filename, marker in writers.items():
        text = read(filename)
        if "runtime-backup-lib.php" not in text:
            fail(f"Runtime backup library not loaded by durable writer: {filename}")
        if "dcz_backup_snapshot(" not in text or marker not in text:
            fail(f"Runtime snapshot hook missing from durable writer: {filename}")


def validate_draft_storage() -> None:
    htaccess = read(".htaccess")
    storage_lib = read("draft-storage-lib.php")
    draft_save = read("draft-save.php")
    draft_img = read("draft-img.php")

    protected_markers = ("draft-storage-lib\\.php", "\\.draft-auth")
    for marker in protected_markers:
        if marker not in htaccess:
            fail(f"Draft storage HTTP protection missing from .htaccess: {marker}")

    lib_guards = {
        "short-lived upload grant": "DCZ_DRAFT_UPLOAD_TTL = 900",
        "one-year retention": "DCZ_DRAFT_RETENTION_SECONDS = 31536000",
        "HttpOnly upload cookie": "'httponly' => true",
        "strict SameSite upload cookie": "'samesite' => 'Strict'",
        "server-side token hash": "hash('sha256', $token)",
        "paired draft cleanup": "dcz_draft_cleanup",
    }
    for label, fragment in lib_guards.items():
        if fragment not in storage_lib:
            fail(f"Draft storage guard missing: {label}")

    if "dcz_draft_create_upload_grant" not in draft_save or "dcz_draft_set_upload_cookie" not in draft_save:
        fail("Draft creation must issue a short-lived image upload grant")
    if "dcz_draft_maybe_cleanup" not in draft_save:
        fail("Draft creation must trigger bounded retention cleanup")

    upload_guards = (
        "dcz_draft_cookie_token_for_id",
        "dcz_draft_validate_upload_grant",
        "dcz_draft_consume_upload_grant",
        "flock($draftFp, LOCK_EX)",
    )
    for marker in upload_guards:
        if marker not in draft_img:
            fail(f"Draft image write-once authorization guard missing: {marker}")


def validate_duel_integrity() -> None:
    htaccess = read(".htaccess")
    duel_lib = read("duel-lib.php")
    draft_lib = read("duel-draft-lib.php")
    draft_endpoint = read("duel-draft-session.php")
    engine = read("duel-engine.php")
    endpoint = read("duel-result.php")
    create = read("duel-create.php")
    join = read("duel-join.php")
    duel_page = read("duel.html")
    index_html = read("index.html")
    integrity_doc = read("COMPETITIVE_INTEGRITY.md")

    for filename in ("duel-engine.php", "duel-draft-lib.php"):
        escaped = filename.replace(".", "\\.")
        if escaped not in htaccess and filename not in htaccess:
            fail(f".htaccess must deny direct HTTP access to {filename}")

    dataset_guards = {
        "canonical dataset registry": "dcz_duel_dataset_registry",
        "canonical team validation": "dcz_duel_validate_team_dataset",
        "source team id": "teamId",
        "game-data source": "game-data.js",
    }
    for label, fragment in dataset_guards.items():
        if fragment not in duel_lib:
            fail(f"Duel squad integrity guard missing: {label}")

    draft_guards = {
        "draft engine version": "DCZ_DUEL_DRAFT_ENGINE_VERSION",
        "private runtime directory": ".draft-sessions",
        "bounded runtime sessions": "DCZ_DUEL_DRAFT_MAX_SESSIONS",
        "runtime HTTP deny": "Require all denied",
        "server pool builder": "dcz_draft_build_pool",
        "server card draw": "dcz_draft_draw_cards",
        "versioned actions": "dcz_draft_session_action",
        "offer history": "'history'=>[]",
        "final team verification": "dcz_draft_verify_completed_session",
        "single-use session": "consumedAt",
    }
    for label, fragment in draft_guards.items():
        if fragment not in draft_lib:
            fail(f"Server-authoritative Duel draft guard missing: {label}")

    endpoint_draft_guards = {
        "start action": "$action === 'start'",
        "player B Duel binding": "$config['role'] === 'b'",
        "classic scope binding": "duel scope mismatch",
        "versioned pick": "dcz_draft_session_action($id, $version, 'pick'",
        "versioned reroll": "dcz_draft_session_action($id, $version, 'reroll'",
    }
    for label, fragment in endpoint_draft_guards.items():
        if fragment not in draft_endpoint:
            fail(f"Duel draft endpoint guard missing: {label}")

    engine_guards = {
        "engine version": "DCZ_DUEL_ENGINE_VERSION",
        "Match Engine v3 marker": "server-v3",
        "server series simulation": "dcz_duel_simulate_series",
        "deterministic RNG": "dcz_duel_rng_float",
        "Poisson goals": "dcz_duel_poisson",
        "server penalties": "dcz_duel_penalties",
        "occupied-slot departments": "$group = dcz_duel_pos_group($slot);",
        "server Team Score": "'score' => (int)round(dcz_duel_avg($allEffective) * 10)",
        "canonical Chemistry": "dcz_duel_team_chemistry",
        "symmetric Chemistry multiplier": "$xa *= (float)($a['chemistry']['mul'] ?? 1.0)",
        "official public metrics": "dcz_duel_public_team_metrics",
    }
    for label, fragment in engine_guards.items():
        if fragment not in engine:
            fail(f"Server-authoritative Duel engine guard missing: {label}")

    for filename, content in (("duel-create.php", create), ("duel-result.php", endpoint)):
        if "dcz_draft_verify_completed_session" not in content or "draftSessionId" not in content:
            fail(f"{filename} must require a completed server-authoritative Duel draft")

    endpoint_guards = {
        "engine loaded": "duel-engine.php",
        "authoritative simulation": "dcz_duel_simulate_series",
        "private replay seed": "_serverSeed",
        "server-authoritative marker": "server-authoritative",
        "dataset verification": "dcz_duel_validate_team_dataset",
        "draft B proof": "_draftB",
        "client result explicitly ignored": "risultato inviato dal client è intenzionalmente ignorato",
        "legacy simulating migration": "Migrazione trasparente",
        "counter snapshot": "dcz_backup_snapshot('game-counter', 'main'",
    }
    for label, fragment in endpoint_guards.items():
        if fragment not in endpoint:
            fail(f"Duel result authority guard missing: {label}")

    if "_draftA" not in create:
        fail("Duel creation must persist the private player-A draft proof")
    if "dcz_sanitize_result($data['result']" in endpoint or "$d['result'] = $data['result']" in endpoint:
        fail("Duel endpoint must never trust the client-submitted match result")
    if "echo json_encode($d" in join:
        fail("Duel public state endpoint must not serialize raw server state")
    if "'result'     => null" not in join:
        fail("Simulating Duel response must hide server-internal finalization state")
    if "'teams'" in join.split("/* waiting: esporre solo i vincoli", 1)[-1]:
        fail("Waiting Duel response must not reveal official team metrics")
    for marker in ("Match Engine v3", "r.teams", "metrics.chemistry", "engine-badge"):
        if marker not in duel_page:
            fail(f"Public Duel verdict metrics missing: {marker}")

    client_guards = {
        "player source sent by draft": "teamId:p.teamId||''",
        "server draft start": "_duelInitServerDraft",
        "server draft endpoint": "duel-draft-session.php",
        "versioned draft state": "DUEL.draftVersion",
        "server pick routing": "_duelDraftAction('pick'",
        "server reroll routing": "_duelDraftAction('reroll'",
        "draft proof submission": "draftSessionId:DUEL.draftSessionId",
        "authoritative result consumer": "duelUseAuthoritativeResult",
        "server result required": "res.body.result",
        "server authority analytics marker": "authority:'server'",
        "shared lineup evaluator": "evaluateLineup(team.players||[],pos",
        "campaign Team Score": "teamScore:teamEval.score",
    }
    for label, fragment in client_guards.items():
        if fragment not in index_html:
            fail(f"Duel client authority guard missing: {label}")
    if "phase:'result',result:res" in index_html:
        fail("Duel client must not submit locally simulated match scores")

    if "server-authoritative" not in integrity_doc:
        fail("Competitive integrity documentation must state Duel result authority")
    if "server-offers-authoritative" not in integrity_doc:
        fail("Competitive integrity documentation must state Duel draft-offer authority")


SITE_URL = "https://decempionz.com/"


def _site_path(url: str) -> str | None:
    """Map a canonical/hreflang URL of this site to its repository file path."""
    if not url.startswith(SITE_URL):
        return None
    path = url[len(SITE_URL):]
    if path == "" or path.endswith("/"):
        path += "index.html"
    return path


def validate_seo_alternates() -> None:
    """hreflang sets must be reciprocal, localized pages must not reuse Italian keywords, and every
    page with an og:image declares a twitter:card."""
    pages: dict[str, tuple[dict[str, str], str | None, str | None]] = {}
    for path in tracked_files("*.html"):
        rel = path.relative_to(ROOT).as_posix()
        if rel.startswith(("scripts/", "duels/")):
            continue
        html = path.read_text(encoding="utf-8")
        alternates = dict(
            re.findall(r'<link rel="alternate" hreflang="([^"]+)" href="([^"]+)"', html)
        )
        if 'property="og:image"' in html and 'name="twitter:card"' not in html:
            fail(f"{rel}: has og:image but no twitter:card (X/Twitter falls back to a small summary card)")
        canonical = re.search(r'<link rel="canonical" href="([^"]+)"', html)
        keywords = re.search(r'<meta name="keywords" content="([^"]*)"', html)
        pages[rel] = (
            alternates,
            canonical.group(1) if canonical else None,
            keywords.group(1) if keywords else None,
        )

    for rel, (alternates, canonical, keywords) in pages.items():
        if not alternates:
            continue
        own = _site_path(canonical) if canonical else None
        if own != rel:
            fail(f"{rel}: canonical URL does not point to itself ({canonical})")
        if own is not None and canonical not in alternates.values():
            fail(f"{rel}: hreflang set does not include the page itself")
        for lang, url in alternates.items():
            target = _site_path(url)
            if target is None or target not in pages:
                fail(f"{rel}: hreflang {lang} points to a missing page ({url})")
                continue
            if pages[target][0] != alternates:
                fail(f"{rel}: hreflang set is not reciprocal with {target}")
        # localized pages need their own keywords, not a copy of the Italian ones
        if rel.startswith(("en/", "es/")) and not rel.startswith(("en/rose/", "es/rose/")):
            it_rel = rel.split("/", 1)[1]
            it_keywords = pages.get(it_rel, ({}, None, None))[2]
            if keywords and it_keywords and keywords == it_keywords:
                fail(f"{rel}: keywords are identical to the Italian page {it_rel}")


def validate_sitemap_indexability() -> None:
    """Pages that only make sense with a query string (shared draft, duel invite) must be noindex,
    and no sitemap URL may point to a noindex page."""
    for rel in ("draft.html", "duel.html"):
        html = read(rel)
        if not re.search(r'<meta name="robots" content="[^"]*noindex', html):
            fail(f"{rel}: parameter-driven page must declare noindex")
    sitemap = read("sitemap.xml")
    for url in re.findall(r"<loc>([^<]+)</loc>", sitemap):
        rel = _site_path(url)
        if rel is None or not (ROOT / rel).exists():
            fail(f"sitemap.xml: {url} does not map to a file")
            continue
        html = (ROOT / rel).read_text(encoding="utf-8")
        if re.search(r'<meta name="robots" content="[^"]*noindex', html):
            fail(f"sitemap.xml: {url} is noindex")


def validate_render_blocking_fonts() -> None:
    """The Google Fonts stylesheet must not block the first paint: it is preloaded and applied on load,
    with a plain <link> only inside <noscript>."""
    html = read("index.html")
    without_noscript = re.sub(r"<noscript>.*?</noscript>", "", html, flags=re.DOTALL)
    for tag in re.findall(r"<link\b[^>]*>", without_noscript):
        if "fonts.googleapis.com/css" in tag and re.search(r'rel="stylesheet"', tag):
            fail("index.html: Google Fonts stylesheet is render-blocking; preload it and apply it on load")
    if 'rel="preload" as="style" href="https://fonts.googleapis.com/css2' not in html or "<noscript><link" not in html:
        fail("index.html: non-blocking Google Fonts preload or its noscript fallback is missing")


def extract_inline_javascript(html: str) -> list[str]:
    """Return executable inline JS blocks, excluding src scripts and data script types."""
    blocks: list[str] = []
    for match in re.finditer(
        r"<script\b([^>]*)>(.*?)</script>",
        html,
        flags=re.IGNORECASE | re.DOTALL,
    ):
        attrs, body = match.group(1), match.group(2)
        if re.search(r"\bsrc\s*=", attrs, flags=re.IGNORECASE):
            continue
        type_match = re.search(
            r"\btype\s*=\s*(['\"]?)([^\s'\">]+)\1",
            attrs,
            flags=re.IGNORECASE,
        )
        if type_match:
            script_type = type_match.group(2).lower()
            if script_type not in (
                "text/javascript",
                "application/javascript",
                "module",
            ):
                continue
        blocks.append(body)
    return blocks


def validate_inline_javascript(filename: str, label: str) -> None:
    path = ROOT / filename
    if not path.exists():
        fail(f"Missing HTML file for inline JavaScript validation: {filename}")
        return

    scripts = extract_inline_javascript(path.read_text(encoding="utf-8"))
    if not scripts:
        fail(f"No executable inline JavaScript found in {filename}")
        return

    combined = "\n;\n".join(scripts)
    tmp_path: Path | None = None
    try:
        with tempfile.NamedTemporaryFile(
            mode="w", suffix=".js", encoding="utf-8", delete=False
        ) as tmp:
            tmp.write(combined)
            tmp_path = Path(tmp.name)

        result = subprocess.run(
            ["node", "--check", str(tmp_path)],
            cwd=ROOT,
            text=True,
            capture_output=True,
            check=False,
        )
        if result.returncode != 0:
            detail = (result.stderr or result.stdout).strip()
            fail(f"Inline JavaScript syntax error in {filename}: {detail}")
        else:
            note(f"{label} inline JavaScript syntax OK: {filename}")
    except FileNotFoundError:
        fail("Node.js is required to validate inline JavaScript")
    finally:
        if tmp_path is not None and tmp_path.exists():
            tmp_path.unlink()


def validate_html_javascript() -> None:
    """Syntax-check the production app plus recovered local-only HTML tools."""
    validate_inline_javascript("index.html", "Production app")
    validate_inline_javascript("duel.html", "Public Duel page")
    for filename in ("dataset-editor.html", "_studio.html"):
        if (ROOT / filename).exists():
            validate_inline_javascript(filename, "Recovered tool")


def main() -> int:
    index_html = read("index.html")
    sw_js = read("sw.js")
    deploy_yml = read(".github/workflows/deploy.yml")
    read("game-data.js")

    validate_json()
    validate_versions(index_html, sw_js)
    validate_service_worker(sw_js)
    validate_deploy_workflow(deploy_yml)
    validate_local_references()
    validate_manifest_assets()
    validate_runtime_backup()
    validate_draft_storage()
    validate_duel_integrity()
    validate_html_javascript()
    validate_seo_alternates()
    validate_sitemap_indexability()
    validate_render_blocking_fonts()

    for message in NOTES:
        print(f"[info] {message}")

    if ERRORS:
        print("\nValidation failed:", file=sys.stderr)
        for message in ERRORS:
            print(f"  - {message}", file=sys.stderr)
        return 1

    print("\nDecempionz repository validation passed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
