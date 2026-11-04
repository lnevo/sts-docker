#!/usr/bin/env node
// Data preparation for the browser checks (used by run.sh; Node 22, no dependencies).
//
//   node prep.mjs tokens            create admin + operator tokens, sign both in,
//                                   print `export` lines (STS_TEST_TOKEN, STS_OP_TOKEN,
//                                   STS_TEST_COOKIE, STS_OP_COOKIE, STS_COOKIE_NAME)
//   node prep.mjs data base         restore the data source, print discovered values
//   node prep.mjs data session      ... and run a tiny workflow so sessions 1.. exist
//   node prep.mjs recipe JOB...    print the session workflow for those jobs (regenerates
//                                   fixtures/browser_session.workflow.json for LOC TURN)
//   node prep.mjs cleanup           revoke the tokens, remove leftover probe backups
//   node prep.mjs filter in out     copy a steps file to `out`: drop pages whose "needs"
//                                   is not met, and set the sign-in cookie name
//
// Env: STS_BASE (e.g. http://localhost:8981/sts), STS_API_TOKEN or STS_API_TOKEN_FILE
// (admin), STS_BROWSER_BACKUP (data source backup name, default browser_fixture),
// STS_PHOTOS_DIR (optional host side of the photo dir: tiny photos are added for the cars),
// STS_API_BACKUPS_DIR (host side of the stack's backups dir; the committed fixture is
// copied there when the stack does not have it), STS_BROWSER_STATE (dir for token ids).
// Everything is done through the public API, as the checks themselves would.

import { readFileSync, writeFileSync, existsSync, copyFileSync, rmSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const BASE = (process.env.STS_BASE || 'http://localhost:8981/sts').replace(/\/$/, '');
const API = BASE + '/api/v1';
const BACKUP = process.env.STS_BROWSER_BACKUP || 'browser_fixture';
const STATE = process.env.STS_BROWSER_STATE || join(HERE, '.state');
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const log = (...a) => console.error('[prep]', ...a);

function adminToken() {
  if (process.env.STS_API_TOKEN) return process.env.STS_API_TOKEN.trim();
  const f = process.env.STS_API_TOKEN_FILE ||
    (process.env.STS_SANDBOX_DATA ? join(process.env.STS_SANDBOX_DATA, 'config', 'bootstrap_admin.token') : '');
  if (f && existsSync(f)) return readFileSync(f, 'utf8').trim();
  throw new Error('no admin token: set STS_API_TOKEN or STS_API_TOKEN_FILE');
}
const ADMIN = () => adminToken();

async function call(method, path, body, token = ADMIN(), extra = {}) {
  const headers = { ...extra };
  if (token) headers.Authorization = 'Bearer ' + token;
  if (body !== undefined) headers['Content-Type'] = 'application/json';
  const r = await fetch(API + path, { method, headers, body: body === undefined ? undefined : JSON.stringify(body) });
  const text = await r.text();
  let json = null;
  try { json = JSON.parse(text); } catch { /* not JSON */ }
  return { status: r.status, json, data: json && json.data, headers: r.headers, text };
}

async function must(promise, what) {
  const r = await promise;
  if (r.status >= 300) throw new Error(`${what}: HTTP ${r.status} ${r.text.slice(0, 300)}`);
  return r;
}

async function waitTask(id, { timeout = 600000, settle = 0 } = {}) {
  await sleep(settle);
  const end = Date.now() + timeout;
  while (Date.now() < end) {
    const r = await call('GET', '/tasks/' + id);
    if (r.status === 200 && ['succeeded', 'failed'].includes(r.data.status)) {
      if (r.data.status !== 'succeeded') throw new Error(`task ${id} failed: ${JSON.stringify(r.data).slice(0, 400)}`);
      return r.data;
    }
    await sleep(r.status >= 500 ? 1000 : 500); // 5xx: transient while a restore swaps tables
  }
  throw new Error(`task ${id} still running after ${timeout / 1000}s`);
}

const shq = (v) => "'" + String(v).replace(/'/g, "'\\''") + "'";
const out = {};
const exp = (k, v) => { out[k] = v; };
function printExports() {
  for (const [k, v] of Object.entries(out)) console.log(`export ${k}=${shq(v)}`);
}

// ---- tokens ---------------------------------------------------------------

function cookieOf(resp) {
  const all = typeof resp.headers.getSetCookie === 'function' ? resp.headers.getSetCookie() : [resp.headers.get('set-cookie') || ''];
  for (const c of all) {
    const m = /^(sts_api(?:_[A-Za-z0-9]+)?)=([^;]+)/.exec(c);
    if (m) return { name: m[1], value: m[2] };
  }
  return null;
}

async function cmdTokens() {
  const ids = [];
  const mk = async (role) => {
    const r = await must(call('POST', '/admin/tokens', { name: `browser-ci-${role}-${process.pid}`, role }), 'create ' + role + ' token');
    ids.push(r.data.id);
    return r.data.token;
  };
  const admin = await mk('admin');
  const op = await mk('operator');
  writeFileSync(STATE + '.tokens', ids.join('\n') + '\n');
  const login = async (tok) => {
    const r = await call('POST', '/auth/login', { token: tok }, null);
    const c = cookieOf(r);
    if (r.status !== 200 || !c) throw new Error('sign-in failed: HTTP ' + r.status + ' ' + r.text.slice(0, 200));
    return c;
  };
  const ac = await login(admin);
  const oc = await login(op);
  exp('STS_TEST_TOKEN', admin);
  exp('STS_OP_TOKEN', op);
  exp('STS_COOKIE_NAME', ac.name);
  exp('STS_TEST_COOKIE', ac.value);
  exp('STS_OP_COOKIE', oc.value);
  printExports();
}

async function cmdCleanup() {
  const f = STATE + '.tokens';
  if (existsSync(f)) {
    for (const id of readFileSync(f, 'utf8').split('\n').filter(Boolean)) await call('DELETE', '/admin/tokens/' + id);
    writeFileSync(f, '');
  }
  if (existsSync(STATE + '.photos')) {
    for (const p of readFileSync(STATE + '.photos', 'utf8').split('\n').filter(Boolean)) rmSync(p, { force: true });
    writeFileSync(STATE + '.photos', '');
  }
  await removeProbes();
}

// Backups the c4 checks create (they are not always removed by the checks).
// remove_backup is not an API command, so use the maintenance page like the checks.
async function removeProbes() {
  const b = await call('GET', '/backups');
  for (const x of (b.data || [])) {
    if (/^(c4probe|c4lockprobe|c4signedout)/.test(x.name)) {
      await fetch(`${BASE}/remove_backup.php?remove_btn=1&remove_name=${encodeURIComponent(x.name)}`, { headers: { Authorization: 'Bearer ' + ADMIN() } });
    }
  }
}

// ---- data -----------------------------------------------------------------

async function ensureBackup(name) {
  const has = async () => ((await call('GET', '/backups')).data || []).some((b) => b.name === name);
  if (await has()) return;
  const src = join(HERE, 'fixtures', name);
  const dest = process.env.STS_API_BACKUPS_DIR;
  if (dest && existsSync(src)) {
    copyFileSync(src, join(dest, name));
    if (await has()) return;
  }
  const e = new Error(`backup '${name}' is not on the stack` + (existsSync(src) ? ' (set STS_API_BACKUPS_DIR to its host backups dir so the fixture can be copied)' : ''));
  e.code = 'NO_BACKUP';
  throw e;
}

async function restore(name) {
  await ensureBackup(name);
  const r = await must(call('POST', '/admin/restore', { backup: name }), 'restore ' + name);
  await waitTask(r.data.task_id, { settle: 1500 });
}

async function allCars() {
  return (await must(call('GET', '/cars?limit=1000'), 'cars')).data;
}

// A recipe for the tiny session: orders, fills, waybills, then assign / switch
// lists / pick up for each job, and reports.
function sessionRecipe(jobs) {
  const steps = [];
  const add = (f, p = {}) => steps.push({ function: f, params: p });
  add('increment_session');
  add('generate_station_report', { info: 'Pre' });
  add('generate_wheel_report', { info: 'Pre' });
  add('generate_orders', { increment_session: '' });
  add('fill_orders', { percent: '100' });
  add('generate_waybills');
  // each job: assign cars, print its switch lists, pick the cars up (they stay on the train,
  // so the switch list and wheel report pages have rows)
  for (const j of jobs) {
    add('auto_assign_locals', { jobs: j });
    add('generate_switchlists', { jobs: j, format: 'all', title: j, info: 'Outbound' });
    add('pick_up_cars', { job: j });
  }
  add('generate_station_report', { info: 'Post' });
  add('generate_wheel_report', { info: 'Post' });
  return { version: 1, name: 'browser_ci_session', description: 'Tiny session for the browser checks', steps };
}

async function runSession(jobNames) {
  let recipe;
  const committed = join(HERE, 'fixtures', 'browser_session.workflow.json');
  if (BACKUP === 'browser_fixture' && existsSync(committed)) recipe = JSON.parse(readFileSync(committed, 'utf8'));
  else recipe = sessionRecipe(jobNames);
  await must(call('PUT', '/workflows/browser_ci_session', { recipe }), 'save workflow');
  const r = await must(call('POST', '/workflows/browser_ci_session/run', {}), 'run workflow');
  try {
    await waitTask(r.data.task_id);
  } finally {
    await call('DELETE', '/workflows/browser_ci_session');
  }
}

async function get(path, cookie) {
  const r = await fetch(BASE + path, { headers: cookie ? { Cookie: cookie } : {} });
  return { status: r.status, text: await r.text() };
}

async function discover(withSession) {
  const jobs = (await must(call('GET', '/jobs?limit=200'), 'jobs')).data;
  const cars = await allCars();
  const jobsWithSteps = [];
  for (const j of jobs) {
    const d = (await call('GET', '/jobs/' + j.id)).data;
    if (d && d.steps && d.steps.length) jobsWithSteps.push(j);
  }
  const pick = jobsWithSteps.length >= 2 ? jobsWithSteps : jobs;
  if (pick.length < 2) throw new Error('data source needs at least two jobs');
  const [j1, j2] = pick;
  exp('STS_JOB1_ID', j1.id); exp('STS_JOB1_NAME', j1.name);
  exp('STS_JOB2_ID', j2.id); exp('STS_JOB2_NAME', j2.name);
  const withCars = cars.filter((c) => c.location_id);
  const loc = withCars[0];
  exp('STS_LOC_ID', loc.location_id);
  const ships = (await must(call('GET', '/shipments?limit=200'), 'shipments')).data;
  exp('STS_SHIP_ID', ships[0].id); exp('STS_SHIP_CODE', ships[0].code);
  exp('STS_CAR_ID', cars[0].id);
  exp('STS_CAR_MARKS_ENC', encodeURIComponent(cars[0].reporting_marks));
  exp('STS_MARKS_PREFIX', cars[0].reporting_marks.slice(0, 2));
  const locs = (await must(call('GET', '/locations?limit=500'), 'locations')).data;
  exp('STS_LOCATION_ID', locs[0].id);
  if (!withSession) return { jobs: [j1, j2] };
  const sess = (await must(call('GET', '/session'), 'session')).data.session_nbr;
  exp('STS_SESSION', sess);
  const orders = (await must(call('GET', '/car-orders?limit=500'), 'orders')).data;
  exp('STS_WAYBILL', orders.length ? orders[0].waybill_number || orders[0].waybill || orders[0].id : '');
  // a waybill the Waybill report page offers (it lists open ones only)
  const wbPage = await get('/display_waybill.php', '');
  const opts = [...(/<select id="waybill_number"[^>]*>([\s\S]*?)<\/select>/.exec(wbPage.text) || ['', ''])[1].matchAll(/<option value="([^"]+)"/g)];
  exp('STS_WAYBILL_OPEN', opts.length ? opts[0][1] : out.STS_WAYBILL);
  // Session output: the first job directory a phase produced (phase_NN/<job>/).
  const cookie = `${process.env.STS_COOKIE_NAME || 'sts_api'}=${process.env.STS_TEST_COOKIE || ''}`;
  let found = null;
  for (let n = 1; n <= 12 && !found; n++) {
    const ph = 'phase_' + String(n).padStart(2, '0');
    for (const j of [j1, j2]) {
      const idx = await get(`/so.php?f=session_${sess}/${ph}/${encodeURIComponent(j.name)}/index.html`, cookie);
      if (idx.status === 200 && idx.text.includes('print_all.html')) { found = { job: j.name, phase: ph }; break; }
    }
  }
  if (!found) throw new Error(`no switch list output found for session ${sess}`);
  exp('STS_SESSION_JOB', found.job);
  exp('STS_SESSION_PHASE', found.phase);
  const wb = await get(`/so.php?f=session_${sess}/waybills/index.html`, cookie);
  const m = /waybills\/([0-9A-Za-z-]+)\.html/.exec(wb.text) || /data-wb="([0-9A-Za-z-]+)"/.exec(wb.text);
  exp('STS_SESSION_WAYBILL', m ? m[1] : out.STS_WAYBILL);
  return { jobs: [j1, j2] };
}

// A 1x1 JPEG, so the rolling-stock photo popup has something to show when the
// data source brings no photos. Only written when STS_PHOTOS_DIR (host side of the
// stack's photo dir) is set; removed again by `cleanup`.
const TINY_JPEG = '/9j/4AAQSkZJRgABAQEASABIAAD/2wBDAP//////////////////////////////////////////////////////////////////////////////////////wgALCAABAAEBAREA/8QAFBABAAAAAAAAAAAAAAAAAAAAAP/aAAgBAQABPxA=';
function ensurePhotos(cars) {
  const dir = process.env.STS_PHOTOS_DIR;
  if (!dir || !existsSync(dir)) return;
  const made = existsSync(STATE + '.photos') ? readFileSync(STATE + '.photos', 'utf8').split('\n').filter(Boolean) : [];
  for (const c of cars) {
    const p = join(dir, c.id + '.jpg');
    if (!existsSync(p)) { writeFileSync(p, Buffer.from(TINY_JPEG, 'base64')); made.push(p); }
  }
  writeFileSync(STATE + '.photos', made.join('\n') + '\n');
}

async function cmdData(level, tweaks) {
  await removeProbes();
  await restore(BACKUP);
  let { jobs } = await discover(false);
  if (level === 'session') {
    // the sign-in cookie value comes from the environment (run.sh exports it)
    await runSession(jobs.map((j) => j.name));
    await sleep(500);
    ({ jobs } = await discover(true));
  }
  // Data the checks rely on, created through the API instead of assumed:
  const cars = await allCars();
  ensurePhotos(cars);
  const empties = cars.filter((c) => c.status === 'Empty');
  // marks: bad reporting marks (fix_reporting_marks.php removes ampersands)
  if (tweaks.includes('marks')) for (const c of empties.slice(-2)) {
    await must(call('PATCH', '/cars/' + c.id, { reporting_marks: 'AB&' + c.id }), 'bad marks');
  }
  // ops: an operating state for touch checks: open orders (half left unfilled for the
  // fill page) and cars assigned to jobs (pick up / organize)
  if (tweaks.includes('ops')) {
    await must(call('POST', '/commands/generate_orders', { params: {} }), 'generate orders');
    await must(call('POST', '/commands/fill_orders', { params: { percent: 50 } }), 'fill half');
    // The jobs parameter is a comma-separated string (an array arrives as "Array").
    await must(call('POST', '/commands/auto_assign_locals', { params: { jobs: jobs.map((j) => j.name).join(',') } }), 'assign cars');
    ({ jobs } = await discover(false)); // re-read jobs after the assignment
  }
  // offhome: empty cars away from home (reposition page, off-home filter)
  const locs = (await must(call('GET', '/locations?limit=500'), 'locations')).data;
  const away = tweaks.includes('offhome') ? empties.slice(0, 3) : [];
  for (const c of away) {
    const other = locs.find((l) => l.id !== c.home_location_id && l.id !== c.location_id);
    if (other) await must(call('PATCH', '/cars/' + c.id, { location_id: other.id }), 'move off home');
  }
  printExports();
}

// ---- steps filter -----------------------------------------------------------

function cmdFilter(inFile, outFile) {
  const pages = JSON.parse(readFileSync(inFile, 'utf8'));
  const kept = pages.filter((p) => !p.needs || p.needs === BACKUP);
  const skipped = pages.length - kept.length;
  if (skipped) log(`${skipped} page(s) in ${inFile} need '${pages.find((p) => p.needs && p.needs !== BACKUP).needs}', skipped`);
  writeFileSync(outFile, JSON.stringify(kept));
  console.log(kept.length + ' ' + skipped);
}

function cmdReport(file) {
  let r;
  try { r = JSON.parse(readFileSync(file, 'utf8')); } catch { console.log('no result (Chrome did not start or crashed)'); return; }
  for (const p of (Array.isArray(r) ? r : [r])) {
    if (p.ok) continue;
    console.log('FAIL ' + p.url);
    for (const k of ['csp', 'console_errors', 'exceptions', 'failed']) for (const m of (p[k] || [])) console.log('  ' + k + ': ' + String(m).replace(/sts_[0-9a-f]{40}/g, 'sts_***').slice(0, 300));
  }
}

const [cmd, a, b] = process.argv.slice(2);
try {
  if (cmd === 'tokens') await cmdTokens();
  else if (cmd === 'data') await cmdData(a || 'base', process.argv.slice(4));
  else if (cmd === 'cleanup') await cmdCleanup();
  else if (cmd === 'report') cmdReport(a);
  else if (cmd === 'recipe') console.log(JSON.stringify(sessionRecipe(process.argv.slice(3)), null, 1));
  else if (cmd === 'filter') cmdFilter(a, b);
  else { console.error('usage: prep.mjs tokens|data base|session|cleanup|filter in out'); process.exit(2); }
} catch (e) {
  console.error('[prep] ' + e.message);
  process.exit(e.code === 'NO_BACKUP' ? 3 : 1);
}
