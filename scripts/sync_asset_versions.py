#!/usr/bin/env python3
"""Keep the cache-busting revisions of the app scripts in sync.

app.js is the source of truth for the game code. Its revision in index.html is the first 10 hex
characters of the SHA-1 of its content (line endings normalized to LF), and the service worker
precaches both script URLs. Run this after every change to app.js or game-data.js:

    python scripts/sync_asset_versions.py

The validator fails when index.html or sw.js are out of date, so a stale bundle can never be
served with a long immutable cache lifetime.
"""
from __future__ import annotations

import hashlib
import re
import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def app_revision() -> str:
    data = (ROOT / "app.js").read_bytes().replace(b"\r\n", b"\n")
    return hashlib.sha1(data).hexdigest()[:10]


def main() -> int:
    index_path = ROOT / "index.html"
    sw_path = ROOT / "sw.js"
    index = open(index_path, encoding="utf-8", newline="").read()
    sw = open(sw_path, encoding="utf-8", newline="").read()

    data_rev = re.search(r'<script defer src="game-data\.js\?v=([A-Za-z0-9._-]+)"></script>', index)
    if not data_rev:
        print("index.html: deferred game-data.js script tag not found", file=sys.stderr)
        return 1
    app_rev = app_revision()

    new_index, n = re.subn(
        r'(<script defer src="app\.js\?v=)[A-Za-z0-9._-]+("></script>)', r"\g<1>%s\g<2>" % app_rev, index
    )
    if n != 1:
        print("index.html: deferred app.js script tag not found", file=sys.stderr)
        return 1
    block = "const VERSIONED_ASSETS = [\n  '/game-data.js?v=%s',\n  '/app.js?v=%s'\n];" % (data_rev.group(1), app_rev)
    new_sw, n = re.subn(r"const VERSIONED_ASSETS = \[.*?\];", lambda _m: block, sw, flags=re.S)
    if n != 1:
        print("sw.js: VERSIONED_ASSETS not found", file=sys.stderr)
        return 1

    changed = []
    if new_index != index:
        open(index_path, "w", encoding="utf-8", newline="").write(new_index)
        changed.append("index.html")
    if new_sw != sw:
        open(sw_path, "w", encoding="utf-8", newline="").write(new_sw)
        changed.append("sw.js")
    print("app.js revision %s, game-data.js revision %s; updated: %s" % (app_rev, data_rev.group(1), ", ".join(changed) or "nothing"))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
