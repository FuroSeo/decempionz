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
  // State isolation between runs in the same page session (Daily / normal campaign).
  { id: 'daily-after-campaign', tab: null, quick: 'home.btn_quick', viewport: { width: 430, height: 900 }, then: 'daily' },
  { id: 'campaign-after-abandoned-daily', tab: null, quick: 'home.btn_quick', viewport: { width: 430, height: 900 }, before: 'abandoned-daily' },
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

// Click like a real pointer: dispatch at the element's current center and let
// the browser hit-test. Playwright's own click() waits for the element to be
// "stable", which never happens for cards with a continuous CSS animation
// (eliteGlow moves the card a few pixels forever), while a user clicks fine.
// Hit-testing is preserved: an overlay covering the card still swallows the
// click and is caught by the stuck detector.
async function pointerClick(page, locator, timeout = 4000) {
  await locator.waitFor({ state: 'visible', timeout });
  await locator.evaluate(el => el.scrollIntoView({ block: 'nearest', inline: 'nearest' }));
  const box = await locator.boundingBox();
  if (!box) throw new Error('element has no bounding box');
  await page.mouse.click(box.x + box.width / 2, box.y + box.height / 2);
}

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
      ht: vis(document.querySelector('#m-ht .ht-opt')),
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

  if (sc.before === 'abandoned-daily') {
    // Start today's Daily and walk away without finishing it.
    await page.evaluate(() => { startDailyPuzzle(false); });
    await page.waitForSelector('#screen-draft.active', { timeout: 5000 });
    await page.evaluate(() => { showScreen('screen-home'); });
    await page.waitForSelector('#screen-home.active', { timeout: 5000 });
  }

  if (sc.tab) await page.click(sc.tab);
  await page.click(`[data-i18n="${sc.quick}"]`);

  const started = Date.now();
  let steps = 0;
  let htSeen = 0;

  // Plays whatever is on screen until a campaign end screen (or a problem).
  async function drive() {
    const t0 = Date.now();
    let lastSig = '';
    let lastChange = Date.now();
    let coachChecked = false;
    while (Date.now() - t0 < CAMPAIGN_MS) {
      const s = await snapshot(page);
      if (!s) { await sleep(200); continue; }
      steps++;

      // On the match screen the visible text is progress too: a long sudden-death shootout has no
      // buttons and no new screen for many seconds, but its rounds keep being written.
      const sig = [s.screen, s.cards, s.coaches, s.rcont, s.skip, s.ht, s.buttons.map(b => b.text).join(','),
        s.screen === 'screen-match' ? s.text : ''].join('|');
      if (sig !== lastSig) {
        lastSig = sig; lastChange = Date.now();
        if (!visited.includes(s.screen)) visited.push(s.screen);
        const bad = s.text.match(BAD_TEXT);
        if (bad) problems.push(`bad text "${bad[0]}" on ${s.screen}`);
      } else if (Date.now() - lastChange > STUCK_MS) {
        problems.push(`stuck on ${s.screen} for ${STUCK_MS / 1000}s`);
        await page.screenshot({ path: path.join(os.tmpdir(), `e2e-stuck-${sc.id}.png`) }).catch(() => {});
        return false;
      }

      if (s.screen === 'screen-gameover' || s.screen === 'screen-trophy') { return true; }

      // Once per campaign, on the coach screen (XI complete): the Team Score panel
      // and G.chem, which feed every match, must describe the FINAL eleven.
      if (s.coaches && !coachChecked) {
        coachChecked = true;
        const c = await page.evaluate(() => {
          const tac = G.draftTactic || G.tactic || 'balanced';
          const pos = fmtPositions(G.formation, tac);
          const ev = evaluateLineup(G.slotPlayers, pos, G.formation, tac);
          const ch = calcChemistry(G.slotPlayers, pos, G.gameMode);
          const el = document.querySelector('#d-team-score .team-score-value');
          return { filled: G.slotPlayers.filter(Boolean).length, score: ev.score, shown: el ? Number(el.textContent) : null,
                   chem: ch.pct, gchem: G.chem ? G.chem.pct : null };
        });
        if (c.filled !== 11) problems.push(`coach screen with ${c.filled}/11 players`);
        if (c.shown !== c.score) problems.push(`stale Team Score on coach screen: shown ${c.shown}, real ${c.score}`);
        if (c.gchem !== c.chem) problems.push(`stale chemistry for matches: G.chem ${c.gchem}%, real ${c.chem}%`);
      }

      try {
        if (s.cards) await pointerClick(page, page.locator('.screen.active button.draft-pick-card').first());
        else if (s.coaches) await pointerClick(page, page.locator('.screen.active button.coach-card').first());
        else if (s.screen === 'screen-match' && s.rcont) {
          // Half-time invariant: the goals shown in the log add up to the final score.
          const m = await page.evaluate(() => ({ half: M.half ? M.half.stage : null, my: M.myS, opp: M.oppS, myG: M.myG, oppG: M.oppG }));
          if (m.half !== 2) problems.push('match reached the result without a second half: ' + JSON.stringify(m));
          if (m.my !== m.myG || m.opp !== m.oppG) problems.push('log goals do not match the final score: ' + JSON.stringify(m));
          await page.click('#btn-rcont', { timeout: 4000 });
        }
        else if (s.screen === 'screen-match' && s.ht) {
          // Alternate between the three second-half tactics so every branch runs.
          const tac = ['attack', 'balanced', 'defend'][htSeen % 3];
          htSeen++;
          await page.click(`#m-ht .ht-opt[data-tac="${tac}"]`, { timeout: 4000 });
          await page.waitForSelector('#m-ht', { state: 'hidden', timeout: 4000 });
        }
        else if (s.screen === 'screen-match' && s.skip) {
          // Odd matches play out at fast speed (natural playback), even ones use "skip".
          const odd = await page.evaluate(() => (G.groupUserResults.length + G.knockResults.length) % 2 === 1);
          if (odd) await page.evaluate(() => { setMatchSpeed('fast'); });
          else await page.click('#spd-skip', { timeout: 4000 });
        }
        else if (s.screen !== 'screen-match') {
          const next = s.buttons.find(b => !b.disabled && !/ghost|screen-logo/.test(b.cls) &&
            !/New Game|Nuova Partita|Reroll|Home|Menu|DECEMPIONZ/i.test(b.text));
          if (!next) { problems.push(`no actionable button on ${s.screen}`); return false; }
          if (next.id) await page.click('#' + next.id, { timeout: 4000 });
          else await page.locator('.screen.active button', { hasText: next.text.slice(0, 24) }).first().click({ timeout: 4000 });
        }
      } catch (e) {
        // A transient re-render can invalidate a click; the stuck detector catches real dead ends.
      }
      await sleep(180);
    }
    return false;
  }
  let finished = await drive();
  const dailyHist = () => page.evaluate(() => {
    try { return Object.keys((JSON.parse(localStorage.getItem('dcz_daily') || '{}').hist) || {}).length; } catch (e) { return -1; }
  });
  const campaigns = () => page.evaluate(() => {
    try { return JSON.parse(localStorage.getItem('dcz_manager_progress') || '{}').campaigns || 0; } catch (e) { return -1; }
  });

  if (finished && sc.before === 'abandoned-daily') {
    // A normal campaign must never be recorded as the abandoned Daily.
    if (await dailyHist() !== 0) problems.push('a normal campaign was recorded as the Daily result');
    if (await page.locator('#daily-share-box').count()) problems.push('Daily share box shown after a normal campaign');
  }

  if (finished && sc.then === 'daily') {
    // A Daily started after a finished campaign, in the same page session, must start
    // from clean per-campaign state and must award progression like any campaign.
    const before = await campaigns();
    await page.evaluate(() => { showScreen('screen-home'); startDailyPuzzle(true); });
    await page.waitForSelector('#screen-draft.active', { timeout: 5000 });
    const iso = await page.evaluate(() => ({
      scorers: Object.keys(G.campaignScorers || {}).length, gf: G.campaignGF || 0,
      awarded: !!G.progressAwarded, tactics: (G.tacticHistory || []).length,
    }));
    if (iso.scorers || iso.gf || iso.awarded || iso.tactics) {
      problems.push('Daily inherited campaign state: ' + JSON.stringify(iso));
    }
    finished = await drive();
    const after = await campaigns();
    if (finished && after !== before + 1) problems.push(`Daily run did not award progression: campaigns ${before} -> ${after}`);
  }

  if (finished && !htSeen) problems.push('no half-time decision appeared during the campaign');
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
