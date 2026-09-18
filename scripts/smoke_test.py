#!/usr/bin/env python3
"""Minimal localhost HTTP smoke test for Decempionz.

Starts PHP's built-in server and verifies that the main public entry points,
static assets and PWA icons are actually served from the repository. No external
network calls are made.
"""

from __future__ import annotations

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
    ("/", "GAME_VERSION"),
    ("/index.html", "GAME_VERSION"),
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

    print(f"\nSmoke test passed ({len(CHECKS)} endpoints/assets).")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
