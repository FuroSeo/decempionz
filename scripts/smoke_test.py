#!/usr/bin/env python3
"""Minimal localhost HTTP smoke test for Decempionz.

Starts PHP's built-in server and verifies that the main public entry points,
static assets and PWA icons are actually served from the repository. No external
network calls are made.
"""

from __future__ import annotations

import json
import shutil
import socket
import subprocess
import sys
import time
from pathlib import Path
from urllib.error import HTTPError, URLError
from urllib.request import urlopen

ROOT = Path(__file__).resolve().parents[1]
HOST = "127.0.0.1"

CHECKS: tuple[tuple[str, str | None], ...] = (
    ("/", "app.js?v="),
    ("/app.js", "GAME_VERSION"),
    ("/game-data.js", "const TEAMS"),
    ("/sw.js", "const CACHE"),
    ("/manifest.json", "Decempionz"),
    ("/icon-192.png", None),
    ("/icon-512.png", None),
    ("/about.html", None),
    ("/ucl.html", None),
    ("/copa.html", None),
    ("/worldcup.html", None),
    ("/draft.html", None),
    ("/duel.html", None),
    ("/sfide.html", None),
)


def free_port() -> int:
    with socket.socket(socket.AF_INET, socket.SOCK_STREAM) as sock:
        sock.bind((HOST, 0))
        return int(sock.getsockname()[1])


def wait_for_server(port: int, timeout_seconds: float = 8.0) -> None:
    deadline = time.monotonic() + timeout_seconds
    while time.monotonic() < deadline:
        try:
            with socket.create_connection((HOST, port), timeout=0.5):
                return
        except OSError:
            time.sleep(0.1)
    raise RuntimeError("Local PHP server did not start in time")


def fetch(port: int, path: str) -> bytes:
    url = f"http://{HOST}:{port}{path}"
    try:
        with urlopen(url, timeout=5) as response:  # noqa: S310 - localhost only
            status = getattr(response, "status", 200)
            body = response.read()
    except (HTTPError, URLError) as exc:
        raise RuntimeError(f"GET {path} failed: {exc}") from exc

    if status != 200:
        raise RuntimeError(f"GET {path} returned HTTP {status}")
    if not body:
        raise RuntimeError(f"GET {path} returned an empty response")
    return body


def check_daily_standing(port: int) -> None:
    """daily-scores.php reports where a not-yet-submitted result would land (fixture in a scratch day)."""
    scores_dir = ROOT / "daily-scores"
    existed = scores_dir.exists()
    fixture = scores_dir / "2001-01-01.json"
    def entry(winner: bool, grade: str) -> dict:
        return {"nickname": "t", "grade": grade, "winner": winner, "res": "W", "goals": {"gf": 1, "ga": 0}, "submittedAt": "2001-01-01T00:00:00+00:00"}
    entries = ([entry(True, "S")] + [entry(True, "A")] * 2 + [entry(True, "C")] * 2 +
               [entry(False, "A")] * 3 + [entry(False, "C")] * 2)
    try:
        scores_dir.mkdir(exist_ok=True)
        fixture.write_text(json.dumps({"entries": entries}), encoding="utf-8")
        def me(query: str) -> dict:
            raw = json.loads(fetch(port, "/daily-scores.php?day=2001-01-01" + query).decode("utf-8"))
            if raw.get("count") != 10:
                raise RuntimeError(f"daily-scores.php count wrong: {raw.get('count')}")
            return raw.get("me")
        got = me("&grade=A&winner=1")
        if got != {"better": 1, "same": 2, "worse": 7, "percentile": 70}:
            raise RuntimeError(f"daily standing for a winning A is wrong: {got}")
        got = me("&grade=C&winner=0")
        if not got or got["worse"] != 0 or got["percentile"] != 0:
            raise RuntimeError(f"daily standing for a losing C is wrong: {got}")
        got = me("&grade=S&winner=1")
        if not got or got["better"] != 0 or got["percentile"] != 90:
            raise RuntimeError(f"daily standing for a winning S is wrong: {got}")
        if me("") is not None or me("&grade=Z&winner=1") is not None:
            raise RuntimeError("daily standing must be null without a valid grade")
        empty = json.loads(fetch(port, "/daily-scores.php?day=2001-01-02&grade=A&winner=1").decode("utf-8"))
        if empty.get("count") != 0 or empty.get("me") is not None or empty.get("entries") != []:
            raise RuntimeError(f"empty Daily day must stay empty: {empty}")
        print("[ok] /daily-scores.php standing")
    finally:
        fixture.unlink(missing_ok=True)
        if not existed:
            shutil.rmtree(scores_dir, ignore_errors=True)


def main() -> int:
    port = free_port()
    process = subprocess.Popen(
        ["php", "-S", f"{HOST}:{port}", "-t", str(ROOT)],
        cwd=ROOT,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.PIPE,
        text=True,
    )

    failures: list[str] = []
    try:
        wait_for_server(port)
        for path, marker in CHECKS:
            try:
                body = fetch(port, path)
                if marker is not None:
                    text = body.decode("utf-8", errors="replace")
                    if marker not in text:
                        raise RuntimeError(f"GET {path} is missing marker: {marker}")
                print(f"[ok] {path}")
            except RuntimeError as exc:
                failures.append(str(exc))
        try:
            check_daily_standing(port)
        except RuntimeError as exc:
            failures.append(str(exc))
    except Exception as exc:  # noqa: BLE001 - surface server startup diagnostics
        failures.append(str(exc))
    finally:
        process.terminate()
        try:
            process.wait(timeout=3)
        except subprocess.TimeoutExpired:
            process.kill()
            process.wait(timeout=3)

    if failures:
        print("\nSmoke test failed:", file=sys.stderr)
        for failure in failures:
            print(f"  - {failure}", file=sys.stderr)
        if process.stderr is not None:
            logs = process.stderr.read().strip()
            if logs:
                print("\nPHP server log:", file=sys.stderr)
                print(logs, file=sys.stderr)
        return 1

    print(f"\nSmoke test passed ({len(CHECKS)} endpoints/assets and the Daily standing check).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
