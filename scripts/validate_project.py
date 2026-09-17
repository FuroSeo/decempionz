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
from pathlib import Path

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
    )
    for filename in local_only_files:
        if filename not in deploy_yml:
            fail(f"Deploy exclusion missing for local/internal file: {filename}")


def validate_runtime_backup() -> None:
    htaccess = read(".htaccess")
    backup_lib = read("runtime-backup-lib.php")
    recovery = read("runtime-recovery.php")

    if "PHP_SAPI !== 'cli'" not in recovery:
        fail("Runtime recovery tool must refuse web execution")
    for filename in ("runtime-backup-lib.php", "runtime-recovery.php"):
        if filename.replace(".", "\\.") not in htaccess and filename not in htaccess:
            fail(f".htaccess must deny direct HTTP access to {filename}")

    if "dirname(__DIR__)" not in backup_lib or "decempionz-runtime-backups" not in backup_lib:
        fail("Runtime backups must default outside public_html")
    if "json_decode" not in backup_lib:
        fail("Runtime backup library must validate JSON before snapshotting")

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
    engine = read("duel-engine.php")
    endpoint = read("duel-result.php")
    join = read("duel-join.php")
    integrity_doc = read("COMPETITIVE_INTEGRITY.md")

    if "duel-engine\\.php" not in htaccess and "duel-engine.php" not in htaccess:
        fail(".htaccess must deny direct HTTP access to duel-engine.php")

    engine_guards = {
        "engine version": "DCZ_DUEL_ENGINE_VERSION",
        "server series simulation": "dcz_duel_simulate_series",
        "deterministic RNG": "dcz_duel_rng_float",
        "Poisson goals": "dcz_duel_poisson",
        "server penalties": "dcz_duel_penalties",
    }
    for label, fragment in engine_guards.items():
        if fragment not in engine:
            fail(f"Server-authoritative Duel engine guard missing: {label}")

    endpoint_guards = {
        "engine loaded": "duel-engine.php",
        "authoritative simulation": "dcz_duel_simulate_series",
        "hidden server result": "_serverResult",
        "server-authoritative marker": "server-authoritative",
        "client result explicitly ignored": "risultato inviato dal client è intenzionalmente ignorato",
        "legacy simulating migration": "Migrazione trasparente",
        "counter snapshot": "dcz_backup_snapshot('game-counter', 'main'",
    }
    for label, fragment in endpoint_guards.items():
        if fragment not in endpoint:
            fail(f"Duel result authority guard missing: {label}")

    if "dcz_sanitize_result($data['result']" in endpoint or "$d['result'] = $data['result']" in endpoint:
        fail("Duel endpoint must never trust the client-submitted match result")
    if "echo json_encode($d" in join:
        fail("Duel public state endpoint must not serialize raw server state")
    if "'result'     => null" not in join:
        fail("Simulating Duel response must hide the precomputed authoritative result")
    if "$rating < 6 || $rating > 10" not in duel_lib:
        fail("Duel team validator must enforce the dataset-compatible 6..10 rating range")
    if "server-authoritative" not in integrity_doc or "client-submitted" not in integrity_doc:
        fail("Competitive integrity documentation must state Duel result and squad trust levels")


def validate_local_html_tools() -> None:
    """Syntax-check inline JavaScript in recovered local-only HTML tools."""
    for filename in ("dataset-editor.html", "_studio.html"):
        path = ROOT / filename
        if not path.exists():
            continue

        html = path.read_text(encoding="utf-8")
        scripts = re.findall(
            r"<script(?![^>]*\bsrc\s*=)[^>]*>(.*?)</script>",
            html,
            flags=re.IGNORECASE | re.DOTALL,
        )
        if not scripts:
            fail(f"No inline JavaScript found in recovered tool: {filename}")
            continue

        combined = "\n;\n".join(scripts)
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
                note(f"Recovered tool JavaScript syntax OK: {filename}")
        except FileNotFoundError:
            fail("Node.js is required to validate recovered local HTML tools")
        finally:
            if "tmp_path" in locals() and tmp_path.exists():
                tmp_path.unlink()


def main() -> int:
    index_html = read("index.html")
    sw_js = read("sw.js")
    deploy_yml = read(".github/workflows/deploy.yml")
    read("game-data.js")

    validate_json()
    validate_versions(index_html, sw_js)
    validate_service_worker(sw_js)
    validate_deploy_workflow(deploy_yml)
    validate_runtime_backup()
    validate_draft_storage()
    validate_duel_integrity()
    validate_local_html_tools()

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
