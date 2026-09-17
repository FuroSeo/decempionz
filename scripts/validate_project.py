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

    protected_runtime_files = (
        "game-counter.json",
        "hall-of-fame-pending.json",
        "hall-of-fame.json",
        "challenge-config.json",
        "hof-config.php",
    )
    for filename in protected_runtime_files:
        if filename not in deploy_yml:
            fail(f"Deploy exclusion missing for runtime/server file: {filename}")


def main() -> int:
    index_html = read("index.html")
    sw_js = read("sw.js")
    deploy_yml = read(".github/workflows/deploy.yml")
    read("game-data.js")

    validate_json()
    validate_versions(index_html, sw_js)
    validate_service_worker(sw_js)
    validate_deploy_workflow(deploy_yml)

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
