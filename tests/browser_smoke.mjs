import { writeFile, mkdir } from 'node:fs/promises';

// Only synthetic fixture accounts and the explicitly isolated local Chrome are used.
const origin = process.env.BROWSER_APP_ORIGIN;
if (!origin || !/^http:\/\/127\.0\.0\.1:\d+$/.test(origin)) {
  throw new Error('BROWSER_APP_ORIGIN must name the isolated localhost application.');
}
const account = process.env.BROWSER_TEST_USER || 'reader';
if (!['reader', 'librarian', 'admin'].includes(account)) {
  throw new Error('Only synthetic fixture accounts are permitted.');
}
// Give every role its own incognito context; a new tab alone shares login cookies.
const version = await (await fetch('http://127.0.0.1:9333/json/version')).json();
const browserSocket = new WebSocket(version.webSocketDebuggerUrl);
await new Promise((resolve, reject) => {
  browserSocket.addEventListener('open', resolve, { once: true });
  browserSocket.addEventListener('error', reject, { once: true });
});
let browserSequence = 0;
const browserPending = new Map();
browserSocket.addEventListener('message', ({ data }) => {
  const message = JSON.parse(String(data));
  const request = browserPending.get(message.id);
  if (!request) return;
  browserPending.delete(message.id);
  clearTimeout(request.timer);
  if (message.error) request.reject(new Error(message.error.message));
  else request.resolve(message.result);
});
function browserCommand(method, params = {}) {
  return new Promise((resolve, reject) => {
    const id = ++browserSequence;
    const timer = setTimeout(() => {
      browserPending.delete(id);
      reject(new Error(`Browser CDP timeout: ${method}`));
    }, 10000);
    browserPending.set(id, { resolve, reject, timer });
    browserSocket.send(JSON.stringify({ id, method, params }));
  });
}
const { browserContextId } = await browserCommand('Target.createBrowserContext');
const { targetId } = await browserCommand('Target.createTarget', { url: 'about:blank', browserContextId });
const targets = await (await fetch('http://127.0.0.1:9333/json/list')).json();
const target = targets.find(item => item.id === targetId);
if (!target) throw new Error('Isolated browser target was not created.');
const socket = new WebSocket(target.webSocketDebuggerUrl);
await new Promise((resolve, reject) => {
  socket.addEventListener('open', resolve, { once: true });
  socket.addEventListener('error', reject, { once: true });
});
let sequence = 0;
const pending = new Map();
const exceptions = [];
const failedRequests = [];
socket.addEventListener('message', ({ data }) => {
  const message = JSON.parse(String(data));
  if (message.id) {
    const request = pending.get(message.id);
    if (!request) return;
    pending.delete(message.id);
    clearTimeout(request.timer);
    if (message.error) request.reject(new Error(message.error.message));
    else request.resolve(message.result);
  }
  if (message.method === 'Page.javascriptDialogOpening') {
    command('Page.handleJavaScriptDialog', { accept: true }).catch(() => {});
  }
  if (message.method === 'Runtime.exceptionThrown') exceptions.push(message.params.exceptionDetails.text);
  if (message.method === 'Network.loadingFailed' && !message.params.canceled) failedRequests.push(message.params.errorText);
});
function command(method, params = {}) {
  return new Promise((resolve, reject) => {
    const id = ++sequence;
    const timer = setTimeout(() => {
      pending.delete(id);
      reject(new Error(`CDP timeout: ${method}`));
    }, 10000);
    pending.set(id, { resolve, reject, timer });
    socket.send(JSON.stringify({ id, method, params }));
  });
}
async function read(expression) {
  const result = await command('Runtime.evaluate', { expression, returnByValue: true });
  if (result.exceptionDetails) throw new Error(result.exceptionDetails.text);
  return result.result.value;
}
async function waitFor(expression) {
  const deadline = Date.now() + 10000;
  while (Date.now() < deadline) {
    if (await read(expression)) return;
    await new Promise(resolve => setTimeout(resolve, 50));
  }
  throw new Error(`Page condition not reached: ${expression}`);
}
async function navigate(page) {
  await command('Page.navigate', { url: `${origin}/index.php?page=${page}` });
  await waitFor(`document.readyState === 'complete' && location.search.includes('page=${page}')`);
}
async function click(selector) {
  const box = await read(`(() => {
    const element = document.querySelector(${JSON.stringify(selector)});
    if (!element) return null;
    const r = element.getBoundingClientRect();
    return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
  })()`);
  if (!box) throw new Error(`Missing clickable element: ${selector}`);
  await command('Input.dispatchMouseEvent', { type: 'mousePressed', button: 'left', clickCount: 1, ...box });
  await command('Input.dispatchMouseEvent', { type: 'mouseReleased', button: 'left', clickCount: 1, ...box });
}
function check(condition, message) {
  if (!condition) throw new Error(message);
  console.log(`PASS ${message}`);
}
await mkdir('tests/artifacts/browser', { recursive: true });
async function screenshot(name) {
  const result = await command('Page.captureScreenshot', { format: 'png', captureBeyondViewport: false });
  await writeFile(`tests/artifacts/browser/${name}.png`, Buffer.from(result.data, 'base64'));
}
try {
  await command('Page.enable');
  await command('Runtime.enable');
  await command('Network.enable');
  await command('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
  await navigate('login');
  check(await read("document.documentElement.lang === 'es' && !!document.querySelector('h1')"), 'Spanish login has a page heading');
  await screenshot(`${account}-login-desktop`);
  await click('input[name="username"]');
  await command('Input.insertText', { text: account });
  await click('input[name="password"]');
  await command('Input.insertText', { text: 'library test password 123' });
  await click('button[type="submit"]');
  await waitFor("location.search.includes('page=dashboard') && document.readyState === 'complete'");
  check(await read("!!document.querySelector('nav[aria-label]')"), 'Authenticated navigation is labelled');
  await screenshot(`${account}-dashboard-desktop`);
  await navigate('books');
  check(await read("document.querySelector('h1')?.textContent.includes('Catálogo') && document.querySelectorAll('tbody tr').length > 0"), 'Catalog renders fixture books');
  await screenshot(`${account}-catalog-desktop`);
  for (const width of [320, 768, 1024, 1440]) {
    await command('Emulation.setDeviceMetricsOverride', { width, height: 900, deviceScaleFactor: 1, mobile: width < 768 });
    check(await read('document.documentElement.scrollWidth <= innerWidth + 1'), `No document horizontal overflow at ${width}px`);
  }
  await command('Emulation.setDeviceMetricsOverride', { width: 320, height: 900, deviceScaleFactor: 1, mobile: true });
  await click('.nav-toggle');
  check(await read("document.querySelector('.nav-toggle').getAttribute('aria-expanded') === 'true' && document.querySelector('#main-nav').getBoundingClientRect().right > 0"), 'Mobile menu opens and announces its state');
  await screenshot(`${account}-catalog-mobile`);
  await command('Emulation.setScriptExecutionDisabled', { value: true });
  await navigate('books');
  await waitFor("!document.documentElement.classList.contains('js')");
  check(await read("(() => { const r = document.querySelector('#main-nav').getBoundingClientRect(); return r.right > 0 && r.left >= 0; })()"), 'Mobile navigation remains usable without JavaScript');
  await screenshot(`${account}-catalog-mobile-nojs`);
  await command('Emulation.setScriptExecutionDisabled', { value: false });
  await command('Emulation.setDeviceMetricsOverride', { width: 1440, height: 1000, deviceScaleFactor: 1, mobile: false });
  if (account === 'reader') {
    await navigate('books');
    await click('tbody form button');
    await waitFor("location.search.includes('page=requests') && document.readyState === 'complete'");
    check(await read("document.querySelector('tbody')?.textContent.includes('Pendiente')"), 'Reader submits a request through the rendered form');
    await screenshot('reader-request');
  } else if (account === 'librarian') {
    await navigate('requests');
    await click('button[data-confirm]');
    await waitFor("location.search.includes('page=loans') && document.readyState === 'complete'");
    check(await read("document.querySelector('tbody')?.textContent.includes('Activo')"), 'Librarian confirms the reader request through the form');
    await screenshot('librarian-active-loan');
    await click('button[data-confirm]');
    await waitFor("document.querySelector('tbody')?.textContent.includes('Devuelto')");
    check(await read("document.querySelector('tbody')?.textContent.includes('Devuelto')"), 'Librarian records return through the rendered form');
  } else {
    await navigate('accounts');
    check(await read("document.querySelector('h1')?.textContent.includes('Cuentas') && !!document.querySelector('select[name=role]')"), 'Administrator accesses account provisioning controls');
    await screenshot('admin-accounts');
  }
  check(exceptions.length === 0, 'No uncaught JavaScript errors');
  check(failedRequests.length === 0, 'No failed network requests');
  console.log('OK browser_smoke');
} finally {
  await command('Emulation.setScriptExecutionDisabled', { value: false }).catch(() => {});
  await command('Page.close').catch(() => {});
  socket.close();
  await browserCommand('Target.disposeBrowserContext', { browserContextId }).catch(() => {});
  browserSocket.close();
}
