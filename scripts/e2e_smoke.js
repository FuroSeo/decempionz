#!/usr/bin/env node
'use strict';
/*
 * Browser smoke test for Decempionz.
 *
 * Serves the repository as a static site, opens it in headless Chromium and
 * plays complete campaigns (Quick Draft -> coach -> group/knockout matches ->
 * end screen) for UCL, Copa Libertadores and World Cup on a mobile viewport,
 * plus a desktop UCL run. PHP endpoints are answered with 404 (like a static
 * host) and third-party hosts are blocked, so the test never touches
 * production data and also proves the client tolerates a missing backend.
 *
 * It fails on: uncaught page errors, console errors that are not expected
 * network noise, a stuck UI (no state change for STUCK_MS), a campaign that
 * does not finish before CAMPAIGN_MS, or visible "NaN" / "undefined" /
 * "[object" text on any screen that is reached.
 *
 * Usage:   npm i --no-save playwright && npx playwright install chromium
 *          node scripts/e2e_smoke.js
 * Env:     PW_CHROMIUM=/path/to/chrome   use a preinstalled browser
 *          E2E_ONLY=ucl,copa,wc,ucl-desktop   run a subset
 */

const http = require('http');
const fs = require('fs');
const os = require('os');
const path = require('path');

let chromium;
try {
  ({ chromium } = require('playwright'));
} catch (e) {
  console.error('playwright is not installed: npm i --no-save playwright');
  process.exit(2);
}

const ROOT = path.resolve(__dirname, '..');
const CAMPAIGN_MS = 150000;
const STUCK_MS = 20000;
const BAD_TEXT = /\bNaN\b|\bundefined\b|\[object/;
const MIME = {
  '.html': 'text/html; charset=utf-8', '.js': 'application/javascript; charset=utf-8',
  '.json': 'application/json', '.css': 'text/css', '.png': 'image/png',
  '.svg': 'image/svg+xml', '.xml': 'application/xml', '.txt': 'text/plain',
};

const SCENARIOS = [
  { id: 'ucl', tab: null, quick: 'home.btn_quick', viewport: { width: 430, height: 900 } },
  { id: 'copa', tab: '#tab-copa', quick: 'copa.btn_quick', viewport: { width: 430, height: 900 } },
  { id: 'wc', tab: '#tab-wc', quick: 'wc.btn_quick', viewport: { width: 430, height: 900 } },
  { id: 'ucl-desktop', tab: null, quick: 'home.btn_quick', viewport: { width: 1280, height: 800 }, lang: 'en-US' },
];

function serve() {
  return new Promise(resolve => {
    const server = http.createServer((req, res) => {
      let p = decodeURIComponent(req.url.split('?')[0]);
      if (p.endsWith('/')) p += 'index.html';
      const file = path.join(ROOT, p);
      if (!file.startsWith(ROOT) || !fs.existsSync(file) || fs.statSync(file).isDirectory()) {
        res.writeHead(404); res.end('not found'); return;
      }
      res.writeHead(200, { 'Content-Type': MIME[path.extname(file)] || 'application/octet-stream' });
      fs.createReadStream(file).pipe(res);
    });
    server.listen(0, '127.0.0.1', () => resolve(server));
  });
}

function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }

async function snapshot(page) {
  return page.evaluate(() => {
    const vis = e => e && e.offsetParent !== null;
    const a = document.querySelector('.screen.active');
    if (!a) return null;
    const buttons = [...a.querySelectorAll('button')].filter(vis).map(e => ({
      id: e.id, cls: e.className, disabled: e.disabled,
      text: e.innerText.trim().replace(/\s+/g, ' ').slice(0, 40),
    }));
    return {
      screen: a.id,
      cards: buttons.filter(b => /draft-pick-card/.test(b.cls)).length,
      coaches: buttons.filter(b => /coach-card/.test(b.cls)).length,
      rcont: vis(document.getElementById('btn-rcont')),
      skip: vis(document.getElementById('spd-skip')),
      buttons,
      text: a.innerText,
    };
  });
}

async function playCampaign(browser, base, sc) {
  const context = await browser.newContext({ viewport: sc.viewport, locale: sc.lang || 'it-IT' });
  const page = await context.newPage();
  const problems = [];
  const visited = [];

  page.on('pageerror', e => problems.push('pageerror: ' + e.message));
  page.on('console', m => {
    if (m.type() !== 'error') return;
    const t = m.text();
    if (/Failed to load resource|net::ERR_/.test(t)) return; // blocked third parties / 404 backend
    problems.push('console.error: ' + t.slice(0, 200));
  });
  await page.route(/\.php(\?|$)/, r => r.fulfill({ status: 404, body: 'not found' }));
  await page.route(u => new URL(u).hostname !== '127.0.0.1', r => r.abort());

  await page.goto(base + '/index.html', { waitUntil: 'load' });
  await page.evaluate(() => { try { localStorage.setItem('dcz_tut', '1'); } catch (e) { /* ignore */ } });
  await page.reload({ waitUntil: 'load' });
  await page.waitForSelector('#screen-home.active', { timeout: 10000 });

  if (sc.tab) await page.click(sc.tab);
  await page.click(`[data-i18n="${sc.quick}"]`);

  const started = Date.now();
  let lastSig = '';
  let lastChange = Date.now();
  let steps = 0;
  let finished = false;

  while (Date.now() - started < CAMPAIGN_MS) {
    const s = await snapshot(page);
    if (!s) { await sleep(200); continue; }
    steps++;

    const sig = [s.screen, s.cards, s.coaches, s.rcont, s.skip, s.buttons.map(b => b.text).join(',')].join('|');
    if (sig !== lastSig) {
      lastSig = sig; lastChange = Date.now();
      if (!visited.includes(s.screen)) visited.push(s.screen);
      const bad = s.text.match(BAD_TEXT);
      if (bad) problems.push(`bad text "${bad[0]}" on ${s.screen}`);
    } else if (Date.now() - lastChange > STUCK_MS) {
      problems.push(`stuck on ${s.screen} for ${STUCK_MS / 1000}s`);
      await page.screenshot({ path: path.join(os.tmpdir(), `e2e-stuck-${sc.id}.png`) }).catch(() => {});
      break;
    }

    if (s.screen === 'screen-gameover' || s.screen === 'screen-trophy') { finished = true; break; }

    try {
      if (s.cards) await page.locator('.screen.active button.draft-pick-card').first().click({ timeout: 4000 });
      else if (s.coaches) await page.locator('.screen.active button.coach-card').first().click({ timeout: 4000 });
      else if (s.screen === 'screen-match' && s.rcont) await page.click('#btn-rcont', { timeout: 4000 });
      else if (s.screen === 'screen-match' && s.skip) await page.click('#spd-skip', { timeout: 4000 });
      else if (s.screen !== 'screen-match') {
        const next = s.buttons.find(b => !b.disabled && !/ghost|screen-logo/.test(b.cls) &&
          !/New Game|Nuova Partita|Reroll|Home|Menu|DECEMPIONZ/i.test(b.text));
        if (!next) { problems.push(`no actionable button on ${s.screen}`); break; }
        if (next.id) await page.click('#' + next.id, { timeout: 4000 });
        else await page.locator('.screen.active button', { hasText: next.text.slice(0, 24) }).first().click({ timeout: 4000 });
      }
    } catch (e) {
      // A transient re-render can invalidate a click; the stuck detector catches real dead ends.
    }
    await sleep(180);
  }

  if (!finished && !problems.some(p => p.startsWith('stuck') || p.startsWith('no actionable'))) {
    problems.push(`campaign did not finish within ${CAMPAIGN_MS / 1000}s`);
  }
  await context.close();
  return { id: sc.id, finished, steps, visited, problems, seconds: Math.round((Date.now() - started) / 1000) };
}

(async () => {
  const only = (process.env.E2E_ONLY || '').split(',').map(s => s.trim()).filter(Boolean);
  const list = SCENARIOS.filter(s => !only.length || only.includes(s.id));
  const server = await serve();
  const base = `http://127.0.0.1:${server.address().port}`;
  const browser = await chromium.launch(process.env.PW_CHROMIUM ? { executablePath: process.env.PW_CHROMIUM } : {});
  let failed = 0;
  try {
    for (const sc of list) {
      const r = await playCampaign(browser, base, sc);
      const ok = r.finished && r.problems.length === 0;
      console.log(`${ok ? 'PASS' : 'FAIL'} ${r.id}: ${r.seconds}s, ${r.steps} steps, screens: ${r.visited.join(' > ')}`);
      r.problems.forEach(p => console.log('  - ' + p));
      if (!ok) failed++;
    }
  } finally {
    await browser.close();
    server.close();
  }
  if (failed) { console.error(`${failed} browser smoke scenario(s) failed`); process.exit(1); }
  console.log('Browser smoke test passed.');
})().catch(e => { console.error(e); process.exit(1); });
