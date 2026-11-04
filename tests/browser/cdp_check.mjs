#!/usr/bin/env node
// Headless-Chrome page checker for STS (no dependencies; Node 22+ for WebSocket).
//
//   node tests/browser/cdp_check.mjs URL [URL...]
//   node tests/browser/cdp_check.mjs --steps steps.json
//
// For each page it reports CSP violations, console errors, uncaught exceptions,
// dialogs (accepted automatically) and failed step assertions, as JSON on stdout.
// Exit status is 1 when any page had a violation, error or failed step.
//
// steps.json: [{"url": "...", "cookie": "name=value"?, "allowConsole": ["regex"]?, "actions": [
//   {"click": "css selector"}, {"type": ["css selector", "text"]},
//   {"select": ["css selector", "value"]}, {"wait": 500},
//   {"waitFor": "css selector"}, {"expect": "js expression that must be truthy"},
//   {"eval": "js expression"},
//   {"tap": "css selector"}            touch tap (touchstart/touchend) at the element centre
//   {"touchDrag": ["from css", "to css"]}  touch drag between two element centres
//   {"screenshot": "/abs/path.png"}    save a PNG of the viewport
// ]}]
// "device": "tablet" (1024x768, touch, mobile viewport) or "phone" (390x844) emulates a
// touch device; without it the page runs as desktop Chrome. This is Chrome's
// emulation, not a real tablet.
// Strings in steps.json may use ${ENV_VAR} placeholders (e.g. "${STS_TEST_TOKEN}",
// "${STS_BASE}"); they are filled from the environment so no token is committed.
// allowConsole: console errors matching any regex are expected (e.g. a signed-out
// 401 from /auth/me) and don't fail the page.
// Env: CHROME (path to the Chrome binary), CDP_TIMEOUT_MS (default 15000),
// CDP_NO_SANDBOX=1 (pass --no-sandbox; needed on CI runners that restrict user namespaces).

import { spawn } from 'node:child_process';
import { mkdtempSync, readFileSync, rmSync, existsSync, writeFileSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';

const CHROME = process.env.CHROME || '/Applications/Google Chrome.app/Contents/MacOS/Google Chrome';
const TIMEOUT = Number(process.env.CDP_TIMEOUT_MS || 15000);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function parseArgs(argv) {
  const pages = [];
  for (let i = 0; i < argv.length; i++) {
    if (argv[i] === '--steps') {
      const raw = readFileSync(argv[++i], 'utf8').replace(/\$\{([A-Z0-9_]+)\}/g, (m, k) => {
        if (process.env[k] === undefined) throw new Error('steps file needs env ' + k);
        return process.env[k];
      });
      pages.push(...JSON.parse(raw));
    } else {
      pages.push({ url: argv[i], actions: [] });
    }
  }
  return pages;
}

async function launch() {
  const dir = mkdtempSync(join(tmpdir(), 'sts-cdp-'));
  const proc = spawn(CHROME, [
    '--headless=new', '--disable-gpu', '--no-first-run', '--no-default-browser-check',
    ...(process.env.CDP_NO_SANDBOX ? ['--no-sandbox'] : []), '--remote-debugging-port=0', `--user-data-dir=${dir}`, 'about:blank',
  ], { stdio: 'ignore' });
  const portFile = join(dir, 'DevToolsActivePort');
  // A cold Chrome on a CI runner can take well over 10s to write its port file.
  for (let i = 0; i < 600 && !existsSync(portFile); i++) await sleep(100);
  if (!existsSync(portFile)) {
    throw new Error('Chrome did not start within 60s (no DevToolsActivePort); is CHROME set, and does it need CDP_NO_SANDBOX=1?');
  }
  const port = readFileSync(portFile, 'utf8').split('\n')[0].trim();
  return { proc, dir, port };
}

class Session {
  constructor(wsUrl) {
    this.ws = new WebSocket(wsUrl);
    this.id = 0;
    this.pending = new Map();
    this.listeners = [];
    this.ws.onmessage = (m) => {
      const msg = JSON.parse(m.data);
      if (msg.id && this.pending.has(msg.id)) {
        const { resolve, reject } = this.pending.get(msg.id);
        this.pending.delete(msg.id);
        msg.error ? reject(new Error(msg.error.message)) : resolve(msg.result);
      } else if (msg.method) {
        this.listeners.forEach((fn) => fn(msg));
      }
    };
  }
  open() {
    return new Promise((res, rej) => { this.ws.onopen = res; this.ws.onerror = rej; });
  }
  send(method, params = {}) {
    const id = ++this.id;
    this.ws.send(JSON.stringify({ id, method, params }));
    return new Promise((resolve, reject) => {
      this.pending.set(id, { resolve, reject });
      setTimeout(() => {
        if (this.pending.has(id)) {
          this.pending.delete(id);
          reject(new Error(`timeout: ${method}`));
        }
      }, TIMEOUT);
    });
  }
  on(fn) { this.listeners.push(fn); }
  close() { this.ws.close(); }
}

const PROBE = `
  window.__sts_csp = [];
  document.addEventListener('securitypolicyviolation', function (e) {
    window.__sts_csp.push((e.violatedDirective || '') + ' blocked ' + (e.blockedURI || 'inline') +
      (e.sourceFile ? ' at ' + e.sourceFile + ':' + e.lineNumber : '') +
      (e.sample ? ' [' + e.sample + ']' : ''));
  }, true);
`;

async function evaluate(s, expr) {
  const r = await s.send('Runtime.evaluate', { expression: expr, awaitPromise: true, returnByValue: true });
  if (r.exceptionDetails) throw new Error(r.exceptionDetails.exception?.description || r.exceptionDetails.text);
  return r.result.value;
}

async function waitLoad(s, loaded) {
  const t0 = Date.now();
  while (!loaded.v && Date.now() - t0 < TIMEOUT) await sleep(50);
  await sleep(300);
}

async function checkPage(port, page) {
  const target = await (await fetch(`http://127.0.0.1:${port}/json/new?about:blank`, { method: 'PUT' })).json();
  const s = new Session(target.webSocketDebuggerUrl);
  await s.open();
  const out = { url: page.url, csp: [], console_errors: [], exceptions: [], dialogs: [], failed: [] };
  const loaded = { v: false };
  s.on((m) => {
    if (m.method === 'Page.loadEventFired') loaded.v = true;
    if (m.method === 'Runtime.exceptionThrown') {
      out.exceptions.push(m.params.exceptionDetails.exception?.description || m.params.exceptionDetails.text);
    }
    if (m.method === 'Runtime.consoleAPICalled' && m.params.type === 'error') {
      out.console_errors.push(m.params.args.map((a) => a.value ?? a.description ?? '').join(' '));
    }
    if (m.method === 'Log.entryAdded' && m.params.entry.level === 'error') {
      const t = m.params.entry.text || '';
      if (/Content Security Policy/i.test(t)) out.csp.push(t);
      else if (!/favicon/.test(m.params.entry.url || '')) out.console_errors.push(t);
    }
    if (m.method === 'Page.javascriptDialogOpening') {
      out.dialogs.push(`${m.params.type}: ${m.params.message}`);
      s.send('Page.handleJavaScriptDialog', { accept: true, promptText: '' }).catch(() => {});
    }
  });
  await s.send('Page.enable');
  await s.send('Runtime.enable');
  await s.send('Log.enable');
  await s.send('Page.addScriptToEvaluateOnNewDocument', { source: PROBE });
  const DEVICES = {
    tablet: { width: 1024, height: 768, deviceScaleFactor: 2, mobile: true },
    phone: { width: 390, height: 844, deviceScaleFactor: 3, mobile: true },
  };
  if (!page.device) {
    // A fixed desktop viewport, so coordinate-based checks (drag and drop) behave
    // the same on a CI runner as on a workstation.
    await s.send('Emulation.setDeviceMetricsOverride', { width: 1280, height: 900, deviceScaleFactor: 1, mobile: false });
  }
  if (page.device && DEVICES[page.device]) {
    await s.send('Emulation.setDeviceMetricsOverride', DEVICES[page.device]);
    await s.send('Emulation.setTouchEmulationEnabled', { enabled: true, maxTouchPoints: 5 });
    await s.send('Emulation.setEmitTouchEventsForMouse', { enabled: true, configuration: 'mobile' }).catch(() => {});
  }
  if (page.cookie) {
    const [name, ...rest] = page.cookie.split('=');
    const u = new URL(page.url);
    await s.send('Network.enable');
    await s.send('Network.setCookie', { name, value: rest.join('='), domain: u.hostname, path: '/sts/' });
  }
  await s.send('Page.navigate', { url: page.url });
  await waitLoad(s, loaded);
  for (const a of page.actions || []) {
    try {
      if (a.wait) await sleep(a.wait);
      if (a.waitFor) {
        const t0 = Date.now();
        while (!(await evaluate(s, `!!document.querySelector(${JSON.stringify(a.waitFor)})`))) {
          if (Date.now() - t0 > TIMEOUT) throw new Error('waitFor timed out: ' + a.waitFor);
          await sleep(100);
        }
      }
      if (a.click) {
        loaded.v = false;
        const ok = await evaluate(s, `(function(){var e=document.querySelector(${JSON.stringify(a.click)});if(!e)return false;e.click();return true;})()`);
        if (!ok) throw new Error('no element for click: ' + a.click);
        await sleep(400);
        if (loaded.v === false) await sleep(200);
        else await waitLoad(s, loaded);
      }
      if (a.type) {
        const [sel, text] = a.type;
        const ok = await evaluate(s, `(function(){var e=document.querySelector(${JSON.stringify(sel)});if(!e)return false;e.focus();e.value=${JSON.stringify(text)};e.dispatchEvent(new Event('input',{bubbles:true}));e.dispatchEvent(new Event('change',{bubbles:true}));return true;})()`);
        if (!ok) throw new Error('no element for type: ' + sel);
      }
      if (a.select) {
        const [sel, val] = a.select;
        const ok = await evaluate(s, `(function(){var e=document.querySelector(${JSON.stringify(sel)});if(!e)return false;e.value=${JSON.stringify(val)};e.dispatchEvent(new Event('change',{bubbles:true}));return true;})()`);
        if (!ok) throw new Error('no element for select: ' + sel);
        await sleep(300);
      }
      if (a.expect) {
        const v = await evaluate(s, a.expect);
        if (!v) throw new Error('expect failed: ' + a.expect);
      }
      if (a.eval) await evaluate(s, a.eval);
      if (a.tap || a.touchDrag) {
        const centre = async (sel) => {
          const r = await evaluate(s, `(function(){var e=document.querySelector(${JSON.stringify(sel)});if(!e)return null;e.scrollIntoView({block:'center'});var b=e.getBoundingClientRect();return {x:b.left+b.width/2,y:b.top+b.height/2};})()`);
          if (!r) throw new Error('no element for touch: ' + sel);
          return r;
        };
        if (a.tap) {
          loaded.v = false;
          const p = await centre(a.tap);
          await s.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: p.x, y: p.y }] });
          await s.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
          await sleep(500);
          if (loaded.v) await waitLoad(s, loaded);
        } else {
          const [from, to] = a.touchDrag;
          const p0 = await centre(from);
          const p1 = await evaluate(s, `(function(){var e=document.querySelector(${JSON.stringify(to)});if(!e)return null;var b=e.getBoundingClientRect();return {x:b.left+b.width/2,y:b.top+b.height/2};})()`);
          if (!p1) throw new Error('no element for touch: ' + to);
          await s.send('Input.dispatchTouchEvent', { type: 'touchStart', touchPoints: [{ x: p0.x, y: p0.y }] });
          for (let i = 1; i <= 10; i++) {
            await s.send('Input.dispatchTouchEvent', { type: 'touchMove', touchPoints: [{ x: p0.x + (p1.x - p0.x) * i / 10, y: p0.y + (p1.y - p0.y) * i / 10 }] });
            await sleep(30);
          }
          await s.send('Input.dispatchTouchEvent', { type: 'touchEnd', touchPoints: [] });
          await sleep(400);
        }
      }
      if (a.screenshot) {
        const shot = await s.send('Page.captureScreenshot', { format: 'png' });
        writeFileSync(a.screenshot, Buffer.from(shot.data, 'base64'));
      }
    } catch (e) {
      out.failed.push(String(e.message || e));
    }
  }
  try {
    out.csp.push(...((await evaluate(s, 'window.__sts_csp || []')) || []));
  } catch (e) { /* page gone */ }
  out.csp = [...new Set(out.csp)];
  if (page.allowConsole && page.allowConsole.length) {
    const allow = page.allowConsole.map((r) => new RegExp(r));
    out.console_errors = out.console_errors.filter((t) => !allow.some((re) => re.test(t)));
  }
  s.close();
  await fetch(`http://127.0.0.1:${port}/json/close/${target.id}`).catch(() => {});
  out.ok = !out.csp.length && !out.console_errors.length && !out.exceptions.length && !out.failed.length;
  return out;
}

const pages = parseArgs(process.argv.slice(2));
if (!pages.length) {
  console.error('usage: cdp_check.mjs URL [URL...] | --steps steps.json');
  process.exit(2);
}
const { proc, dir, port } = await launch();
let bad = 0;
const results = [];
try {
  for (const p of pages) {
    const r = await checkPage(port, p).catch((e) => ({ url: p.url, ok: false, failed: [String(e)] }));
    if (!r.ok) bad++;
    results.push(r);
  }
} finally {
  // Let Chrome exit before deleting its profile; it may still be writing to it.
  const exited = new Promise((r) => proc.once('exit', r));
  proc.kill();
  await Promise.race([exited, sleep(5000)]);
  try {
    rmSync(dir, { recursive: true, force: true, maxRetries: 10, retryDelay: 200 });
  } catch (e) {
    console.error('cdp_check: could not remove ' + dir + ': ' + e.message);
  }
}
console.log(JSON.stringify(results.length === 1 ? results[0] : results, null, 1));
process.exit(bad ? 1 : 0);
