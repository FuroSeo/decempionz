#!/usr/bin/env python3
"""End-to-end tests for the recovered Decempionz content generators.

Both generators run in isolated temporary copies so CI proves that they still
work without mutating the checked-out repository.
"""

from __future__ import annotations

import difflib
import hashlib
import re
import shutil
import subprocess
import sys
import tempfile
import xml.etree.ElementTree as ET
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]


def fail(message: str) -> None:
    raise RuntimeError(message)


def copy_files(dst: Path, names: tuple[str, ...]) -> None:
    for name in names:
        src = ROOT / name
        if not src.exists():
            fail(f"missing generator input: {name}")
        target = dst / name
        target.parent.mkdir(parents=True, exist_ok=True)
        shutil.copy2(src, target)


def run_python(workdir: Path, script: str) -> str:
    proc = subprocess.run(
        [sys.executable, script],
        cwd=workdir,
        text=True,
        capture_output=True,
        check=False,
    )
    if proc.returncode != 0:
        detail = "\n".join(x for x in (proc.stdout, proc.stderr) if x.strip())
        fail(f"{script} failed ({proc.returncode}):\n{detail}")
    return proc.stdout


def digest_tree(root: Path, patterns: tuple[str, ...]) -> str:
    files: list[Path] = []
    for pattern in patterns:
        files.extend(root.glob(pattern))
    files = sorted({p for p in files if p.is_file()})
    h = hashlib.sha256()
    for path in files:
        rel = path.relative_to(root).as_posix().encode()
        h.update(rel + b"\0")
        h.update(path.read_bytes())
        h.update(b"\0")
    return h.hexdigest()


def strip_localized_seo(html: str) -> str:
    html = re.sub(r'<script type="application/ld\+json">.*?</script>', "", html, flags=re.S)
    return re.sub(r'<meta name="keywords" content="[^"]*">', "", html)


def test_i18n_generator() -> None:
    with tempfile.TemporaryDirectory(prefix="dcz-i18n-") as td:
        work = Path(td)
        copy_files(
            work,
            (
                "_build_i18n.py",
                "ucl.html",
                "copa.html",
                "worldcup.html",
                "about.html",
            ),
        )

        first = run_python(work, "_build_i18n.py")
        if "hreflang" not in first:
            fail("_build_i18n.py did not report hreflang handling")

        pages = ("ucl", "copa", "worldcup", "about")
        for page in pages:
            source = (work / f"{page}.html").read_text(encoding="utf-8")
            for lang in ("en", "es"):
                if f'data-lang="{lang}"' not in source:
                    fail(f"{page}.html is missing required {lang.upper()} content block")

        for lang in ("en", "es"):
            for page in pages:
                path = work / lang / f"{page}.html"
                if not path.exists() or path.stat().st_size < 500:
                    fail(f"_build_i18n.py missing/empty output: {lang}/{page}.html")
                html = path.read_text(encoding="utf-8")
                if f'<html lang="{lang}">' not in html:
                    fail(f"wrong html lang in {lang}/{page}.html")
                if f'hreflang="{lang}"' not in html:
                    fail(f"missing hreflang in {lang}/{page}.html")
                if f"https://decempionz.com/{lang}/{page}.html" not in html:
                    fail(f"missing localized canonical URL in {lang}/{page}.html")

                if lang == "es" and page in {"copa", "worldcup"}:
                    committed = ROOT / lang / f"{page}.html"
                    if not committed.exists():
                        fail(f"missing committed generated page: {lang}/{page}.html")
                    committed_text = committed.read_text(encoding="utf-8")
                    # Keywords and JSON-LD are localized by hand after generation, so they are
                    # excluded here; everything else must match the generator byte for byte.
                    committed_text = strip_localized_seo(committed_text)
                    html_cmp = strip_localized_seo(html)
                    if committed_text != html_cmp:
                        diff = "\n".join(
                            list(
                                difflib.unified_diff(
                                    committed_text.splitlines(),
                                    html_cmp.splitlines(),
                                    fromfile=f"committed/{lang}/{page}.html",
                                    tofile=f"generated/{lang}/{page}.html",
                                    lineterm="",
                                )
                            )[:80]
                        )
                        fail(
                            f"committed generated page is stale: {lang}/{page}.html"
                            + (f"\n{diff}" if diff else "")
                        )

        patterns = (
            "ucl.html",
            "copa.html",
            "worldcup.html",
            "about.html",
            "en/*.html",
            "es/*.html",
        )
        digest1 = digest_tree(work, patterns)
        run_python(work, "_build_i18n.py")
        digest2 = digest_tree(work, patterns)
        if digest1 != digest2:
            fail("_build_i18n.py is not idempotent on a second run")


def test_rose_generator() -> None:
    with tempfile.TemporaryDirectory(prefix="dcz-rose-") as td:
        work = Path(td)
        copy_files(
            work,
            (
                "_build_rose.py",
                "index.html",
                "game-data.js",
            ),
        )

        first = run_python(work, "_build_rose.py")
        match_it = re.search(r"rose:\s*(\d+) pagine \+ indice", first)
        match_en = re.search(r"en/rose:\s*(\d+) pagine \+ indice", first)
        match_sm = re.search(r"sitemap:\s*(\d+) url", first)
        if not (match_it and match_en and match_sm):
            fail("_build_rose.py output summary is incomplete")

        count_it = int(match_it.group(1))
        count_en = int(match_en.group(1))
        sitemap_count = int(match_sm.group(1))
        if count_it != count_en or count_it < 200:
            fail(f"unexpected generated squad count: it={count_it}, en={count_en}")

        it_pages = [p for p in (work / "rose").glob("*.html") if p.name != "index.html"]
        en_pages = [p for p in (work / "en" / "rose").glob("*.html") if p.name != "index.html"]
        if len(it_pages) != count_it or len(en_pages) != count_en:
            fail(
                "generated squad file count mismatch: "
                f"it={len(it_pages)}/{count_it}, en={len(en_pages)}/{count_en}"
            )

        for path in (work / "rose" / "index.html", work / "en" / "rose" / "index.html"):
            if not path.exists() or path.stat().st_size < 500:
                fail(f"missing/empty rose index: {path.relative_to(work)}")

        sample = it_pages[0].read_text(encoding="utf-8")
        for marker in ('rel="canonical"', 'hreflang="it"', '"@type": "SportsTeam"'):
            if marker not in sample:
                fail(f"generated squad page missing marker: {marker}")

        sitemap = work / "sitemap.xml"
        if not sitemap.exists():
            fail("_build_rose.py did not generate sitemap.xml")
        try:
            root = ET.parse(sitemap).getroot()
        except ET.ParseError as exc:
            fail(f"generated sitemap.xml is invalid XML: {exc}")
        url_nodes = root.findall("{http://www.sitemaps.org/schemas/sitemap/0.9}url")
        if len(url_nodes) != sitemap_count:
            fail(f"sitemap URL count mismatch: xml={len(url_nodes)}, log={sitemap_count}")
        if sitemap_count < (count_it * 2):
            fail("sitemap does not contain both IT and EN squad pages")

        sitemap_text = sitemap.read_text(encoding="utf-8")
        for localized in ("es/ucl.html", "es/copa.html", "es/worldcup.html", "es/about.html"):
            if f"https://decempionz.com/{localized}" not in sitemap_text:
                fail(f"generated sitemap missing localized page: {localized}")

        patterns = ("rose/*.html", "en/rose/*.html", "sitemap.xml")
        digest1 = digest_tree(work, patterns)
        run_python(work, "_build_rose.py")
        digest2 = digest_tree(work, patterns)
        if digest1 != digest2:
            fail("_build_rose.py is not idempotent on a second run")


def main() -> int:
    try:
        test_i18n_generator()
        print("[ok] _build_i18n.py end-to-end + idempotency")
        test_rose_generator()
        print("[ok] _build_rose.py end-to-end + idempotency")
    except RuntimeError as exc:
        print(f"Generator E2E failed: {exc}", file=sys.stderr)
        return 1

    print("Generator E2E tests passed.")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
