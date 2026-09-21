#!/usr/bin/env node
'use strict';
/*
 * End to end test of the PHP backend together with the real client: Daily and Duel.
 *
 * e2e_smoke.js answers every .php request with 404 (a static host) and proves the client
 * tolerates a missing backend. This test does the opposite: it starts a real PHP server
 * (php -S) on a throw-away copy of the site, so the repository is never touched, and drives
 * the actual UI against it.
 *
 *   Daily API      validation, one entry per network and nickname, ranking, percentile,
 *                  HTML escaping, concurrent submissions must not lose entries.
 *   Daily browser  play the Daily to the end, see the standing line, submit the nickname,
 *                  see the position, the leaderboard, the sent state after a reload.
 *   Duel browser   A drafts and creates the duel, B opens the link, drafts, plays the
 *                  series, A opens the verdict; then the public pages are checked.
 *   Duel API       a waiting duel never reveals A's squad, a finished one is closed to a
 *                  second challenger, a squad without a verified draft session is refused.
 *
 * Time is fixed to UTC on both sides (PHP date.timezone and the browser context), so the
 * "today" of the client and of the server are the same day.
 *
 * Usage:   npm i --no-save playwright && npx playwright install chromium
 *          node scripts/e2e_php.js
 * Needs:   php in PATH.
 * Env:     PW_CHROMIUM=/path/to/chrome   use a preinstalled browser
 *          E2E_ONLY=daily-api,daily,duel,duel-api   run a subset
 */

const http = require('http');
const net = require('net');
const fs = require('fs');
const os = require('os');
const path = require('path');
const { spawn } = require('child_process');

let chromium;
try {
  ({ chromium } = require('playwright'));
} catch (e) {
  console.error('playwright is not installed: npm i --no-save playwright');
  process.exit(2);
}

const ROOT = path.resolve(__dirname, '..');
const STEP_TIMEOUT_MS = 150000;
const STUCK_MS = 30000;
const BAD_TEXT = /\bNaN\b|\bundefined\b|\[object/;

const results = [];
let currentGroup = '';

function check(name, ok, detail) {
  results.push({ group: currentGroup, name, ok: !!ok, detail: ok ? '' : (detail === undefined ? '' : String(detail)) });
}
function sleep(ms) { return new Promise(r => setTimeout(r, ms)); }
function dayString(offsetDays) {
  return new Date(Date.now() + offsetDays * 86400000).toISOString().slice(0, 10);
}

/* ── PHP server on a copy of the site ───────────────────────────────────────────────── */

function freePort() {
  return new Promise((resolve, reject) => {
    const s = net.createServer();
    s.once('error', reject);
    s.listen(0, '127.0.0.1', () => { const p = s.address().port; s.close(() => resolve(p)); });
  });
}

function copySite(dest) {
  fs.mkdirSync(dest, { recursive: true });
  for (const e of fs.readdirSync(ROOT, { withFileTypes: true })) {
    if (e.name.startsWith('.')) continue;
    if (e.isFile()) fs.copyFileSync(path.join(ROOT, e.name), path.join(dest, e.name));
    else if (e.name === 'fonts') fs.cpSync(path.join(ROOT, e.name), path.join(dest, e.name), { recursive: true });
  }
}

async function startPhp() {
  const work = fs.mkdtempSync(path.join(os.tmpdir(), 'dcz-e2e-php-'));
  const site = path.join(work, 'site');
  const tmp = path.join(work, 'tmp');
  copySite(site);
  fs.mkdirSync(tmp);
  const port = await freePort();
  const proc = spawn('php', ['-d', 'date.timezone=UTC', '-d', 'display_errors=stderr', '-S', `127.0.0.1:${port}`, '-t', site], {
    env: Object.assign({}, process.env, { TMPDIR: tmp, TEMP: tmp, TMP: tmp, PHP_CLI_SERVER_WORKERS: '4' }),
    stdio: ['ignore', 'ignore', 'pipe'],
  });
  let log = '';
  proc.stderr.on('data', d => { log += d.toString(); });
  proc.on('error', e => { log += 'spawn error: ' + e.message + '\n'; });
  const base = `http://127.0.0.1:${port}`;
  const t0 = Date.now();
  for (;;) {
    try {
      const r = await fetch(base + '/index.html');
      if (r.ok) break;
    } catch (e) { /* not up yet */ }
    if (Date.now() - t0 > 15000) { proc.kill(); throw new Error('php -S did not start: ' + log); }
    await sleep(150);
  }
  return {
    base, site, work,
    errors: () => log.split('\n').filter(l => /Fatal error|Warning:|Notice:|Deprecated:|Parse error|Uncaught/.test(l)),
    stop: () => new Promise(resolve => { proc.once('exit', resolve); proc.kill(); setTimeout(resolve, 2000); }),
  };
}

async function api(base, method, url, body) {
  const opt = { method, headers: {} };
  if (body !== undefined) {
    opt.headers['Content-Type'] = 'application/json';
    opt.body = typeof body === 'string' ? body : JSON.stringify(body);
  }
  const r = await fetch(base + url, opt);
  const text = await r.text();
  let json = null;
  try { json = JSON.parse(text); } catch (e) { /* not json */ }
  return { status: r.status, json, text };
}

/* ── Browser helpers ────────────────────────────────────────────────────────────────── */

async function newPage(browser, base, problems, opts) {
  const o = opts || {};
  const context = await browser.newContext({
    viewport: o.viewport || { width: 430, height: 900 }, locale: o.lang || 'it-IT',
    timezoneId: 'UTC', serviceWorkers: 'block',
  });
  const page = await context.newPage();
  page.on('pageerror', e => problems.push('pageerror: ' + e.message));
  page.on('console', m => {
    if (m.type() !== 'error') return;
    const t = m.text();
    if (/Failed to load resource|net::ERR_/.test(t)) return; // handled below, with the URL
    problems.push('console.error: ' + t.slice(0, 200));
  });
  page.on('response', r => {
    if (r.url().startsWith(base) && r.status() >= 500) problems.push(`HTTP ${r.status()} on ${r.url().replace(base, '')}`);
  });
  page.on('dialog', d => {
    // A JS alert()/confirm() blocks the renderer and hangs every following command; it must
    // never happen in a healthy run, but auto-dismiss it so the test fails loudly instead of
    // hanging silently until the outer timeout.
    problems.push(`unexpected ${d.type()} dialog: ${d.message()}`);
    d.dismiss().catch(() => {});
  });
  page.on('requestfailed', r => {
    // A reload/navigation cancels whatever the previous page still had in flight (background
    // stats pings, poll requests): that is expected, not a bug — same as the console filter above.
    const err = (r.failure() && r.failure().errorText) || '';
    if (/ABORTED/.test(err)) return;
    if (r.url().startsWith(base)) problems.push(`request failed: ${r.url().replace(base, '')} (${err})`);
  });
  await page.route(u => new URL(u).hostname !== '127.0.0.1', r => r.abort());
  return { context, page };
}

async function openHome(page, base) {
  await page.goto(base + '/index.html', { waitUntil: 'load' });
  await page.evaluate(() => { try { localStorage.setItem('dcz_tut', '1'); } catch (e) { /* ignore */ } });
  await page.reload({ waitUntil: 'load' });
  await page.waitForSelector('#screen-home.active', { timeout: 10000 });
}

// Click like a pointer at the element centre (cards have a never ending CSS animation, so
// Playwright's own "wait for stable" would never finish). See e2e_smoke.js.
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

/*
 * Plays whatever is on screen (draft cards, coach, half time, match speed, result) until
 * `until(snapshot)` is true. `generic` also presses the first actionable button on the
 * other screens (needed for a whole campaign, not for a draft or a duel series).
 */
async function autoplay(page, problems, label, until, generic, timeoutMs) {
  const limit = timeoutMs || STEP_TIMEOUT_MS;
  const t0 = Date.now();
  let lastSig = '';
  let lastChange = Date.now();
  let htSeen = 0;
  let lastNullLog = 0;
  while (Date.now() - t0 < limit) {
    const url = page.url();
    // `until` is checked against the URL first: the SPA's own screens all expose
    // `.screen.active`, but a destination like duel.php is a different, static page that
    // never has one, so waiting for a snapshot there would never see the "done" condition.
    if (await until(null, url)) return true;
    const s = await snapshot(page).catch(() => null); // null while a navigation is in flight
    if (!s) {
      if (process.env.E2E_DEBUG && Date.now() - lastNullLog > 5000) {
        lastNullLog = Date.now();
        console.error(`[${label} ${Math.round((Date.now() - t0) / 1000)}s] no active screen, url=${url.replace(/^https?:\/\/[^/]+/, '')}`);
      }
      await sleep(200); continue;
    }
    if (await until(s, url)) return true;

    const sig = [s.screen, url, s.cards, s.coaches, s.rcont, s.skip, s.ht, s.buttons.map(b => b.text).join(','),
      s.screen === 'screen-match' ? s.text : ''].join('|');
    if (sig !== lastSig) {
      lastSig = sig; lastChange = Date.now();
      if (process.env.E2E_DEBUG) console.error(`[${label} ${Math.round((Date.now() - t0) / 1000)}s] ${url.replace(/^https?:\/\/[^/]+/, '')} screen=${s.screen} cards=${s.cards} coaches=${s.coaches} rcont=${s.rcont} skip=${s.skip} ht=${s.ht} buttons=${s.buttons.map(b => b.id || b.text).join(',')}`);
      const bad = s.text.match(BAD_TEXT);
      if (bad) problems.push(`${label}: bad text "${bad[0]}" on ${s.screen}`);
    } else if (Date.now() - lastChange > STUCK_MS) {
      if (process.env.E2E_DEBUG) console.error(`[${label} ${Math.round((Date.now() - t0) / 1000)}s] STUCK on ${s.screen}`);
      problems.push(`${label}: stuck on ${s.screen} for ${STUCK_MS / 1000}s`);
      return false;
    }

    try {
      if (s.cards) await pointerClick(page, page.locator('.screen.active button.draft-pick-card').first());
      else if (s.coaches) await pointerClick(page, page.locator('.screen.active button.coach-card').first());
      else if (s.screen === 'screen-match' && s.rcont) await page.click('#btn-rcont', { timeout: 4000 });
      else if (s.screen === 'screen-match' && s.ht) {
        const tac = ['attack', 'balanced', 'defend'][htSeen++ % 3];
        await page.click(`#m-ht .ht-opt[data-tac="${tac}"]`, { timeout: 4000 });
        await page.waitForSelector('#m-ht', { state: 'hidden', timeout: 4000 });
      } else if (s.screen === 'screen-match' && s.skip) await page.click('#spd-skip', { timeout: 4000 });
      else if (generic && s.screen !== 'screen-match') {
        const next = s.buttons.find(b => !b.disabled && !/ghost|screen-logo/.test(b.cls) &&
          !/New Game|Nuova Partita|Reroll|Home|Menu|DECEMPIONZ/i.test(b.text));
        if (!next) { problems.push(`${label}: no actionable button on ${s.screen}`); return false; }
        if (next.id) await page.click('#' + next.id, { timeout: 4000 });
        else await page.locator('.screen.active button', { hasText: next.text.slice(0, 24) }).first().click({ timeout: 4000 });
      }
    } catch (e) { /* a re-render can invalidate a click: the stuck detector catches real dead ends */ }
    await sleep(180);
  }
  problems.push(`${label}: not finished within ${limit / 1000}s`);
  return false;
}

/* ── Daily: API ─────────────────────────────────────────────────────────────────────── */

function dailyBody(day, nick, grade, winner, res, gf, ga) {
  return { day, num: 7, nickname: nick, grade, winner, res, goals: { gf, ga } };
}

async function dailyApi(base) {
  currentGroup = 'daily-api';
  const tomorrow = dayString(1);
  const yesterday = dayString(-1);
  const old = dayString(-5);

  let r = await api(base, 'GET', '/daily-submit.php');
  check('submit refuses GET with 405', r.status === 405, r.status);
  r = await api(base, 'POST', '/daily-submit.php', 'not json');
  check('submit refuses invalid JSON with 400', r.status === 400, r.status);
  r = await api(base, 'POST', '/daily-submit.php', dailyBody('nonsense', 'X', 'A', false, 'W', 1, 0));
  check('submit refuses a malformed day with 400', r.status === 400, r.status);
  r = await api(base, 'POST', '/daily-submit.php', dailyBody(old, 'Old', 'A', false, 'W', 1, 0));
  check('submit refuses a day that is not open (400 day not open)', r.status === 400 && r.json && r.json.error === 'day not open', r.text);
  r = await api(base, 'POST', '/daily-submit.php', dailyBody(tomorrow, '   ', 'A', false, 'W', 1, 0));
  check('submit refuses an empty nickname with 400', r.status === 400, r.status);
  r = await api(base, 'POST', '/daily-submit.php', JSON.stringify(Object.assign(dailyBody(tomorrow, 'Big', 'A', false, 'W', 1, 0), { pad: 'x'.repeat(9000) })));
  check('submit refuses a body over 8 KB with 413', r.status === 413, r.status);

  // Order of arrival is not the order of the standing.
  const send = (nick, g, w, res, gf, ga) => api(base, 'POST', '/daily-submit.php', dailyBody(tomorrow, nick, g, w, res, gf, ga));
  r = await send('Alpha', 'B', false, 'WLW', 3, 2);
  check('first entry is #1 of 1', r.status === 200 && r.json.success && r.json.position === 1 && r.json.count === 1, r.text);
  r = await send('Bravo', 'S', true, 'WWW', 5, 0);
  check('a winner with grade S goes to #1 of 2', r.json && r.json.position === 1 && r.json.count === 2, r.text);
  r = await send('Charlie', 'C', false, 'LLL', 0, 4);
  check('the weakest entry is #3 of 3', r.json && r.json.position === 3 && r.json.count === 3, r.text);
  r = await send('Del<b>ta', 'A', false, 'WDW', 2, 1);
  check('a grade A that did not win is #2 of 4', r.json && r.json.position === 2 && r.json.count === 4, r.text);
  r = await send('Alpha', 'S', true, 'WWW', 9, 0);
  check('the same nickname on the same day is refused with 429', r.status === 429 && r.json && r.json.error === 'already submitted', r.text);

  r = await api(base, 'GET', `/daily-scores.php?day=${tomorrow}`);
  const names = (r.json.entries || []).map(e => e.nickname);
  check('scores: 4 entries, the refused one is not stored', r.json.count === 4 && names.length === 4, r.text);
  check('scores: ordered winner first, then grade', names.join(',') === 'Bravo,Del&lt;b&gt;ta,Alpha,Charlie', names.join(','));
  check('scores: nickname is HTML escaped by the server', !names.some(n => /[<>]/.test(n)), names.join(','));
  check('scores: without a grade there is no percentile block', r.json.me === null, JSON.stringify(r.json.me));

  const pct = async q => (await api(base, 'GET', `/daily-scores.php?day=${tomorrow}&${q}`)).json.me;
  let me = await pct('grade=A&winner=0');
  check('percentile of an A that did not win is 50 (2 worse of 4)', me && me.percentile === 50 && me.better === 1 && me.same === 1 && me.worse === 2, JSON.stringify(me));
  me = await pct('grade=S&winner=1');
  check('percentile of an S winner is 75 (3 worse of 4)', me && me.percentile === 75 && me.better === 0 && me.same === 1, JSON.stringify(me));
  me = await pct('grade=C&winner=0');
  check('percentile of the weakest tier is 0', me && me.percentile === 0 && me.better === 3, JSON.stringify(me));
  me = await pct('grade=S&winner=0');
  check('an S without the trophy ranks under every winner (75)', me && me.percentile === 75 && me.better === 1, JSON.stringify(me));
  me = await pct('grade=Z&winner=1');
  check('an invalid grade gives no percentile block', me === null, JSON.stringify(me));
  r = await api(base, 'GET', '/daily-scores.php?day=2001-01-01');
  check('a day without entries answers count 0 and an empty list', r.status === 200 && r.json.count === 0 && r.json.entries.length === 0 && r.json.me === null, r.text);

  // Concurrent submissions on a fresh day: every one must survive the file lock.
  const nicks = ['P1', 'P2', 'P3', 'P4', 'P5', 'P6'];
  const all = await Promise.all(nicks.map((n, i) => api(base, 'POST', '/daily-submit.php',
    dailyBody(yesterday, n, ['S', 'A', 'B', 'C'][i % 4], i % 2 === 0, 'WLW', i, 1))));
  check('6 parallel submissions are all accepted', all.every(x => x.status === 200 && x.json.success), all.map(x => x.status).join(','));
  r = await api(base, 'GET', `/daily-scores.php?day=${yesterday}`);
  check('6 parallel submissions leave 6 entries (no lost update)', r.json.count === 6 && r.json.entries.length === 6, r.text.slice(0, 300));
}

/* ── Daily: browser ─────────────────────────────────────────────────────────────────── */

async function dailyBrowser(browser, base) {
  currentGroup = 'daily';
  const today = dayString(0);
  const problems = [];

  // Five ranked players already on the board, so that the percentile line is shown (>= 5).
  const seeds = [['Seed1', 'C', false], ['Seed2', 'C', false], ['Seed3', 'B', false], ['Seed4', 'A', false], ['Seed5', 'S', true]];
  for (const [n, g, w] of seeds) {
    const r = await api(base, 'POST', '/daily-submit.php', dailyBody(today, n, g, w, 'WLW', 2, 1));
    if (r.status !== 200) problems.push(`seed ${n} failed: ${r.text}`);
  }

  const { context, page } = await newPage(browser, base, problems);
  await openHome(page, base);
  // The Daily panel auto-opens on first visit when today's puzzle is not played yet; only
  // click the accordion header if it is not already open (a click on an open one closes it).
  await page.evaluate(() => { if (!document.getElementById('hmp-daily').classList.contains('open')) homeTogglePanel('daily'); });
  await pointerClick(page, page.locator('#hdb-play-btn'));
  await page.waitForSelector('#screen-draft.active', { timeout: 8000 });

  const finished = await autoplay(page, problems, 'daily', s => !!s && (s.screen === 'screen-gameover' || s.screen === 'screen-trophy'), true);
  check('the Daily campaign reaches the end screen', finished, problems.join(' | '));
  if (!finished) { check('no client problems', false, problems.join(' | ')); await context.close(); return; }

  await page.waitForSelector('#daily-share-box .dlb-form', { timeout: 6000 }).catch(() => {});
  check('the share box and the leaderboard form appear', await page.locator('#daily-share-box .dlb-send').count() === 1);

  const entry = await page.evaluate(day => {
    try { return JSON.parse(localStorage.getItem('dcz_daily') || '{}').hist[day] || null; } catch (e) { return null; }
  }, today);
  check('the result is stored locally for today', entry && /^[SABC]$/.test(entry.g), JSON.stringify(entry));

  // Standing line before sending: percentile over 5 seeded players, same number as the server.
  const expected = (await api(base, 'GET', `/daily-scores.php?day=${today}&grade=${entry.g}&winner=${entry.win ? 1 : 0}`)).json;
  await page.waitForFunction(() => { const e = document.querySelector('#daily-share-box .daily-standing'); return e && e.style.display !== 'none' && e.textContent.length > 0; }, null, { timeout: 6000 }).catch(() => {});
  const standing = await page.locator('#daily-share-box .daily-standing').textContent().catch(() => '');
  check('the standing line shows the server percentile over 5 players',
    expected.me && standing.includes(`${expected.me.percentile}%`) && standing.includes(`${expected.count}`), `line="${standing}" expected ${JSON.stringify(expected.me)}`);

  // Send the nickname.
  await page.fill('#daily-share-box .dlb-nick', 'E2E Browser');
  await pointerClick(page, page.locator('#daily-share-box .dlb-send'));
  await page.waitForFunction(() => /#\d+/.test(document.querySelector('#daily-share-box .dlb-form')?.textContent || ''), null, { timeout: 8000 }).catch(() => {});
  const formText = await page.locator('#daily-share-box .dlb-form').textContent().catch(() => '');
  const posMatch = formText.match(/#(\d+)\D+(\d+)/);
  check('after sending, the position is shown as "#N of 6"', posMatch && Number(posMatch[2]) === 6 && Number(posMatch[1]) >= 1 && Number(posMatch[1]) <= 6, formText);
  check('the sent day is remembered', await page.evaluate(() => localStorage.getItem('dcz_daily_sent')) === today);

  const board = (await api(base, 'GET', `/daily-scores.php?day=${today}`)).json;
  const mine = board.entries.find(e => e.nickname === 'E2E Browser');
  check('the server stored the entry with the local result',
    board.count === 6 && mine && mine.grade === entry.g && !!mine.winner === !!entry.win, JSON.stringify(mine));
  const dup = await api(base, 'POST', '/daily-submit.php', dailyBody(today, 'E2E Browser', 'S', true, 'WWW', 5, 0));
  check('sending again is refused with 429', dup.status === 429, dup.status);

  // The sent state survives a reload, and the home leaderboard lists the player.
  await page.reload({ waitUntil: 'load' });
  await page.waitForSelector('#screen-home.active', { timeout: 10000 });
  await page.evaluate(() => { document.getElementById('hmc-daily').click(); });
  await page.waitForSelector('#hdb-lb-link', { state: 'visible', timeout: 5000 }).catch(() => {});
  await page.evaluate(() => { _dailyToggleBoard(); });
  await page.waitForFunction(() => /E2E Browser/.test(document.getElementById('hdb-lb')?.textContent || ''), null, { timeout: 6000 }).catch(() => {});
  const lb = await page.locator('#hdb-lb').textContent();
  check('the home leaderboard lists the new player', /E2E Browser/.test(lb) && /Seed5/.test(lb), lb.slice(0, 200));
  const list = lb.split('#').filter(Boolean);
  check('the leaderboard puts the seeded S winner before the C players', lb.indexOf('Seed5') < lb.indexOf('Seed1'), lb.slice(0, 200));
  check('the leaderboard has one row per ranked player (6)', list.length === 6, list.length);

  check('no client problems during the Daily', problems.length === 0, problems.join(' | '));
  await context.close();
}

/* ── Duel: browser ──────────────────────────────────────────────────────────────────── */

async function draftEleven(page, problems, label, until) {
  return autoplay(page, problems, label, until, false);
}

async function duelBrowser(browser, base, state) {
  currentGroup = 'duel';
  const problems = [];

  // Player A: setup, formation, server side draft, create.
  const A = await newPage(browser, base, problems);
  const a = A.page;
  await openHome(a, base);
  await a.evaluate(() => { document.getElementById('hmc-duel').click(); });
  await a.evaluate(() => { duelStart(); });
  await a.waitForSelector('#screen-duel-setup.active', { timeout: 5000 });
  await a.fill('#duel-nick', 'Alice');
  await pointerClick(a, a.locator('#screen-duel-setup button[onclick="duelGoDraft()"]'));
  await a.waitForSelector('#screen-formation.active', { timeout: 5000 });
  await pointerClick(a, a.locator('#form-grid .form-card:not(#fc-formation-random)').first());
  await pointerClick(a, a.locator('#btn-start-draft'));
  await a.waitForSelector('#screen-draft.active', { timeout: 8000 });
  const draftedA = await draftEleven(a, problems, 'duel A draft', async s => !!s && s.screen === 'screen-duel-share');
  check('A finishes the verified draft and reaches the share screen', draftedA, problems.join(' | '));
  await a.waitForSelector('#duel-share-ok', { state: 'visible', timeout: 12000 }).catch(() => {});
  const link = await a.inputValue('#duel-link').catch(() => '');
  const idMatch = link.match(/duel\.php\?id=([A-Za-z0-9]+)$/);
  check('A gets an invite link with a duel id', !!idMatch, link);
  if (!idMatch) { check('no client problems (duel, A)', false, problems.join(' | ')); await A.context.close(); return; }
  const id = idMatch[1];
  state.duelId = id;
  const storeA = await a.evaluate(() => JSON.parse(localStorage.getItem('dcz_duels') || '[]'));
  check('A stores the duel locally as waiting', storeA.length === 1 && storeA[0].id === id && storeA[0].role === 'a' && storeA[0].status === 'waiting', JSON.stringify(storeA));

  // What a stranger can read while the duel is waiting: the constraints, never the squad.
  let r = await api(base, 'GET', `/duel-join.php?id=${id}`);
  check('a waiting duel exposes the challenger nick and the constraints', r.status === 200 && r.json.status === 'waiting' && r.json.a.nick === 'Alice' && r.json.tournament === 'ucl', r.text.slice(0, 200));
  check('a waiting duel never reveals the challenger squad', r.json && !('players' in r.json.a) && !('formation' in r.json.a) && r.json.b === undefined && !/"players"/.test(r.text), r.text.slice(0, 300));
  r = await api(base, 'GET', `/duel.php?id=${id}`);
  check('the invite page carries the challenge title and noindex', r.status === 200 && /Alice ti sfida a duello!/.test(r.text) && /<meta name="robots" content="noindex">/.test(r.text), r.text.slice(0, 200));

  // Player B: a different browser profile opens the link.
  const B = await newPage(browser, base, problems);
  const b = B.page;
  await b.goto(`${base}/index.html?duel=${id}`, { waitUntil: 'load' });
  await b.evaluate(() => { try { localStorage.setItem('dcz_tut', '1'); } catch (e) { /* ignore */ } });
  await b.goto(`${base}/index.html?duel=${id}`, { waitUntil: 'load' });
  await b.waitForSelector('#screen-duel-accept.active', { timeout: 10000 });
  await b.waitForSelector('#duel-acc-main', { state: 'visible', timeout: 8000 });
  const title = await b.locator('#duel-acc-title').textContent();
  check('B sees who challenged them', /Alice/.test(title), title);
  await b.fill('#duel-acc-nick', 'Bob');
  await pointerClick(b, b.locator('#screen-duel-accept button[onclick="duelAcceptGo()"]'));
  await b.waitForSelector('#screen-formation.active', { timeout: 5000 });
  await pointerClick(b, b.locator('#form-grid .form-card:not(#fc-formation-random)').nth(1));
  await pointerClick(b, b.locator('#btn-start-draft'));
  await b.waitForSelector('#screen-draft.active', { timeout: 8000 });
  // The series (up to 3 full matches, possibly with a penalty shootout with no skip control)
  // runs noticeably longer than a single campaign match, hence the wider timeout.
  const seriesPlayed = await autoplay(b, problems, 'duel B series', async (s, url) => /\/duel\.php\?id=/.test(url), false, 240000);
  check('B drafts, the server plays the series and B is sent to the verdict page', seriesPlayed, problems.join(' | '));

  await b.waitForSelector('#duel-content', { state: 'visible', timeout: 8000 }).catch(() => {});
  const verdict = await b.locator('body').innerText();
  check('the verdict page names both players and a winner', /Alice/.test(verdict) && /Bob/.test(verdict) && /vince il duello/.test(verdict), verdict.slice(0, 300));
  check('the verdict page shows the verified engine badge', /Match Engine v3/.test(verdict), verdict.slice(0, 300));
  check('the verdict page has no NaN/undefined text', !BAD_TEXT.test(verdict), verdict.match(BAD_TEXT));
  const matchRows = await b.locator('.match-list > *').count();
  check('the verdict lists 2 or 3 matches', matchRows === 2 || matchRows === 3, matchRows);
  const storeB = await b.evaluate(() => JSON.parse(localStorage.getItem('dcz_duels') || '[]'));
  check('B stores the duel as done, seen, against Alice', storeB.length === 1 && storeB[0].status === 'done' && storeB[0].role === 'b' && storeB[0].opp === 'Alice', JSON.stringify(storeB));
  await B.context.close();

  // Public state after the series.
  r = await api(base, 'GET', `/duel-join.php?id=${id}`);
  const d = r.json || {};
  check('the finished duel is done with a verified integrity block', d.status === 'done' && d.integrity && d.integrity.engine === 'server-v3', r.text.slice(0, 200));
  check('the finished duel shows both squads with 11 players', d.a && d.b && d.a.players.length === 11 && d.b.players.length === 11, r.text.slice(0, 200));
  const m = d.result && d.result.matches ? d.result.matches : [];
  const winsA = m.filter(x => x.ga > x.gb || (x.ga === x.gb && x.pa > x.pb)).length;
  const winsB = m.length - winsA;
  check('the series result is consistent with its matches (best of 3)',
    (m.length === 2 || m.length === 3) && d.result.winsA === winsA && d.result.winsB === winsB && Math.max(winsA, winsB) === 2 &&
    d.result.winner === (winsA > winsB ? 'a' : 'b'), JSON.stringify(d.result).slice(0, 300));
  r = await api(base, 'GET', `/duel.php?id=${id}`);
  check('the shared verdict page has the verdict title and noindex', /<title>⚔️ Alice \d–\d Bob/.test(r.text) && /noindex/.test(r.text), (r.text.match(/<title>[^<]*/) || [''])[0]);

  // Player A comes back: the verdict is replayed in the app, then leads to the verdict page.
  await a.goto(`${base}/index.html?duel=${id}`, { waitUntil: 'load' });
  const replay = await autoplay(a, problems, 'duel A replay', async (s, url) => /\/duel\.php\?id=/.test(url), false, 240000);
  check('A opens the finished duel, watches the replay and reaches the verdict page', replay, problems.join(' | '));
  await a.waitForSelector('#duel-content', { state: 'visible', timeout: 8000 }).catch(() => {});
  const storeA2 = await a.evaluate(() => JSON.parse(localStorage.getItem('dcz_duels') || '[]'));
  check('A stores the duel as done against Bob', storeA2.length === 1 && storeA2[0].status === 'done' && storeA2[0].opp === 'Bob', JSON.stringify(storeA2));
  await A.context.close();

  check('no client problems during the Duel', problems.length === 0, problems.join(' | '));
}

/* ── Duel: API ──────────────────────────────────────────────────────────────────────── */

async function duelApi(base, state) {
  currentGroup = 'duel-api';
  let r = await api(base, 'GET', '/duel-join.php?id=zzzzzzzz');
  check('an unknown duel is 404', r.status === 404, r.status);
  r = await api(base, 'GET', '/duel-join.php?id=a');
  check('a malformed duel id is 400', r.status === 400, r.status);
  r = await api(base, 'GET', '/duel.php?id=zzzzzzzz');
  check('duel.php without a valid duel serves the plain template (no crash)', r.status === 200 && /<html/.test(r.text), r.status);
  r = await api(base, 'POST', '/duel-create.php', 'not json');
  await sleep(3200); // create is limited to one call per 3 seconds and IP, and the call above used it

  if (!state.duelId) return;
  const done = (await api(base, 'GET', `/duel-join.php?id=${state.duelId}`)).json;
  const team = { nick: 'Mallory', formation: done.a.formation, tactic: done.a.tactic || 'balanced', players: done.a.players };

  r = await api(base, 'POST', '/duel-create.php', { tournament: 'ucl', mode: 'classic', eraId: 'alltime', era: 'All Time', lang: 'it', team });
  check('creating a duel without a draft session is refused (400 verified draft required)', r.status === 400 && r.json && r.json.error === 'verified draft required', r.text);
  await sleep(3200);
  r = await api(base, 'POST', '/duel-create.php', { tournament: 'ucl', mode: 'classic', eraId: 'alltime', era: 'All Time', lang: 'it', team, draftSessionId: 'a'.repeat(32) });
  check('a made up draft session id does not create a duel', r.status >= 400 && r.status < 500 && !(r.json && r.json.id), r.text);
  await sleep(3200);
  r = await api(base, 'POST', '/duel-create.php', { tournament: 'nope', team });
  check('an unknown tournament is refused', r.status === 400, r.text);

  await sleep(2200); // draft start is limited to one call per 2 seconds and IP
  r = await api(base, 'POST', '/duel-draft-session.php', { action: 'start', config: {
    role: 'b', mode: 'classic', tournament: done.tournament, eraId: done.eraId, formation: done.b.formation, tactic: 'balanced', club: null, duelId: state.duelId,
  } });
  check('a second challenger cannot start a draft on a finished duel (409)', r.status === 409, r.text);
  r = await api(base, 'POST', '/duel-result.php', { id: state.duelId, phase: 'team', team: done.b, draftSessionId: 'b'.repeat(32) });
  check('a second squad cannot be committed on a finished duel', r.status >= 400 && r.status < 500, r.text);
  await sleep(200);
  const after = (await api(base, 'GET', `/duel-join.php?id=${state.duelId}`)).json;
  check('the finished duel is unchanged by the refused attempts', JSON.stringify(after.result) === JSON.stringify(done.result) && after.b.nick === 'Bob', JSON.stringify(after.b && after.b.nick));
}

/* ── Main ───────────────────────────────────────────────────────────────────────────── */

(async () => {
  const only = (process.env.E2E_ONLY || '').split(',').map(s => s.trim()).filter(Boolean);
  const want = k => !only.length || only.includes(k);
  const php = await startPhp();
  const base = php.base;
  const state = {};
  let browser = null;
  let crashed = null;
  const started = Date.now();
  try {
    if (want('daily-api')) await dailyApi(base);
    const needBrowser = want('daily') || want('duel');
    if (needBrowser) browser = await chromium.launch(process.env.PW_CHROMIUM ? { executablePath: process.env.PW_CHROMIUM } : {});
    if (want('daily')) await dailyBrowser(browser, base);
    if (want('duel')) await duelBrowser(browser, base, state);
    if (want('duel-api')) {
      if (!state.duelId && want('duel') === false) {
        // Stand-alone run: the API checks that need a finished duel are skipped, the rest still run.
      }
      await duelApi(base, state);
    }
    currentGroup = 'server';
    const phpErrors = php.errors();
    check('PHP logged no errors, warnings or notices', phpErrors.length === 0, phpErrors.slice(0, 3).join(' | '));
  } catch (e) {
    crashed = e;
  } finally {
    if (browser) await browser.close().catch(() => {});
    await php.stop();
    try { fs.rmSync(php.work, { recursive: true, force: true }); } catch (e) { /* ignore */ }
  }

  let group = '';
  let failed = 0;
  for (const r of results) {
    if (r.group !== group) { group = r.group; console.log(`\n[${group}]`); }
    console.log(`  ${r.ok ? 'PASS' : 'FAIL'} ${r.name}${r.ok || !r.detail ? '' : '\n       ' + r.detail}`);
    if (!r.ok) failed++;
  }
  console.log(`\n${results.length - failed}/${results.length} checks passed in ${Math.round((Date.now() - started) / 1000)}s`);
  if (crashed) { console.error('\nThe test crashed:', crashed); process.exit(1); }
  if (failed) { console.error(`${failed} check(s) failed`); process.exit(1); }
  console.log('PHP end to end test passed.');
})().catch(e => { console.error(e); process.exit(1); });
