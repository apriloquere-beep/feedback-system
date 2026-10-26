(function () {
  const STATUS_MAP = {
    pending: 'pending',
    in_review: 'in_review',
    inreview: 'in_review',
    resolved: 'resolved',
    archived: 'archived',
    verified: 'resolved',
    adjusted: 'in_review',
    reopen: 'pending',
    reopened: 'pending',
  };

  const SENTIMENT_MAP = {
    positive: 'Positive',
    neutral: 'Neutral',
    negative: 'Negative',
  };

  const REPORT_SENTIMENT_LABELS = ['Positive', 'Neutral', 'Negative'];

  const POSITIVE_WORDS = new Set(['excellent', 'great', 'good', 'helpful', 'friendly', 'kind', 'caring', 'professional', 'courteous', 'polite', 'satisfied', 'happy', 'thank', 'thanks', 'appreciate', 'commend', 'mabuti', 'magaling', 'maayos', 'mabilis']);
  const NEGATIVE_WORDS = new Set(['bad', 'poor', 'terrible', 'awful', 'worst', 'rude', 'slow', 'dirty', 'late', 'unhelpful', 'disappointed', 'frustrated', 'complaint', 'matagal', 'bastos', 'pangit']);

  // Respondent-type categorization. The public form (form/feedback.php)
  // sends one of the four canonical labels; older saved values remain readable.
  const RESPONDENT_TYPE_TO_CATEGORY = {
    'Patient / Client': 'patient',
    'Pasyente/Kliyente': 'patient',
    "Patient's Companion (Relative)": 'companion',
    'Kauban sa Pasyente': 'companion',
    'Business (visited for business purposes)': 'business',
    'Bisita': 'business',
  };

  const RESPONDENT_CATEGORY_LABELS = {
    patient: 'Patient / Client',
    companion: "Patient's Companion (Relative)",
    business: 'Business (visited for business purposes)',
    other: 'Other',
  };

  function normalizeRespondentCategory(entry) {
    const raw = normalizeText(entry && entry.respondentType);
    if (!raw) return 'unspecified';
    if (raw.toLowerCase() === 'other') return 'other';
    return RESPONDENT_TYPE_TO_CATEGORY[raw] || 'other';
  }

  function respondentCategoryLabel(category) {
    return RESPONDENT_CATEGORY_LABELS[category] || 'Unspecified';
  }

  // Fallback only — used if the `staff` table's 10 st0-st9 rows (see
  // api/staff_roles.php, surfaced to the dashboard as modules.staffRoles)
  // haven't loaded yet or the request failed. The database is now the
  // source of truth for labels/active-state; this never overrides it.
  const DEFAULT_STAFF_ROLES = [
    { key: 'st0', label: 'Doctor', isActive: true },
    { key: 'st1', label: 'Dentist', isActive: true },
    { key: 'st2', label: 'Nurse', isActive: true },
    { key: 'st3', label: 'Midwife', isActive: true },
    { key: 'st4', label: 'Sanitary Inspector', isActive: true },
    { key: 'st5', label: 'Barangay Health Aide/Worker', isActive: true },
    { key: 'st6', label: 'Medical Technologist or Laboratory Aide', isActive: true },
    { key: 'st7', label: 'Pharmacist / Pharmacy Assistant', isActive: true },
    { key: 'st8', label: 'Administrative Aide/Clerk', isActive: true },
    { key: 'st9', label: 'Ambulance Driver', isActive: true },
  ];

  // Classifies a single st0-st9 answer as one of '5'..'1', 'NA', or null
  // (no response at all — the key is absent from entry.ratings). Mirrors
  // api/feedback_submit.php's rating_is_answered()/rating_score_value(): a
  // numeric 1-5 value counts toward the average, an explicit "N/A" is a
  // real answer but never counts toward the average, and a missing key
  // (only possible for non-browser test submissions, since st0-st9 are
  // required fields in the live form) counts as no response at all.
  function classifyStaffAnswer(value) {
    if (value === undefined || value === null || value === '') return null;
    if (typeof value === 'string' && value.trim().toUpperCase() === 'N/A') return 'NA';
    const num = Number(value);
    if (!Number.isNaN(num)) {
      const rounded = Math.round(num);
      if (rounded >= 1 && rounded <= 5) return String(rounded);
    }
    return null;
  }

  // Aggregates every entry's staff-role answers (st0, st1, ... — any key
  // present in the live catalog, not a fixed count of 10) into per-role
  // response counts (5/4/3/2/1/N/A) and an average that excludes N/A, plus
  // a combined "overall" figure across every role. Returns roles in the
  // given order (the `staff` table's own `role` ordering).
  //
  // `staffRoles` is the live staff-role catalog (modules.staffRoles from
  // api/staff_roles.php via admin_modules.php) — {id, key, label, isActive}
  // per row, including any admin-added role beyond the original 10. Falls
  // back to DEFAULT_STAFF_ROLES only when that hasn't loaded. This function
  // only ever reads id/labels/active-state from it; the rating math itself
  // (counts/average) is untouched and still keyed purely by whatever
  // answer data is already in `entries`.
  function computeStaffRoleStats(entries = [], staffRoles = null) {
    const source = Array.isArray(staffRoles) && staffRoles.length ? staffRoles : DEFAULT_STAFF_ROLES;
    const roles = source.map((role) => ({
      id: role.id,
      key: role.key,
      label: role.label,
      isActive: role.isActive !== false,
      counts: { 5: 0, 4: 0, 3: 0, 2: 0, 1: 0, NA: 0 },
      responses: 0,
      average: null,
    }));

    let overallSum = 0;
    let overallCount = 0;

    entries.forEach((entry) => {
      const ratings = entry.ratings || {};
      roles.forEach((role) => {
        const outcome = classifyStaffAnswer(ratings[role.key]);
        if (outcome === null) return;
        role.responses += 1;
        if (outcome === 'NA') {
          role.counts.NA += 1;
        } else {
          role.counts[outcome] += 1;
        }
      });
    });

    roles.forEach((role) => {
      const numericResponses = role.responses - role.counts.NA;
      if (numericResponses > 0) {
        const sum = (role.counts[5] * 5) + (role.counts[4] * 4) + (role.counts[3] * 3) + (role.counts[2] * 2) + (role.counts[1] * 1);
        role.average = Math.round((sum / numericResponses) * 10) / 10;
        overallSum += sum;
        overallCount += numericResponses;
      }
    });

    return {
      roles,
      overall: {
        average: overallCount > 0 ? Math.round((overallSum / overallCount) * 10) / 10 : null,
        responses: overallCount,
      },
    };
  }

  function escapeHtml(value) {
    return String(value ?? '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#39;');
  }

  function normalizeText(value) {
    return String(value ?? '').trim();
  }

  function isRatingsOnlyText(value) {
    const text = normalizeText(value);
    if (!text) return false;
    const fields = text.split(/;|\.\.\.|…/).map((field) => field.trim()).filter(Boolean);
    return fields.length >= 1
      && fields.some((field) => /^sqd\d+\s*:/i.test(field))
      && fields.every((field) => /^[a-z][a-z0-9_]*\s*:\s*(?:(?:[1-5](?:\.\d+)?)|n\/a)?$/i.test(field));
  }

  function isWrittenText(value) {
    const text = normalizeText(value);
    return Boolean(text)
      && !isRatingsOnlyText(text)
      && !/^(?:survey submission\.?|general feedback|no comment\.?)$/i.test(text);
  }

  function writtenComment(entry = {}) {
    return [entry.feedback, entry.suggestions, entry.appreciation, entry.subject]
      .map(normalizeText)
      .find(isWrittenText) || '';
  }

  function hasRatingAnswers(entry = {}) {
    return Boolean(entry.ratings && typeof entry.ratings === 'object' && Object.keys(entry.ratings).length)
      || Number(entry.rating || entry.averageRating || 0) > 0;
  }

  function normalizeStatus(value) {
    const key = normalizeText(value).toLowerCase().replace(/\s+/g, '_');
    return STATUS_MAP[key] || 'pending';
  }

  function normalizeSentiment(value, sourceText = '') {
    const key = normalizeText(value).toLowerCase();
    if (SENTIMENT_MAP[key]) {
      return SENTIMENT_MAP[key];
    }
    if (key && !sourceText) return 'Neutral';

    const text = normalizeText(sourceText).toLowerCase();
    if (!text) return 'Neutral';

    if (typeof window.analyzeSentimentJS === 'function') {
      const analyzed = SENTIMENT_MAP[String(window.analyzeSentimentJS(text).label || '').toLowerCase()];
      return analyzed || 'Neutral';
    }

    let positive = 0;
    let negative = 0;
    text.split(/[^a-z0-9\u00C0-\u024F]+/).filter(Boolean).forEach((token) => {
      if (POSITIVE_WORDS.has(token)) positive += 1;
      if (NEGATIVE_WORDS.has(token)) negative += 1;
    });

    if (positive > negative) return 'Positive';
    if (negative > positive) return 'Negative';
    return 'Neutral';
  }

  function normalizeSource(entry) {
    const source = normalizeText(entry.source || entry.formType || entry.userType).toLowerCase();
    if (source.includes('visitor')) return 'Visitor';
    if (source.includes('patient')) return 'Patient';
    return entry.formType === 'visitor' ? 'Visitor' : 'Patient';
  }

  // Reports use the same per-feedback numeric rating in every widget.
  // Zero is the existing N/A marker and is excluded from rating denominators.
  function reportRating(entry) {
    const value = entry.rating ?? entry.averageRating;
    if (value === null || value === undefined || value === '') return null;
    const rating = Number(value);
    return Number.isFinite(rating) && rating >= 1 && rating <= 5 ? rating : null;
  }

  function reportSentimentCounts(entries = []) {
    const counts = Object.fromEntries(REPORT_SENTIMENT_LABELS.map((label) => [label, 0]));
    entries.forEach((entry) => {
      const label = normalizeSentiment(entry.sentimentLabel);
      if (Object.prototype.hasOwnProperty.call(counts, label)) counts[label] += 1;
    });
    return counts;
  }

  function reportTotals(entries = []) {
    const ratings = entries.map(reportRating).filter((rating) => rating !== null);
    const satisfied = ratings.filter((rating) => rating >= 4).length;
    const resolved = entries.filter((entry) => entry.status === 'resolved').length;
    return {
      total: entries.length,
      ratingCount: ratings.length,
      satisfied,
      satisfaction: ratings.length ? Number((satisfied * 100 / ratings.length).toFixed(1)) : null,
      resolved,
      resolutionRate: entries.length ? Number((resolved * 100 / entries.length).toFixed(1)) : null,
      sentimentCounts: reportSentimentCounts(entries),
      sentimentAnalyzedCount: entries.length,
    };
  }

  function reportSentimentTrend(entries = [], granularity = 'monthly') {
    const buckets = new Map();
    entries.forEach((entry) => {
      const sentiment = normalizeSentiment(entry.sentimentLabel);
      if (!REPORT_SENTIMENT_LABELS.includes(sentiment)) return;
      const date = new Date(entry.timestamp || entry.date || '');
      let key = 'Unknown';
      let label = 'Unknown';
      if (!Number.isNaN(date.getTime())) {
        const start = new Date(date.getFullYear(), date.getMonth(), date.getDate());
        if (granularity === 'weekly') start.setDate(start.getDate() - ((start.getDay() + 6) % 7));
        if (granularity === 'monthly') start.setDate(1);
        const day = `${start.getFullYear()}-${String(start.getMonth() + 1).padStart(2, '0')}-${String(start.getDate()).padStart(2, '0')}`;
        key = granularity === 'monthly' ? day.slice(0, 7) : day;
        label = granularity === 'monthly'
          ? start.toLocaleDateString('en-US', { month: 'short', year: 'numeric' })
          : `${granularity === 'weekly' ? 'Week of ' : ''}${start.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`;
      }
      if (!buckets.has(key)) buckets.set(key, { key, label, counts: {}, total: 0 });
      const bucket = buckets.get(key);
      bucket.counts[sentiment] = (bucket.counts[sentiment] || 0) + 1;
      bucket.total += 1;
    });
    return Array.from(buckets.values()).sort((left, right) => left.key.localeCompare(right.key));
  }

  function ratingDistribution(entries = []) {
    const counts = [0, 0, 0, 0, 0];
    entries.forEach((entry) => {
      const rating = reportRating(entry);
      if (rating === null) return;
      const bucket = Math.round(rating);
      if (bucket >= 1 && bucket <= 5) counts[bucket - 1] += 1;
    });
    return counts;
  }

  function ratingTrend(entries = [], granularity = 'monthly') {
    const buckets = new Map();
    entries.forEach((entry) => {
      const rating = reportRating(entry);
      const date = new Date(entry.timestamp || entry.date || '');
      if (rating === null || Number.isNaN(date.getTime())) return;

      let bucketDate = new Date(date.getFullYear(), date.getMonth(), date.getDate());
      if (granularity === 'weekly') {
        const mondayOffset = (bucketDate.getDay() + 6) % 7;
        bucketDate.setDate(bucketDate.getDate() - mondayOffset);
      } else if (granularity === 'monthly') {
        bucketDate = new Date(bucketDate.getFullYear(), bucketDate.getMonth(), 1);
      }

      const year = bucketDate.getFullYear();
      const month = String(bucketDate.getMonth() + 1).padStart(2, '0');
      const day = String(bucketDate.getDate()).padStart(2, '0');
      const key = granularity === 'monthly' ? `${year}-${month}` : `${year}-${month}-${day}`;
      const current = buckets.get(key) || { date: bucketDate, sum: 0, count: 0 };
      current.sum += rating;
      current.count += 1;
      buckets.set(key, current);
    });

    return Array.from(buckets.entries())
      .sort(([left], [right]) => left.localeCompare(right))
      .map(([key, bucket]) => ({
        key,
        label: granularity === 'monthly'
          ? bucket.date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' })
          : granularity === 'weekly'
            ? `Week of ${bucket.date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' })}`
            : bucket.date.toLocaleDateString('en-US', { month: 'short', day: 'numeric', year: 'numeric' }),
        average: Number((bucket.sum / bucket.count).toFixed(2)),
        count: bucket.count,
      }));
  }

  function sentimentByService(entries = [], catalogServices = []) {
    const chartSentiments = REPORT_SENTIMENT_LABELS;
    const services = new Map();

    catalogServices.forEach((catalogService) => {
      if (catalogService?.status && catalogService.status !== 'active') return;
      const serviceId = catalogService?.id ?? null;
      const label = normalizeText(catalogService?.name || 'Unassigned') || 'Unassigned';
      const key = serviceId !== null && serviceId !== '' ? `id:${serviceId}` : `name:${label.toLowerCase()}`;
      if (!services.has(key)) {
        services.set(key, { serviceId, label, total: 0, analyzedCount: 0, ...Object.fromEntries(chartSentiments.map((name) => [name, 0])) });
      }
    });

    entries.forEach((entry) => {
      const serviceId = entry.serviceId ?? null;
      const label = normalizeText(entry.service || 'Unassigned') || 'Unassigned';
      const key = serviceId !== null && serviceId !== '' ? `id:${serviceId}` : `name:${label.toLowerCase()}`;
      if (!services.has(key)) services.set(key, { serviceId, label, total: 0, analyzedCount: 0, ...Object.fromEntries(chartSentiments.map((name) => [name, 0])) });
      const service = services.get(key);
      service.total += 1;
      const sentiment = normalizeSentiment(entry.sentimentLabel);
      service[sentiment] += 1;
      service.analyzedCount += 1;
    });

    const catalogOrder = new Map(catalogServices.map((service, index) => [`id:${service.id}`, index]));
    return Array.from(services.values())
      .sort((left, right) => {
        const leftOrder = catalogOrder.has(`id:${left.serviceId}`) ? catalogOrder.get(`id:${left.serviceId}`) : Number.MAX_SAFE_INTEGER;
        const rightOrder = catalogOrder.has(`id:${right.serviceId}`) ? catalogOrder.get(`id:${right.serviceId}`) : Number.MAX_SAFE_INTEGER;
        return leftOrder - rightOrder || left.label.localeCompare(right.label);
      })
      .map((service) => {
        const percentages = {};
        if (service.analyzedCount === 0) {
          chartSentiments.forEach((label) => { percentages[label] = 0; });
          return { ...service, hasAnalyzedSentiment: false, percentages };
        }
        let runningTotal = 0;
        chartSentiments.forEach((label, index) => {
          if (index === chartSentiments.length - 1) {
            percentages[label] = Number(Math.max(0, 100 - runningTotal).toFixed(2));
            return;
          }
          const percentage = Number(((service[label] / service.analyzedCount) * 100).toFixed(2));
          percentages[label] = percentage;
          runningTotal += percentage;
        });
        return { ...service, hasAnalyzedSentiment: true, percentages };
      });
  }

  function serviceComparison(entries = []) {
    const services = new Map();
    entries.forEach((entry) => {
      const serviceId = entry.serviceId ?? null;
      const label = normalizeText(entry.service || 'Unassigned') || 'Unassigned';
      const key = serviceId !== null && serviceId !== '' ? `id:${serviceId}` : `name:${label.toLowerCase()}`;
      if (!services.has(key)) services.set(key, { serviceId, label, count: 0, ratingTotal: 0, ratingCount: 0, satisfiedCount: 0 });
      const service = services.get(key);
      service.count += 1;

      const rating = reportRating(entry);
      if (rating === null) return;
      service.ratingTotal += rating;
      service.ratingCount += 1;
      if (rating >= 4) service.satisfiedCount += 1;
    });

    return Array.from(services.values())
      .map((service) => ({
        serviceId: service.serviceId,
        label: service.label,
        count: service.count,
        avg: service.ratingCount ? service.ratingTotal / service.ratingCount : null,
        satisfaction: service.ratingCount ? (service.satisfiedCount / service.ratingCount) * 100 : null,
        ratingCount: service.ratingCount,
      }))
      .sort((left, right) => (right.avg ?? -1) - (left.avg ?? -1) || right.count - left.count || left.label.localeCompare(right.label));
  }

  function getAverageRating(entry) {
    if (typeof entry.averageRating === 'number' && !Number.isNaN(entry.averageRating)) {
      return entry.averageRating;
    }

    if (typeof entry.rating === 'number' && !Number.isNaN(entry.rating) && entry.rating > 0) {
      return entry.rating;
    }

    const ratings = [];
    Object.values(entry.ratings || {}).forEach((value) => {
      if (Array.isArray(value)) {
        value.forEach((item) => {
          const score = Number(item);
          if (!Number.isNaN(score) && score > 0) ratings.push(score);
        });
        return;
      }

      if (value && typeof value === 'object' && value.score !== undefined) {
        const score = Number(value.score);
        if (!Number.isNaN(score) && score > 0) ratings.push(score);
        return;
      }

      const score = Number(value);
      if (!Number.isNaN(score) && score > 0) ratings.push(score);
    });

    if (!ratings.length) {
      return 0;
    }

    return Number((ratings.reduce((sum, value) => sum + value, 0) / ratings.length).toFixed(1));
  }

  function formatDate(timestamp) {
    const date = new Date(timestamp);
    if (Number.isNaN(date.getTime())) {
      return '—';
    }

    return date.toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
    });
  }

  function formatDateTime(timestamp) {
    const date = new Date(timestamp);
    if (Number.isNaN(date.getTime())) {
      return '—';
    }

    return date.toLocaleString('en-US', {
      month: 'short',
      day: 'numeric',
      year: 'numeric',
      hour: 'numeric',
      minute: '2-digit',
      hour12: true,
    });
  }

  function respondentTypeLabel(entry) {
    return respondentCategoryLabel(entry.respondentCategory || normalizeRespondentCategory(entry));
  }

  function formatDateKey(timestamp, granularity) {
    const date = new Date(timestamp);
    if (Number.isNaN(date.getTime())) {
      return 'Unknown';
    }

    if (granularity === 'weekly') {
      const start = new Date(date);
      start.setDate(date.getDate() - date.getDay());
      const end = new Date(start);
      end.setDate(start.getDate() + 6);
      return `${start.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })} - ${end.toLocaleDateString('en-US', { month: 'short', day: 'numeric' })}`;
    }

    if (granularity === 'monthly') {
      return date.toLocaleDateString('en-US', { month: 'short', year: 'numeric' });
    }

    return date.toLocaleDateString('en-US', { month: 'short', day: 'numeric' });
  }

  function normalizeFeedback(entry = {}) {
    const timestamp = entry.timestamp || entry.created_at || entry.date || new Date().toISOString();
    const rawFeedback = normalizeText(entry.feedback || entry.message || entry.notes || entry.comment);
    const subject = normalizeText(entry.subject || entry.title);
    const rawSuggestions = normalizeText(entry.suggestions || entry.recommendation);
    const rawAppreciation = normalizeText(entry.appreciation || entry.recognition);
    const feedbackText = isWrittenText(rawFeedback) ? rawFeedback : '';
    const suggestions = isWrittenText(rawSuggestions) ? rawSuggestions : '';
    const appreciation = isWrittenText(rawAppreciation) ? rawAppreciation : '';
    const sourceText = [feedbackText, suggestions].filter(Boolean).join(' ');
    const rating = getAverageRating(entry);
    const sentimentLabel = sourceText
      ? normalizeSentiment(entry.sentimentLabel || entry.sentiment_label || entry.sentiment, sourceText)
      : 'Neutral';
    const status = normalizeStatus(entry.status || entry.reviewStatus || entry.workflowStatus);
    const source = normalizeSource(entry);
    const respondentCategory = normalizeRespondentCategory(entry);
    const anonymous = typeof entry.anonymous === 'boolean' ? entry.anonymous : !!entry.isAnonymous;
    const name = normalizeText(entry.name || [entry.firstName, entry.lastName].filter(Boolean).join(' '));
    const priority = entry.priority === 'High' || (sentimentLabel === 'Negative' && rating > 0 && rating <= 2) ? 'High' : 'Normal';

    return {
      ...entry,
      anonymous,
      isAnonymous: anonymous,
      name: name || 'Guest',
      source,
      respondentCategory,
      rating,
      averageRating: rating,
      timestamp,
      date: entry.date || timestamp.slice(0, 10),
      sentimentLabel,
      sentimentScore: Number(entry.sentimentScore || entry.sentiment_score || 0),
      status,
      priority,
      isHighPriority: priority === 'High',
      service: normalizeText(entry.service || 'Unassigned') || 'Unassigned',
      subject: subject || 'General Feedback',
      feedback: feedbackText,
      suggestions,
      appreciation,
    };
  }

  function flagDuplicates(entries = []) {
    const seen = new Set();
    return entries.map((entry) => {
      const key = `${normalizeText(entry.feedback || '').toLowerCase().slice(0, 80)}|${String(entry.timestamp || '').slice(0, 16)}`;
      const duplicate = seen.has(key);
      seen.add(key);
      return { ...entry, isDuplicate: duplicate };
    });
  }

  function badge(label, className = '') {
    return `<span class="pill ${className}">${escapeHtml(label)}</span>`;
  }

  function sentimentBadge(label) {
    const normalized = normalizeSentiment(label);
    const classes = {
      Positive: 'pill-positive',
      Neutral: 'pill-neutral',
      Negative: 'pill-negative',
    };
    const icons = {
      Positive: '🟢',
      Neutral: '⚪',
      Negative: '🔴',
    };
    return `<span class="pill ${classes[normalized] || 'pill-slate'}">${icons[normalized] || ''} ${escapeHtml(normalized)}</span>`;
  }

  function statusBadge(status) {
    const normalized = normalizeStatus(status);
    const classes = {
      pending: 'pill-amber',
      in_review: 'pill-in-review',
      resolved: 'pill-green',
      archived: 'pill-slate',
    };
    const labels = {
      pending: '⏳ Pending',
      in_review: '🔄 In Review',
      resolved: '✅ Resolved',
      archived: '🗄 Archived',
    };
    return `<span class="pill ${classes[normalized] || 'pill-slate'}">${labels[normalized] || escapeHtml(normalized)}</span>`;
  }

  function priorityBadge(priority) {
    return priority === 'High'
      ? `<span class="priority-badge"><i class="fas fa-fire" style="font-size:9px"></i> High Priority</span>`
      : `<span style="font-size:11px;color:var(--ink-3)">Normal</span>`;
  }

  function sourceBadge(source, name) {
    const cls = source === 'Visitor' ? 'pill-visitor' : 'pill-patient';
    return `<div class="source-chip"><div><span class="pill ${cls}">${escapeHtml(source)}</span></div><div class="source-name">${escapeHtml(name || 'Guest')}</div></div>`;
  }

  const RESPONDENT_BADGE_CLASSES = {
    patient: 'pill-patient',
    companion: 'pill-patient',
    visitor: 'pill-visitor',
    other: 'pill-slate',
  };

  function respondentCategoryBadge(category, name) {
    const cls = RESPONDENT_BADGE_CLASSES[category] || 'pill-slate';
    const label = respondentCategoryLabel(category);
    return `<div class="source-chip"><div><span class="pill ${cls}">${escapeHtml(label)}</span></div><div class="source-name">${escapeHtml(name || 'Guest')}</div></div>`;
  }

  function starRating(rating, count = null) {
    const numeric = Number(rating || 0);
    if (!numeric) return '—';
    const scoreClass = numeric >= 4 ? 'hi' : numeric >= 3 ? 'mid' : 'lo';
    const stars = Math.max(0, Math.min(5, Math.round(numeric)));
    const starStr = '★'.repeat(stars) + '☆'.repeat(5 - stars);
    return `<span class="score ${scoreClass}" style="display:inline-flex;align-items:center;gap:4px"><span>${starStr}</span><span>${numeric.toFixed(1)}</span></span>${count ? ` <span class="rating-count">(${count})</span>` : ''}`;
  }

  function feedbackTableRow(entry, mode = 'all') {
    const scoreClass = entry.rating >= 4 ? 'hi' : entry.rating >= 3 ? 'mid' : entry.rating > 0 ? 'lo' : 'na';
    const reference = escapeHtml(entry.referenceId || '');
    const actionButtons = `
      <div class="actions-dropdown">
        <button class="actions-btn" onclick="viewFeedback('${reference}')" title="View details"><i class="fas fa-eye"></i></button>
        <button class="actions-btn" onclick="toggleActions(this)" title="More options"><i class="fas fa-ellipsis-v"></i></button>
        <div class="dropdown-menu">
          <button class="dropdown-item" onclick="updateStatus('${reference}','in_review')"><i class="fas fa-history"></i> Mark In Review</button>
          <button class="dropdown-item" onclick="updateStatus('${reference}','resolved')"><i class="fas fa-check-circle"></i> Mark Resolved</button>
        </div>
      </div>`;

    if (mode === 'recent') {
      return `
        <tr>
          <td class="recent-feedback-date">${escapeHtml(formatDateTime(entry.timestamp))}</td>
          <td><span class="recent-feedback-type">${escapeHtml(respondentTypeLabel(entry))}</span></td>
          <td class="recent-feedback-service">${escapeHtml(entry.service || '—')}</td>
          <td>${sentimentBadge(entry.sentimentLabel)}</td>
          <td><span class="score ${scoreClass}">${entry.rating ? entry.rating.toFixed(1) : '—'}</span></td>
          <td>${statusBadge(entry.status)}</td>
          <td class="recent-feedback-actions">${actionButtons}</td>
        </tr>`;
    }

    if (mode === 'patient' || mode === 'visitor' || mode === 'companion' || mode === 'business' || mode === 'other') {
      return `
        <tr class="feedback-responsive-row">
          <td data-label="Date" style="white-space:nowrap;font-size:12px">${escapeHtml(entry.date || formatDate(entry.timestamp))}</td>
          <td data-label="Service" style="font-size:12px">${escapeHtml(entry.service || '—')}</td>
          <td data-label="Sentiment">${sentimentBadge(entry.sentimentLabel)}</td>
          <td data-label="Rating"><span class="score ${scoreClass}">${entry.rating ? entry.rating.toFixed(1) : '—'}</span></td>
          <td data-label="Status">${statusBadge(entry.status)}</td>
          <td class="feedback-mobile-respondent" data-label="User Type">${escapeHtml(respondentTypeLabel(entry))}</td>
          <td class="feedback-row-actions" data-label="Actions">${actionButtons}</td>
        </tr>`;
    }

    return `
      <tr>
        <td style="white-space:nowrap;font-size:12px">${escapeHtml(entry.date || formatDate(entry.timestamp))}</td>
        <td><div class="source-stack">${respondentCategoryBadge(entry.respondentCategory, entry.name || 'Guest')}</div></td>
        <td style="font-size:12px">${escapeHtml(entry.service || '—')}</td>
        <td>${sentimentBadge(entry.sentimentLabel)}</td>
        <td><span class="score ${scoreClass}">${entry.rating ? entry.rating.toFixed(1) : '—'}</span></td>
        <td>${statusBadge(entry.status)}</td>
        <td>${actionButtons}</td>
      </tr>`;
  }

  function feedbackCard(entry) {
    const label = entry.sentimentLabel || 'Neutral';
    const icClass = label === 'Positive' ? 'ft-c' : label === 'Negative' ? 'ft-x' : 'ft-s';
    const icon = label === 'Positive' ? 'fas fa-thumbs-up' : label === 'Negative' ? 'fas fa-thumbs-down' : 'fas fa-comment';
    const reference = escapeHtml(entry.referenceId || '');
    const comment = normalizeText(entry.feedback);
    const staffToThank = normalizeText(entry.appreciation);
    const suggestion = normalizeText(entry.suggestions);
    const feedbackFields = [
      isWrittenText(comment) ? `<div class="fb-text"><strong>Comment:</strong> ${escapeHtml(comment)}</div>` : '',
      isWrittenText(staffToThank) ? `<div class="fb-text"><strong>Name of Staff to Thank or Commend:</strong> ${escapeHtml(staffToThank)}</div>` : '',
      isWrittenText(suggestion) ? `<div class="fb-text"><strong>Suggestion:</strong> ${escapeHtml(suggestion)}</div>` : '',
    ].filter(Boolean);
    const feedbackSummary = feedbackFields.length
      ? feedbackFields.join('')
      : `<div class="fb-text">${hasRatingAnswers(entry) ? 'Ratings-only feedback — no written comment.' : 'No written comment provided.'}</div>`;
    const status = normalizeStatus(entry.status);
    const actionButtons = status === 'resolved'
      ? `
        <button class="q-btn pending-btn" onclick="updateStatus('${reference}','pending')">Re-open</button>
        <button class="q-btn edit-btn" onclick="updateStatus('${reference}','in_review')">In Review</button>
        <button class="q-btn view-btn" onclick="viewFeedback('${reference}')">View</button>
        <button class="q-btn print-btn" onclick="printFeedback('${reference}')">Print</button>
      `
      : status === 'in_review'
        ? `
          <button class="q-btn pending-btn" onclick="updateStatus('${reference}','pending')">Re-open</button>
          <button class="q-btn resolve-btn" onclick="updateStatus('${reference}','resolved')">Resolve</button>
        `
        : `
          <button class="q-btn edit-btn" onclick="updateStatus('${reference}','in_review')">In Review</button>
          <button class="q-btn resolve-btn" onclick="updateStatus('${reference}','resolved')">Resolve</button>
        `;

    return `
      <div class="fb-item">
        <div class="fb-ic ${icClass}"><i class="${icon}"></i></div>
        <div class="fb-body">
          <div class="fb-top">
            <span class="fb-dept">${escapeHtml(entry.service || 'Unassigned')}</span>
            ${sentimentBadge(label)}
            ${priorityBadge(entry.priority)}
          </div>
          ${entry.subject && entry.subject !== 'General Feedback' ? `<div class="fb-subj">${escapeHtml(entry.subject)}</div>` : ''}
          ${feedbackSummary}
          <div class="fb-meta">
            Ref: ${escapeHtml(entry.referenceId || 'Local')} · ${escapeHtml(entry.date || formatDate(entry.timestamp))}
            · ${escapeHtml(entry.service || 'Unassigned')}
            · Rating: ${entry.rating > 0 ? entry.rating.toFixed(1) : '—'}
            · ${statusBadge(status)}
          </div>
          <div class="fb-actions">
            ${actionButtons}
          </div>
        </div>
      </div>`;
  }

  function batchDomUpdates(updates = []) {
    requestAnimationFrame(() => {
      updates.forEach((update) => {
        const target = document.getElementById(update.id);
        if (!target) return;
        if (update.html !== undefined) target.innerHTML = update.html;
        if (update.text !== undefined) target.textContent = update.text;
      });
    });
  }

  function filterEntries(entries = [], filters = {}) {
    let output = entries.slice();
    const search = normalizeText(filters.search).toLowerCase();
    const service = normalizeText(filters.service);
    const serviceName = normalizeText(filters.serviceName).toLowerCase();
    // normalizeStatus() defaults an unrecognized/empty value to 'pending' —
    // correct when normalizing a real entry's own status, but wrong here:
    // an empty filter selection ("All Status") must mean "no filter", not
    // "filter to pending only". Check the raw value for emptiness first.
    const statusFilter = normalizeText(filters.status);
    const status = statusFilter ? normalizeStatus(statusFilter) : '';
    const feedbackType = normalizeText(filters.feedbackType).toLowerCase();
    const sentiment = normalizeText(filters.sentiment);
    const respondent = normalizeText(filters.respondent).toLowerCase();
    const minRating = Number(filters.minRating || 0);
    const maxRating = Number(filters.maxRating || 0);
    const rating = normalizeText(filters.rating);
    const datePreset = normalizeText(filters.datePreset);
    const dateFrom = normalizeText(filters.dateFrom);
    const dateTo = normalizeText(filters.dateTo);
    const statusGroup = normalizeText(filters.statusGroup);

    const entryDate = (entry) => normalizeText(entry.timestamp || entry.date).slice(0, 10);
    const localDate = (date) => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;

    if (respondent) {
      output = output.filter((entry) => entry.respondentCategory === respondent);
    }
    if (search) {
      output = output.filter((entry) => {
        const displayedRating = Number(entry.rating || 0) > 0 ? Number(entry.rating).toFixed(1) : 'N/A';
        const searchable = [
          entry.referenceId, entry.service, entry.serviceSubtype, entry.serviceSubtypeName,
          entry.subject, entry.feedback, entry.suggestions, entry.appreciation,
          entry.sentimentLabel, displayedRating, entry.status,
          entry.status === 'in_review' ? 'In Review' : entry.status,
          entry.respondentType, respondentCategoryLabel(entry.respondentCategory),
          entry.name, entry.fullName, entry.email, entry.contactNumber,
          entry.date, entry.visitDate, entry.timestamp, formatDateTime(entry.timestamp),
          new Date(entry.timestamp).toLocaleDateString('en-US', { month: 'long', day: 'numeric', year: 'numeric' }),
          entry.feedbackType, entry.userType, entry.priority,
        ];
        return searchable.some((field) => normalizeText(field).toLowerCase().includes(search));
      });
    }
    if (service) {
      output = output.filter((entry) => String(entry.serviceId ?? '') === service
        || (entry.serviceId == null && serviceName && normalizeText(entry.service).toLowerCase() === serviceName));
    }
    if (status) {
      output = output.filter((entry) => normalizeStatus(entry.status) === status);
    }
    if (feedbackType) {
      output = output.filter((entry) => normalizeText(entry.feedbackType || entry.type).toLowerCase() === feedbackType);
    }
    if (sentiment) {
      output = output.filter((entry) => normalizeSentiment(entry.sentimentLabel) === sentiment);
    }
    if (minRating) {
      output = output.filter((entry) => Number(entry.rating || 0) >= minRating);
    }
    if (maxRating) {
      output = output.filter((entry) => Number(entry.rating || 0) <= maxRating);
    }
    if (rating) {
      output = output.filter((entry) => rating === 'na'
        ? Number(entry.rating || 0) <= 0
        : Number(entry.rating || 0).toFixed(1) === Number(rating).toFixed(1));
    }
    if (statusGroup === 'pending') {
      output = output.filter((entry) => entry.status === 'pending' || entry.status === 'in_review');
    } else if (statusGroup === 'resolved') {
      output = output.filter((entry) => entry.status === 'resolved');
    }
    if (datePreset) {
      const today = new Date();
      const todayKey = localDate(today);
      let start = '';
      let end = todayKey;
      if (datePreset === 'today') start = todayKey;
      if (datePreset === 'week') {
        const monday = new Date(today.getFullYear(), today.getMonth(), today.getDate() - ((today.getDay() + 6) % 7));
        start = localDate(monday);
      }
      if (datePreset === 'month') start = localDate(new Date(today.getFullYear(), today.getMonth(), 1));
      if (datePreset === 'custom') {
        start = dateFrom;
        end = dateTo || dateFrom;
        if (!start && dateTo) end = dateTo;
      }
      if (start || (datePreset === 'custom' && end)) {
        output = output.filter((entry) => {
          const date = entryDate(entry);
          return date && (!start || date >= start) && (!end || date <= end);
        });
      }
    }

    return output;
  }

  window.AdminDashboardFormatters = {
    escapeHtml,
    normalizeText,
    isRatingsOnlyText,
    isWrittenText,
    writtenComment,
    hasRatingAnswers,
    normalizeStatus,
    normalizeSentiment,
    normalizeSource,
    normalizeRespondentCategory,
    respondentCategoryLabel,
    computeStaffRoleStats,
    getAverageRating,
    ratingDistribution,
    ratingTrend,
    sentimentByService,
    serviceComparison,
    reportRating,
    reportSentimentCounts,
    reportSentimentTrend,
    reportTotals,
    formatDate,
    formatDateTime,
    respondentTypeLabel,
    formatDateKey,
    normalizeFeedback,
    flagDuplicates,
    badge,
    sentimentBadge,
    statusBadge,
    priorityBadge,
    sourceBadge,
    respondentCategoryBadge,
    starRating,
    feedbackTableRow,
    feedbackCard,
    batchDomUpdates,
    filterEntries,
  };
})();
