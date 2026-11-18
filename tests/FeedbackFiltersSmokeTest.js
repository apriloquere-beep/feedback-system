const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const context = { window: {} };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '..', 'js/admin/formatters.js'), 'utf8'), context);
const { normalizeFeedback, filterEntries } = context.window.AdminDashboardFormatters;
const localDate = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
const today = localDate(new Date());
const oldDate = '2025-01-12';
const entries = [
  { referenceId: 'P-1', respondentType: 'Patient / Client', fullName: 'Ada Reyes', serviceId: 1, service: 'Laboratory', serviceSubtype: 'Hematology', feedback: 'Medical care', sentimentLabel: 'Negative', averageRating: 1, status: 'pending', timestamp: `${today}T09:00:00` },
  { referenceId: 'P-2', respondentType: 'Patient / Client', serviceId: 2, service: 'Consultation', feedback: 'Helpful clinician', sentimentLabel: 'Positive', averageRating: 5, status: 'resolved', timestamp: `${oldDate}T09:00:00` },
  { referenceId: 'C-1', respondentType: "Patient's Companion (Relative)", serviceId: 1, service: 'Laboratory', feedback: 'Waiting time', sentimentLabel: 'Neutral', averageRating: 1, status: 'in_review', timestamp: `${today}T10:00:00` },
  { referenceId: 'B-1', respondentType: 'Business (visited for business purposes)', serviceId: 1, service: 'Laboratory', feedback: 'Business visit', sentimentLabel: 'Positive', averageRating: 2, status: 'resolved', timestamp: `${oldDate}T10:00:00` },
  { referenceId: 'O-1', respondentType: 'Other', serviceId: 2, service: 'Consultation', feedback: 'Other comment', sentimentLabel: 'Neutral', averageRating: 0, status: 'pending', timestamp: `${today}T11:00:00` },
  { referenceId: 'U-1', respondentType: null, serviceId: 2, service: 'Consultation', feedback: 'Legacy', sentimentLabel: 'Neutral', averageRating: 3, status: 'pending', timestamp: `${oldDate}T11:00:00` },
].map(normalizeFeedback);

const ids = (filters) => filterEntries(entries, filters).map((entry) => entry.referenceId).sort().join(',');
const expect = (filters, expected) => assert.equal(ids(filters), expected.split(',').sort().join(','), JSON.stringify(filters));

expect({ search: 'Medical' }, 'P-1');
expect({ search: 'P-1' }, 'P-1');
expect({ search: 'Hematology' }, 'P-1');
expect({ search: 'Ada Reyes' }, 'P-1');
expect({ search: 'Negative' }, 'P-1');
expect({ search: '5.0' }, 'P-2');
expect({ search: 'In Review' }, 'C-1');
expect({ search: today }, 'P-1,C-1,O-1');
expect({ search: new Date(`${today}T09:00:00`).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }) }, 'P-1,C-1,O-1');
expect({ status: 'pending' }, 'P-1,O-1,U-1');
expect({ status: 'in_review' }, 'C-1');
expect({ status: 'resolved' }, 'P-2,B-1');
expect({ service: '1' }, 'P-1,C-1,B-1');
expect({ rating: '1' }, 'P-1,C-1');
expect({ rating: 'na' }, 'O-1');
expect({ datePreset: 'today' }, 'P-1,C-1,O-1');
expect({ datePreset: 'week' }, 'P-1,C-1,O-1');
expect({ datePreset: 'month' }, 'P-1,C-1,O-1');
expect({ datePreset: 'custom', dateFrom: oldDate }, 'P-2,B-1,U-1');
expect({ datePreset: 'custom', dateTo: oldDate }, 'P-2,B-1,U-1');
expect({ datePreset: 'custom', dateFrom: oldDate, dateTo: today }, 'P-1,P-2,C-1,B-1,O-1,U-1');
expect({ search: 'Medical', status: 'pending' }, 'P-1');
expect({ search: 'Medical', service: '1' }, 'P-1');
expect({ search: 'Medical', rating: '1' }, 'P-1');
expect({ search: 'Medical', datePreset: 'today' }, 'P-1');
expect({ search: 'Medical', service: '1', rating: '1', status: 'pending', datePreset: 'today' }, 'P-1');
expect({ search: '', service: '1', rating: '1' }, 'P-1,C-1');
expect({ datePreset: '', dateFrom: today }, 'P-1,P-2,C-1,B-1,O-1,U-1');
expect({ rating: '' }, 'P-1,P-2,C-1,B-1,O-1,U-1');
expect({ respondent: 'patient' }, 'P-1,P-2');
expect({ respondent: 'companion' }, 'C-1');
expect({ respondent: 'business' }, 'B-1');
expect({ respondent: 'other' }, 'O-1');
expect({ statusGroup: 'pending' }, 'P-1,C-1,O-1,U-1');
expect({ statusGroup: 'resolved' }, 'P-2,B-1');
expect({ search: 'not present', respondent: 'patient' }, '');
expect({}, 'P-1,P-2,C-1,B-1,O-1,U-1');

const legacyService = normalizeFeedback({ respondentType: 'Other', service: 'Clinic', serviceId: null, averageRating: 4 });
assert.equal(filterEntries([legacyService], { service: '9', serviceName: 'Clinic' }).length, 1);
assert.equal(normalizeFeedback({ respondentType: 'Bisita' }).respondentCategory, 'business');
assert.equal(context.window.AdminDashboardFormatters.respondentTypeLabel(normalizeFeedback({ respondentType: 'Bisita' })), 'Business (visited for business purposes)');
for (const [label, category] of [
  ['Patient / Client', 'patient'],
  ["Patient's Companion (Relative)", 'companion'],
  ['Business (visited for business purposes)', 'business'],
  ['Other', 'other'],
]) {
  const entry = normalizeFeedback({ respondentType: label });
  assert.equal(entry.respondentCategory, category);
  assert.equal(context.window.AdminDashboardFormatters.respondentTypeLabel(entry), label);
}

const elements = new Map();
context.document = {
  getElementById(id) {
    if (!elements.has(id)) elements.set(id, { innerHTML: '', textContent: '', value: '' });
    return elements.get(id);
  },
};
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '..', 'js/admin/renderers.js'), 'utf8'), context);
const renderers = context.window.AdminDashboardRenderers;
context.window.AdminDashboardServiceFilter = 'all';
context.document.getElementById('serviceSearch').value = 'BLOOD';
renderers.renderModules({
  entries: [],
  modules: {
    services: [
      { id: 1, name: 'Primary Care', status: 'active', averageRating: 4, subservices: [{ name: 'Blood Panel' }], subserviceNames: ['Blood Panel', 'Archived MRI'] },
      { id: 2, name: 'Dental', status: 'active', averageRating: 4, subservices: [] },
    ],
    audit: [],
  },
});
assert.match(elements.get('servicesTableBody').innerHTML, /Primary Care/);
assert.doesNotMatch(elements.get('servicesTableBody').innerHTML, />Dental</);
assert.equal(elements.get('serviceSearchStatus').textContent, '1 matching service');
context.document.getElementById('serviceSearch').value = 'ARCHIVED MRI';
renderers.renderModules({
  entries: [],
  modules: {
    services: [
      { id: 1, name: 'Primary Care', status: 'active', averageRating: 4, subservices: [{ name: 'Blood Panel' }], subserviceNames: ['Blood Panel', 'Archived MRI'] },
      { id: 2, name: 'Dental', status: 'active', averageRating: 4, subservices: [], subserviceNames: [] },
    ],
    audit: [],
  },
});
assert.match(elements.get('servicesTableBody').innerHTML, /Primary Care/);
assert.doesNotMatch(elements.get('servicesTableBody').innerHTML, />Dental</);
context.document.getElementById('serviceSearch').value = 'no service';
renderers.renderModules({ entries: [], modules: { services: [], audit: [] } });
assert.match(elements.get('servicesTableBody').innerHTML, /No results found/);

renderers.renderStaffRatingsTable('staffBody', [
  { id: 1, key: 'st12', label: 'Front Desk', isActive: true, average: 4, counts: { 5: 1, 4: 0, 3: 0, 2: 0, 1: 0, NA: 0 }, responses: 1 },
  { id: 2, key: 'st13', label: 'Nursing', isActive: true, average: 4, counts: { 5: 1, 4: 0, 3: 0, 2: 0, 1: 0, NA: 0 }, responses: 1 },
], 'all', 'ST12');
assert.match(elements.get('staffBody').innerHTML, /Front Desk/);
assert.doesNotMatch(elements.get('staffBody').innerHTML, /Nursing/);
assert.equal(elements.get('staffSearchStatus').textContent, '1 matching staff role');

renderers.renderAuditTable([
  { timestamp: '2026-11-18T10:00:00', adminName: 'April Admin', action: 'Admin Login', referenceId: '', detail: 'Successful login' },
], 'april');
assert.match(elements.get('auditBody').innerHTML, /April Admin/);
renderers.renderAuditTable([
  { timestamp: '2026-11-18T10:00:00', adminName: 'April Admin', action: 'Admin Login', referenceId: '', detail: 'Successful login' },
], 'no-match');
assert.match(elements.get('auditBody').innerHTML, /No results found/);

console.log('Feedback filter smoke tests passed.');
