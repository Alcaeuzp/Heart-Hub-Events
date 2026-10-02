// Regression and adversarial checks for payment display and complete CSV exports.
// Runs the shipped dashboard functions in a minimal DOM/network harness, without a site.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const message = { textContent: '' };
const exportButton = { disabled: false, textContent: 'Export CSV', addEventListener() {} };
const content = {};
const dialog = {};
const statusFilter = { dataset: { filter: 'status' }, value: 'pending' };
const modal = { querySelector: selector => selector === '[role="dialog"]' ? dialog : content };
const app = {
  querySelector: selector => selector === '.hherm-modal' ? modal : selector === '.hherm-message' ? message : null,
  querySelectorAll: () => [statusFilter],
  addEventListener() {},
};
const document = {
  getElementById: () => app,
  querySelector: () => exportButton,
  addEventListener() {},
  createElement: tag => tag === 'a' ? { click() {} } : {
    textContent: '',
    get innerHTML() { return String(this.textContent).replaceAll('&', '&amp;').replaceAll('<', '&lt;').replaceAll('>', '&gt;'); },
  },
};
let exportedBlob;
let requests = 0;
let scenario = 'complete';
let totalRows = 10001;
let requestFilters = [];
class HarnessURL extends URL {
  static createObjectURL(blob) { exportedBlob = blob; return 'blob:audit'; }
  static revokeObjectURL() {}
}
const context = vm.createContext({
  document, window: { HHERM: {} }, HHERM: { root: 'https://example.test/applications', nonce: 'fixture' },
  URL: HarnessURL, URLSearchParams, Blob, AbortController, setTimeout: () => 0, clearTimeout() {},
  fetch: async url => {
    requests++;
    const query = new URL(url).searchParams;
    const page = Number(query.get('page'));
    requestFilters.push(query.get('status'));
    assert.equal(query.get('include_stats'), 'false');
    if (page === 1) statusFilter.value = 'approved';
    if (scenario === 'network' && page === 2) return { ok: false, json: async () => ({ message: 'Network failure' }) };
    const total = scenario === 'changed' && page === 2 ? totalRows + 1 : totalRows;
    const start = (page - 1) * 50;
    const items = Array.from({ length: Math.max(0, Math.min(50, total - start)) }, (_, i) => ({ id: start + i + 1, name: start + i === 0 ? '=DANGEROUS(),"quoted"' : `Person ${start + i + 1}` }));
    if (scenario === 'duplicate' && page === 2) items[0].id = 1;
    if (scenario === 'incomplete' && page === 2) items.pop();
    return { ok: true, json: async () => ({ items, total, pages: scenario === 'bad-pages' ? 0 : Math.ceil(total / 50) }) };
  },
});
const source = fs.readFileSync(path.join(__dirname, '../assets/dashboard.js'), 'utf8');
assert.match(source, /\}\)\(\);\s*$/);
vm.runInContext(source.replace(/\}\)\(\);\s*$/, 'globalThis.audit = { historySection, exportCurrent };\n})();'), context);

(async () => {
  const history = context.audit.historySection([{
    id: 1, payment_amount: '25.00', payment_status: 'refunded', payment_method: 'card', event: { title: 'Fixture event' },
  }], 1);
  assert.match(history, /Amount \$25\.00/);
  assert.match(history, /Refunded/);
  assert.doesNotMatch(history, /Paid \$25\.00/);
  console.log('PASS refunded amount retains its status without claiming payment');
  for (const paymentStatus of ['pending', 'failed', 'paid', '', '<script>alert(1)</script>']) {
    const html = context.audit.historySection([{ id: 1, payment_amount: '0', payment_status: paymentStatus }], 1);
    assert.match(html, /Amount \$0\.00/);
    assert.doesNotMatch(html, /<script>/);
    assert.match(html, paymentStatus === '' ? /Payment status not recorded/ : new RegExp(paymentStatus === 'paid' ? 'Paid' : paymentStatus === 'pending' ? 'Pending' : paymentStatus === 'failed' ? 'Failed' : '&lt;script&gt;'));
  }
  console.log('PASS payment status and zero amounts are explicit and escaped');

  const unavailableHistory = context.audit.historySection([], 1, 'Customer history is unavailable. <retry>');
  assert.match(unavailableHistory, /Customer history is unavailable\. &lt;retry&gt;/);
  assert.doesNotMatch(unavailableHistory, /No other event registrations/);
  assert.doesNotMatch(unavailableHistory, /<retry>/);
  console.log('PASS failed customer history is shown as unavailable rather than falsely empty');

  await context.audit.exportCurrent();
  const csv = await exportedBlob.text();
  assert.equal(requests, 201);
  assert.equal(csv.split('\r\n').length - 1, 10001);
  assert.equal(message.textContent, '');
  assert.ok(requestFilters.every(value => value === 'pending'));
  assert.match(csv, /'\=DANGEROUS\(\),""quoted""/);
  assert.equal(exportButton.disabled, false);
  console.log('PASS all 10,001 registrations exported with frozen filters and CSV formula/quote escaping');

  for (const failure of ['duplicate', 'changed', 'incomplete', 'network', 'bad-pages', 'too-large']) {
    scenario = failure; totalRows = failure === 'too-large' ? 100001 : 101;
    exportedBlob = undefined; requests = 0; message.textContent = ''; statusFilter.value = 'pending';
    await context.audit.exportCurrent();
    assert.equal(exportedBlob, undefined, failure);
    assert.ok(message.textContent.length > 0, failure);
    assert.equal(exportButton.disabled, false, failure);
    console.log(`PASS ${failure} export produces an actionable error and no misleading partial CSV`);
  }
  scenario = 'empty'; totalRows = 0; exportedBlob = undefined; requests = 0;
  await context.audit.exportCurrent();
  assert.equal(exportedBlob, undefined);
  assert.match(message.textContent, /no applications/);
  assert.equal(requests, 1);
  console.log('PASS empty result completes without download or extra requests');
})().catch(error => { console.error(error); process.exitCode = 1; });
