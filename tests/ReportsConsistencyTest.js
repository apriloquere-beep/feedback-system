const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const context = { window: {} };
vm.runInNewContext(fs.readFileSync(path.join(__dirname, '..', 'js/admin/formatters.js'), 'utf8'), context);
const reports = context.window.AdminDashboardFormatters;
const entries = [
  { serviceId: 1, service: 'Clinic', rating: 5, sentimentLabel: 'Positive', feedback: 'Excellent service.', status: 'resolved', timestamp: '2026-09-21T09:00:00' },
  { serviceId: 1, service: 'Clinic', rating: 1, sentimentLabel: 'Not Sure', suggestions: 'The wait was slow.', status: 'pending', timestamp: '2026-09-21T10:00:00' },
  { serviceId: 2, service: 'Laboratory', rating: 4, sentimentLabel: 'Mixed', feedback: 'Helpful staff, but rude treatment.', status: 'in_review', timestamp: '2026-09-22T09:00:00' },
  { serviceId: 2, service: 'Laboratory', rating: 0, sentimentLabel: 'Negative', feedback: 'Bad service.', status: 'archived', timestamp: '2026-09-22T10:00:00' },
  { serviceId: null, service: 'Unassigned', rating: 2, sentimentLabel: 'Neutral', feedback: 'Service was okay.', status: 'pending', timestamp: '2026-09-22T11:00:00' },
].map(reports.normalizeFeedback);

const totals = reports.reportTotals(entries);
assert.equal(totals.total, 5);
assert.equal(totals.ratingCount, 4);
assert.equal(totals.satisfied, 2);
assert.equal(totals.satisfaction, 50);
assert.equal(totals.resolved, 1);
assert.equal(totals.resolutionRate, 20);
assert.equal(reports.reportTotals([{ rating: 5, status: 'verified', sentimentLabel: 'Positive' }]).resolved, 0);
assert.equal(Object.values(totals.sentimentCounts).reduce((sum, count) => sum + count, 0), 5);
assert.deepEqual(JSON.parse(JSON.stringify(totals.sentimentCounts)), { Positive: 1, Neutral: 2, Negative: 2 });
assert.equal(totals.sentimentAnalyzedCount, 5);
assert.equal(entries[1].sentimentLabel, 'Negative', 'unsupported historical labels must be derived from written feedback');
assert.equal(entries[2].sentimentLabel, 'Neutral', 'balanced written feedback must resolve to Neutral');
assert.equal(reports.normalizeSentiment('Not Sure', 'excellent and helpful'), 'Positive');
assert.equal(reports.normalizeSentiment('Mixed', 'helpful but rude'), 'Neutral');
assert.equal(reports.normalizeSentiment('Not Sure', ''), 'Neutral');
assert.deepEqual(Object.keys(totals.sentimentCounts).sort(), ['Negative', 'Neutral', 'Positive']);

const distribution = reports.ratingDistribution(entries);
assert.equal(Array.from(distribution).join(','), '1,1,0,1,1');
assert.equal(distribution.reduce((sum, count) => sum + count, 0), totals.ratingCount);
const trend = reports.ratingTrend(entries, 'daily');
assert.equal(trend.length, 2);
assert.equal(trend[0].average, 3);
assert.equal(trend[0].count, 2);
assert.equal(trend[1].average, 3);
assert.equal(trend[1].count, 2);

const sentimentsByService = reports.sentimentByService(entries);
const sentimentTrend = reports.reportSentimentTrend(entries, 'daily');
const comparison = reports.serviceComparison(entries);
assert.equal(sentimentsByService.reduce((sum, service) => sum + service.total, 0), totals.total);
assert.equal(sentimentsByService.reduce((sum, service) => sum + service.analyzedCount, 0), totals.sentimentAnalyzedCount);
assert.equal(sentimentTrend.reduce((sum, period) => sum + period.total, 0), totals.sentimentAnalyzedCount);
assert.equal(sentimentTrend.length, 2);
assert.equal(sentimentTrend[0].counts.Positive, 1);
assert.equal(sentimentTrend[0].total, 2);
assert.equal(sentimentTrend[1].counts.Negative, 1);
assert.equal(sentimentTrend[1].counts.Neutral, 2);
assert.equal(comparison.reduce((sum, service) => sum + service.count, 0), totals.total);
for (const service of sentimentsByService) {
  assert.equal(Object.entries(service)
    .filter(([key]) => ['Positive', 'Neutral', 'Negative'].includes(key))
    .reduce((sum, [, count]) => sum + count, 0), service.analyzedCount);
}
const clinic = comparison.find((service) => service.label === 'Clinic');
assert.equal(clinic.count, 2);
assert.equal(clinic.ratingCount, 2);
assert.equal(clinic.avg, 3);
assert.equal(clinic.satisfaction, 50);
const lab = comparison.find((service) => service.label === 'Laboratory');
assert.equal(lab.count, 2);
assert.equal(lab.ratingCount, 1);
assert.equal(lab.avg, 4);
assert.equal(lab.satisfaction, 100);

const filtered = entries.filter((entry) => entry.serviceId === 1);
assert.equal(reports.reportTotals(filtered).total, 2);
assert.equal(reports.sentimentByService(filtered)[0].total, 2);
assert.equal(reports.serviceComparison(filtered)[0].count, 2);
assert.equal(reports.ratingTrend(filtered, 'daily')[0].count, 2);
assert.equal(reports.reportSentimentTrend(filtered, 'daily')[0].total, 2);

const ratingsOnly = reports.normalizeFeedback({
  referenceId: 'REF-ONLY',
  feedback: 'sqd4:5; a2:5; a3:5; c17:',
  ratings: { sqd4: 5, a2: 5, a3: 5 },
  status: 'pending',
});
assert.equal(reports.isRatingsOnlyText('sqd4:5'), true);
assert.equal(reports.reportTotals([ratingsOnly]).sentimentAnalyzedCount, 1);
assert.equal(reports.reportSentimentTrend([ratingsOnly], 'daily').length, 1);
const queueCard = reports.feedbackCard(ratingsOnly);
assert.match(queueCard, /Ratings-only feedback — no written comment\./);
assert.doesNotMatch(queueCard, /sqd4:5/);
assert.match(queueCard, /Neutral/);
assert.match(queueCard, /Resolve/);
assert.match(reports.feedbackCard(reports.normalizeFeedback({ status: 'resolved', feedback: 'Helpful', rating: 5 })), /Re-open/);
assert.match(reports.feedbackCard(reports.normalizeFeedback({ status: 'in_review', feedback: 'Helpful', rating: 5 })), /Resolve/);

const separatedFields = reports.normalizeFeedback({
  referenceId: 'REF-FIELDS',
  feedback: 'Comment with "quotes" & <care>.',
  appreciation: 'Nurse "A."',
  suggestions: 'Add a ramp.',
  rating: 4,
  status: 'pending',
});
const separatedCard = reports.feedbackCard(separatedFields);
assert.match(separatedCard, /Comment:/);
assert.match(separatedCard, /Name of Staff to Thank or Commend:/);
assert.match(separatedCard, /Suggestion:/);
assert.match(separatedCard, /Comment with &quot;quotes&quot; &amp; &lt;care&gt;\./);
assert.match(separatedCard, /Nurse &quot;A\.&quot;/);
assert.match(separatedCard, /Add a ramp\./);

console.log('Reports consistency tests passed.');
