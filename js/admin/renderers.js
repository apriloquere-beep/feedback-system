(function () {
  function getEl(id) {
    return document.getElementById(id);
  }

  function setHtml(id, html) {
    const el = getEl(id);
    if (el) {
      el.innerHTML = html;
    }
  }

  function setText(id, text) {
    const el = getEl(id);
    if (el) {
      el.textContent = text;
    }
  }

  function setHtmlBatch(updates) {
    window.AdminDashboardFormatters.batchDomUpdates(updates);
  }

  const SQD_QUESTIONS = {
    sqd4: 'I easily found information on where to go and what to do for my concern at this office/website. (SQD4)',
    sqd3: 'The steps (including payment) I needed to take for my transaction were easy and simple. (SQD3)',
    sqd2: "This office followed the transaction's requirements and steps based on the information provided. (SQD2)",
    sqd1: 'The overall time taken from registration until discharge was reasonable. (SQD1)',
    sqd8: 'I got what I needed from this office, or if denied, my request was properly explained to me. (SQD8)',
    sqd5: 'I paid a reasonable amount for my transaction. (SQD5)',
    sqd6: 'I was treated fairly and courteously. (SQD6)',
    sqd0: 'I am likely to return and would recommend this facility to others. (SQD0)',
  };

  function ratingValue(value) {
    const raw = value && typeof value === 'object' && 'score' in value ? value.score : value;
    const rating = Number(raw);
    return Number.isFinite(rating) && rating >= 1 && rating <= 5 ? rating : null;
  }

  function renderKpis(summary, entries = []) {
    const total = summary.total || 0;
    const pending = summary.pending || 0;
    const resolved = summary.resolved || 0;
    const ratingValues = entries
      .map((entry) => Number(entry.rating))
      .filter((rating) => Number.isFinite(rating) && rating >= 0.5 && rating <= 5);
    const satisfiedCount = ratingValues.filter((rating) => rating >= 4).length;
    const satisfaction = ratingValues.length ? Number(((satisfiedCount / ratingValues.length) * 100).toFixed(1)) : null;
    const averageRating = Number(summary.averageRating || 0).toFixed(1);
    const resolutionRate = Number(summary.resolutionRate || 0).toFixed(1);
    const storedSettings = window.AdminDashboardStore?.getModules?.().settings || {};
    const configuredBenchmark = Number(storedSettings.admin_satisfaction_benchmark?.value);
    const benchmark = Number.isFinite(configuredBenchmark) && configuredBenchmark >= 0 && configuredBenchmark <= 100 ? configuredBenchmark : 80;

    setHtmlBatch([
      { id: 'valTotal', text: total },
      { id: 'valPending', text: pending },
      { id: 'valResolved', text: resolved },
      { id: 'valSat', text: satisfaction !== null ? `${satisfaction}%` : '0%' },
      { id: 'valResRate', text: `${resolutionRate}%` },
      { id: 'valRating', text: averageRating },
      { id: 'valResolution', text: `${resolutionRate}%` },
      { id: 'helperTotal', text: total ? `${total} stored submissions` : 'No feedback submitted yet' },
      { id: 'helperPending', text: '' },
      { id: 'helperResolved', text: '' },
      { id: 'helperSat', text: total ? `Satisfied: ${satisfiedCount} of ${ratingValues.length}` : 'Satisfied: 0 of 0' },
      { id: 'helperResRate', text: total ? `Resolved: ${resolved} of ${total}` : 'Resolved ÷ Total feedbacks' },
      { id: 'helperRating', text: total ? 'Based on stored feedback ratings' : 'Ratings will show when data arrives' },
      { id: 'helperResolution', text: total ? `${resolved} of ${total} entries are actioned` : 'Resolution rate appears after submissions arrive' },
      { id: 'tTotal', text: summary.recentDelta || '— Awaiting data' },
      { id: 'tPending', text: pending ? 'Review queue active' : '— No queue' },
      { id: 'statusSat', text: satisfaction === null ? 'Satisfaction' : satisfaction >= benchmark ? 'Good' : satisfaction >= 50 ? 'Fair' : 'Low' },
      { id: 'statusResRate', text: Number(resolutionRate) >= 80 ? 'On Track' : Number(resolutionRate) >= 40 ? 'Moderate' : 'Low' },
      { id: 'tSat', text: satisfaction !== null ? `Benchmark: ${benchmark}% | Current: ${satisfaction}%` : `— Benchmark: ${benchmark}%` },
      { id: 'tResRate', text: `Target: 80% | Current: ${resolutionRate}%` },
      { id: 'tResolved', text: total ? `${resolutionRate}% resolution rate` : '— Awaiting data' },
      { id: 'tRating', text: total ? (Number(averageRating) >= 4 ? '↑ High satisfaction' : Number(averageRating) >= 3 ? 'Moderate' : '↓ Below target') : '— No benchmark yet' },
      { id: 'tResolution', text: total ? `${resolved} actioned` : '— Awaiting data' },
    ]);

    // Keep semantic color classes aligned with status text on dashboard2 cards.
    const satStatusEl = getEl('statusSat');
    if (satStatusEl) {
      satStatusEl.className = `kpi-status ${satisfaction === null ? 'ks-neutral' : satisfaction >= benchmark ? 'ks-ok' : satisfaction >= 50 ? 'ks-warn' : 'ks-alert'}`;
    }

    const resStatusEl = getEl('statusResRate');
    if (resStatusEl) {
      const rateNum = Number(resolutionRate);
      resStatusEl.className = `kpi-status ${rateNum >= 80 ? 'ks-ok' : rateNum >= 40 ? 'ks-warn' : 'ks-alert'}`;
    }
  }

  function renderAlerts(summary) {
    const alerts = summary.alerts || [];
    if (!alerts.length) {
      setHtml('alertStrip', `
        <div class="alert-item al-good">
          <div class="al-icon"><i class="fas fa-check-circle"></i></div>
          <div class="al-body">
            <div class="al-title">System Normal</div>
            <div class="al-desc">No active issues. Feedback levels and service quality are stable.</div>
          </div>
          <div class="al-time">Just now</div>
        </div>
      `);
      return;
    }

    setHtml('alertStrip', alerts.slice(0, 3).map((alert) => `
      <div class="alert-item al-${alert.type || 'info'}">
        <div class="al-icon"><i class="fas ${alert.icon || 'fa-info-circle'}"></i></div>
        <div class="al-body">
          <div class="al-title">${window.AdminDashboardFormatters.escapeHtml(alert.title || 'Alert')}</div>
          <div class="al-desc">${window.AdminDashboardFormatters.escapeHtml(alert.description || '')}</div>
        </div>
        <div class="al-time">${window.AdminDashboardFormatters.escapeHtml(alert.time || 'Now')}</div>
      </div>
    `).join(''));
  }

  function renderInsights(summary) {
    setText('insBestDept', summary.bestService ? `${summary.bestService.label} (${summary.bestService.averageRating.toFixed(1)})` : 'No data yet');
    setText('insBestDeptMeta', summary.bestService ? `${summary.bestService.count} submissions` : 'Waiting for enough service data.');
    setText('insTopService', summary.topService ? `${summary.topService.label} (${summary.topService.count})` : '—');
    setText('insTopServiceMeta', summary.topService ? `${summary.topService.count} submission${summary.topService.count === 1 ? '' : 's'} across feedback entries` : 'Service with the most feedback submissions');
    setText('insLowArea', summary.lowService ? `${summary.lowService.label} (${summary.lowService.averageRating.toFixed(1)})` : 'No low-performing area');
    setText('insLowAreaMeta', summary.lowService ? `${summary.lowService.count} entries with ratings below threshold` : 'Only services with 3+ entries are evaluated.');
    setText('insTrend', summary.trendLabel || 'Stable');
    setText('insTrendMeta', 'Last 30 days vs previous 30 days');
  }

  function renderReportSidebar(summary, entries) {
    const report = window.AdminDashboardFormatters.reportTotals(entries);
    const { total, resolved, satisfied: satisfiedCount, satisfaction, resolutionRate } = report;
    const storedSettings = window.AdminDashboardStore?.getModules?.().settings || {};
    const configuredBenchmark = Number(storedSettings.admin_satisfaction_benchmark?.value);
    const benchmark = Number.isFinite(configuredBenchmark) && configuredBenchmark >= 0 && configuredBenchmark <= 100 ? configuredBenchmark : 80;
    const target = 80;

    setText('reportSatValue', satisfaction !== null ? `${satisfaction}%` : '—%');
    setText('reportSatDetail', `Satisfied: ${satisfiedCount} of ${report.ratingCount} rated feedback`);
    setText('reportSatMeta', satisfaction !== null ? `Benchmark: ${benchmark}% | Current: ${satisfaction}%` : `Benchmark: ${benchmark}% | Current: —`);

    setText('reportResValue', resolutionRate !== null ? `${resolutionRate}%` : '—%');
    setText('reportResDetail', total ? `Resolved: ${resolved} of ${total}` : 'Resolved: 0 of 0');
    setText('reportResMeta', resolutionRate !== null ? `Target: ${target}% | Current: ${resolutionRate}%` : `Target: ${target}% | Current: —`);

    const satStatus = getEl('reportSatStatus');
    if (satStatus) {
      satStatus.textContent = satisfaction === null ? 'No Data' : satisfaction >= benchmark ? 'Good' : 'Low';
      satStatus.className = `report-status ${satisfaction !== null && satisfaction >= benchmark ? 'report-status-good' : 'report-status-low'}`;
    }

    const resStatus = getEl('reportResStatus');
    if (resStatus) {
      resStatus.textContent = resolutionRate === null ? 'No Data' : resolutionRate >= target ? 'On Track' : 'Low';
      resStatus.className = `report-status ${resolutionRate !== null && resolutionRate >= target ? 'report-status-good' : 'report-status-low'}`;
    }

    const formatters = window.AdminDashboardFormatters;
    const esc = formatters.escapeHtml;
    const sqdStats = new Map(Object.keys(SQD_QUESTIONS).map((key) => [key, { total: 0, count: 0 }]));
    let sqdEligibleEntries = 0;
    entries.forEach((entry) => {
      let entryHasRating = false;
      Object.keys(SQD_QUESTIONS).forEach((key) => {
        const rating = ratingValue(entry.ratings?.[key]);
        if (rating === null) return;
        const stat = sqdStats.get(key);
        stat.total += rating;
        stat.count += 1;
        entryHasRating = true;
      });
      if (entryHasRating) sqdEligibleEntries += 1;
    });
    const ratedQuestions = Array.from(sqdStats.entries())
      .filter(([, stat]) => stat.count >= 3)
      .map(([key, stat]) => ({ key, label: SQD_QUESTIONS[key], average: stat.total / stat.count, count: stat.count }));
    const commentEntries = entries.filter((entry) => formatters.isWrittenText(entry.feedback));
    const analyzedEntries = entries.filter((entry) => (
      formatters.isWrittenText(entry.feedback) || formatters.isWrittenText(entry.suggestions)
    ));
    const sentimentCounts = { Positive: 0, Neutral: 0, Negative: 0 };
    analyzedEntries.forEach((entry) => {
      const sentiment = formatters.normalizeSentiment(entry.sentimentLabel);
      if (Object.prototype.hasOwnProperty.call(sentimentCounts, sentiment)) sentimentCounts[sentiment] += 1;
    });

    const digest = (label, values) => {
      const items = values.slice(0, 3).map((value) => `<div>${esc(value)}</div>`).join('');
      return `<div><strong>${esc(label)}</strong><p>${items || 'No written response recorded.'}</p></div>`;
    };
    const commentTexts = commentEntries.map((entry) => formatters.normalizeText(entry.feedback));
    const staffNames = entries.map((entry) => formatters.normalizeText(entry.appreciation)).filter(formatters.isWrittenText);
    const suggestionItems = entries.map((entry) => formatters.normalizeText(entry.suggestions)).filter(formatters.isWrittenText);

    let ratingInsight = 'Insufficient SQD rating data for an insight; at least 3 valid responses per question are required.';
    if (ratedQuestions.length) {
      const highest = ratedQuestions.slice().sort((left, right) => right.average - left.average || left.label.localeCompare(right.label))[0];
      const lowest = ratedQuestions.slice().sort((left, right) => left.average - right.average || left.label.localeCompare(right.label))[0];
      ratingInsight = `Highest-rated: ${highest.label} (${highest.average.toFixed(1)}/5; n=${highest.count}). Lowest-rated: ${lowest.label} (${lowest.average.toFixed(1)}/5; n=${lowest.count}).`;
    }
    const commentInsight = analyzedEntries.length
      ? `${sentimentCounts.Positive} Positive, ${sentimentCounts.Neutral} Neutral, and ${sentimentCounts.Negative} Negative across ${analyzedEntries.length} entries with written Comment or Suggestion.`
      : 'No written Comment or Suggestion was submitted.';
    setHtml(
      'experienceSummaryText',
      `<div><strong>SQD rating overview (separate from comment sentiment)</strong><p>${esc(ratingInsight)}</p></div>
       <div><strong>Sentiment for Comment and Suggestion</strong><p>${esc(commentInsight)}</p></div>
       ${digest('Comment', commentTexts)}
       ${digest('Name of Staff to Thank or Commend', staffNames)}`
    );
    setText(
      'experienceSummaryMeta',
      `SQD ratings: ${sqdEligibleEntries} eligible feedback entries. Comment: ${commentTexts.length} entries. Staff name: ${staffNames.length} entries. Suggestion: ${suggestionItems.length} entries.`
    );

    setHtml(
      'suggestionsSummaryText',
      suggestionItems.length
        ? suggestionItems.slice(0, 3).map((suggestion) => `<div><strong>Suggestion:</strong> ${esc(suggestion)}</div>`).join('')
        : 'No written Suggestion was submitted.'
    );
    setText(
      'suggestionsSummaryMeta',
      suggestionItems.length
        ? `${suggestionItems.length} written Suggestion entr${suggestionItems.length === 1 ? 'y' : 'ies'}; showing up to 3 actual responses.`
        : 'No written Suggestion in this period.'
    );
  }

  function renderSummaryChips(entries) {
    const total = entries.length;
    const formatters = window.AdminDashboardFormatters;
    const patient = entries.filter((entry) => entry.respondentCategory === 'patient').length;
    const companion = entries.filter((entry) => entry.respondentCategory === 'companion').length;
    const business = entries.filter((entry) => entry.respondentCategory === 'business').length;
    const other = entries.filter((entry) => entry.respondentCategory === 'other').length;
    const unspecified = total - patient - companion - business - other;
    const unspecifiedChip = unspecified > 0 ? ` <span>•</span> <strong>Unspecified:</strong> ${unspecified}` : '';
    setHtml(
      'allFbSummary',
      `<div class="summary-inline"><strong>Total:</strong> ${total} <span>•</span> <strong>${formatters.respondentCategoryLabel('patient')}:</strong> ${patient} <span>•</span> <strong>Companion:</strong> ${companion} <span>•</span> <strong>${formatters.respondentCategoryLabel('business')}:</strong> ${business} <span>•</span> <strong>Other:</strong> ${other}${unspecifiedChip}</div>`
    );
  }

  // Shared by every respondent-category page (Patient / Client, Companion,
  // Business, Other) — replaces the old renderPatientSummary/
  // renderVisitorSummary pair now that there are 4 categories instead of 2.
  function renderSentimentSummary(entries) {
    const total = entries.length;
    const labels = [
      ['Positive', '🟢'],
      ['Neutral', '⚪'],
      ['Negative', '🔴'],
    ];
    const counts = labels.map(([label, icon]) => {
      const count = entries.filter((entry) => entry.sentimentLabel === label).length;
      return `<span>${icon} ${label}: ${count}</span>`;
    });
    return `<div class="summary-inline"><strong>Total:</strong> ${total} <span>•</span> ${counts.join(' <span>•</span> ')}</div>`;
  }

  function renderRespondentSummary(entries, targetId) {
    setHtml(targetId, renderSentimentSummary(entries));
  }

  function renderPatientSummary(entries) {
    setHtml('patientFbSummary', renderSentimentSummary(entries));
  }

  function renderVisitorSummary(entries) {
    setHtml('visitorFbSummary', renderSentimentSummary(entries));
  }

  function renderFeedbackTable(targetId, entries, mode, emptyMessage) {
    const rows = entries.length ? entries.map((entry) => window.AdminDashboardFormatters.feedbackTableRow(entry, mode)).join('') : `<tr><td colspan="6" style="text-align:center;padding:20px;color:var(--ink-3)">${window.AdminDashboardFormatters.escapeHtml(emptyMessage)}</td></tr>`;
    setHtml(targetId, rows);
  }

  function renderFeedbackCards(targetId, entries, emptyHtml) {
    const html = entries.length ? entries.map((entry) => window.AdminDashboardFormatters.feedbackCard(entry)).join('') : emptyHtml;
    setHtml(targetId, html);
  }

  function renderRecentFeedback(entries) {
    const output = entries
      .slice()
      .sort((left, right) => new Date(right.timestamp || 0) - new Date(left.timestamp || 0))
      .slice(0, 10);
    setHtml('recentFbBody', output.length ? output.map((entry) => window.AdminDashboardFormatters.feedbackTableRow(entry, 'recent')).join('') : '<tr><td colspan="7" style="text-align:center;padding:20px;color:var(--ink-3)">No feedback yet.</td></tr>');
    renderSummaryChips(entries);
  }

  // Staff Ratings page (formerly "Staff Performance") — a formal table of
  // the 10 fixed staff-role rating questions (st0-st9), not individual
  // named employees. Classification thresholds match the filter chips:
  // High >=4.0, Moderate 3.0-3.9, Needs Attention <4.0 (roles with no
  // numeric responses yet are excluded from all three, shown only in "All").
  function classifyRoleLevel(average) {
    if (average === null || average === undefined) return null;
    if (average >= 4) return 'high';
    if (average >= 3) return 'mid';
    return 'low';
  }

  function ratingScoreClass(average) {
    if (average === null || average === undefined) return 'na';
    if (average >= 4) return 'hi';
    if (average >= 3) return 'mid';
    return 'lo';
  }

  function renderStaffRatingsTable(targetId, roleStats, filterLevel, searchTerm = '') {
    const esc = window.AdminDashboardFormatters.escapeHtml;
    let rows = roleStats;
    if (filterLevel && filterLevel !== 'all') {
      rows = rows.filter((role) => classifyRoleLevel(role.average) === filterLevel);
    }
    const normalizedSearch = String(searchTerm).trim().toLowerCase();
    if (normalizedSearch) {
      // Staff Ratings is role-based: search only the canonical role label,
      // never a feedback respondent or a staff-management record.
      rows = rows.filter((role) => role.label.toLowerCase().includes(normalizedSearch));
    }

    if (!rows.length) {
      setHtml(targetId, '<tr><td colspan="10" style="text-align:center;padding:20px;color:var(--ink-3)">No staff roles match the selected filter or search.</td></tr>');
      return;
    }

    const html = rows.map((role) => {
      const scoreClass = ratingScoreClass(role.average);
      const avgDisplay = role.average === null ? '—' : `${role.average.toFixed(1)} / 5.0`;
      const inactiveTag = role.isActive === false
        ? ' <span class="pill pill-red" style="font-size:10px;padding:2px 8px">Inactive</span>'
        : '';
      return `
        <tr>
          <td>${esc(role.label)}${inactiveTag}</td>
          <td><span class="score ${scoreClass}">${avgDisplay}</span></td>
          <td style="text-align:center">${role.counts[5]}</td>
          <td style="text-align:center">${role.counts[4]}</td>
          <td style="text-align:center">${role.counts[3]}</td>
          <td style="text-align:center">${role.counts[2]}</td>
          <td style="text-align:center">${role.counts[1]}</td>
          <td style="text-align:center">${role.counts.NA}</td>
          <td style="text-align:center">${role.responses}</td>
          <td class="staff-action-cell">
            <div class="actions-dropdown">
              <button type="button" class="actions-btn" onclick="toggleActions(this)" title="Staff role actions"><i class="fas fa-ellipsis-v"></i></button>
              <div class="dropdown-menu">
                <button class="dropdown-item" onclick="openEditStaffRoleModal(${role.id})"><i class="fas fa-edit"></i> Edit</button>
                <button class="dropdown-item text-red" onclick="openDeleteStaffRoleModal(${role.id})"><i class="fas fa-trash"></i> Delete</button>
              </div>
            </div>
          </td>
        </tr>`;
    }).join('');
    setHtml(targetId, html);
  }

  function renderStaffOverallSummary(overall) {
    const statusEl = getEl('staffOverallStatus');
    const valueEl = getEl('staffOverallValue');
    const metaEl = getEl('staffOverallMeta');
    if (!overall || overall.average === null) {
      if (statusEl) { statusEl.textContent = 'No Data'; statusEl.className = 'report-status'; }
      if (valueEl) valueEl.textContent = 'No ratings available';
      if (metaEl) metaEl.textContent = 'Staff-role ratings will appear once feedback is submitted.';
      return;
    }
    if (statusEl) {
      const level = classifyRoleLevel(overall.average);
      statusEl.textContent = level === 'high' ? 'Good' : level === 'mid' ? 'Moderate' : 'Needs Attention';
      statusEl.className = 'report-status ' + (level === 'high' ? 'report-status-good' : level === 'mid' ? '' : 'report-status-low');
    }
    if (valueEl) valueEl.textContent = `${overall.average.toFixed(1)} / 5.0`;
    if (metaEl) metaEl.textContent = `${overall.responses} response${overall.responses === 1 ? '' : 's'}`;
  }

  function renderPendingResolved(entries) {
    const pending = entries.filter((entry) => entry.status === 'pending');
    const resolved = entries.filter((entry) => entry.status === 'resolved');
    renderFeedbackCards('pendingList', pending, '<div class="empty-state"><i class="far fa-clock"></i><p>No pending feedbacks.</p></div>');
    renderFeedbackCards('resolvedList', resolved, '<div class="empty-state"><i class="fas fa-check-circle"></i><p>No resolved feedbacks yet.</p></div>');
  }

  function renderModules(snapshot) {
    const modules = snapshot.modules || {};
    const entries = snapshot.entries || [];
    const services = Array.isArray(modules.services) ? modules.services : Object.values(modules.services || {});

    const serviceFilter = getEl('fbServiceFilter');
    if (serviceFilter) {
      const selected = serviceFilter.value;
      serviceFilter.innerHTML = '<option value="">All Services</option>' + services
        .map((service) => `<option value="${service.id}">${window.AdminDashboardFormatters.escapeHtml(service.name || '')}</option>`)
        .join('');
      if (services.some((service) => String(service.id) === selected)) serviceFilter.value = selected;
    }

    const analyticsServiceFilter = getEl('analyticsServiceFilter');
    if (analyticsServiceFilter) {
      const selected = String(window.AdminDashboardStore?.getSnapshot?.().filters?.service || analyticsServiceFilter.value || '');
      analyticsServiceFilter.innerHTML = '<option value="">All Services</option>' + services
        .map((service) => `<option value="${service.id}">${window.AdminDashboardFormatters.escapeHtml(service.name || '')}</option>`)
        .join('');
      if (services.some((service) => String(service.id) === selected)) analyticsServiceFilter.value = selected;
    }

    const serviceRatingFilter = window.AdminDashboardServiceFilter || 'all';
    const serviceStatusFilter = getEl('serviceStatusFilter')?.value || 'active';
    const serviceRowsData = services.filter((item) => {
      if (serviceStatusFilter !== 'all' && item.status !== serviceStatusFilter) return false;
      const average = Number(item.averageRating ?? 0);
      if (serviceRatingFilter === 'high') return average >= 4;
      if (serviceRatingFilter === 'mid') return average >= 3 && average < 4;
      if (serviceRatingFilter === 'low') return average > 0 && average < 3;
      return true;
    });
    const serviceRows = serviceRowsData.length ? serviceRowsData.map((item, index) => {
      const avg = Number(item.averageRating ?? 0);
      const scoreClass = avg >= 4 ? 'hi' : avg >= 3 ? 'mid' : 'lo';
      const subservices = Array.isArray(item.subservices) ? item.subservices : [];
      const subtypeHtml = subservices.length
        ? `<div style="font-size:11px;color:var(--ink-3);margin-top:4px">${subservices.length} subservices</div>`
        : '';
      const inactiveTag = item.status === 'inactive'
        ? ' <span class="pill pill-red" style="font-size:10px;padding:2px 8px">Inactive</span>'
        : '';
      const lifecycleAction = item.status === 'inactive'
        ? `<button class="dropdown-item" onclick="reactivateService(${item.id})"><i class="fas fa-undo"></i> Reactivate</button>`
        : `<button class="dropdown-item text-red" onclick="deleteService(${item.id})"><i class="fas fa-trash"></i> Deactivate</button>`;
      const permanentDeleteAction = `<button class="dropdown-item text-red" onclick="permanentlyDeleteService(${item.id})"><i class="fas fa-trash-alt"></i> Delete</button>`;
      return `
        <tr class="service-management-row">
          <td data-label="Service"><strong class="service-name">${window.AdminDashboardFormatters.escapeHtml(item.name || '')}</strong>${inactiveTag}${subtypeHtml}</td>
          <td data-label="Feedback" class="service-metric-cell"><span class="pill pill-blue service-feedback-count">${item.totalFeedback ?? 0}</span></td>
          <td style="text-align:center"><span class="score ${scoreClass}">${avg ? avg.toFixed(1) : '—'}</span></td>
          <td data-label="Actions" class="service-action-cell"><div class="actions-dropdown"><button class="actions-btn" onclick="toggleActions(this)" aria-label="Actions for ${window.AdminDashboardFormatters.escapeHtml(item.name || 'service')}" aria-haspopup="true"><i class="fas fa-ellipsis-v"></i></button><div class="dropdown-menu"><button class="dropdown-item" onclick="openServiceModal(${item.id})"><i class="fas fa-edit"></i> Edit</button><button class="dropdown-item" onclick="manageSubservices(${item.id})"><i class="fas fa-list"></i> Manage Subservices</button>${lifecycleAction}${permanentDeleteAction}</div></div></td>
        </tr>`;
    }).join('') : '<tr class="service-empty-row"><td colspan="4">No services match the selected filters.</td></tr>';

    // #staffBody belongs to the Staff Ratings page, rendered exclusively by
    // renderStaffTable() (js/admin/bootstrap.js) from real
    // feedback-derived stats — this function must not also write to it.
    setHtml('servicesTableBody', serviceRows);

    const feedbackItems = entries
      .filter((entry) => [entry.feedback, entry.appreciation, entry.suggestions].some(window.AdminDashboardFormatters.isWrittenText))
      .sort((left, right) => new Date(right.timestamp || right.createdAt || 0) - new Date(left.timestamp || left.createdAt || 0))
      .slice(0, 10);

    const searchTerm = (document.getElementById('notifSearch')?.value || '').toLowerCase();
    const filteredItems = searchTerm
      ? feedbackItems.filter((entry) => {
          const text = `${window.AdminDashboardFormatters.respondentCategoryLabel(entry.respondentCategory)} ${entry.service || ''} ${entry.name || ''} ${entry.feedback || ''} ${entry.appreciation || ''} ${entry.suggestions || ''}`.toLowerCase();
          return text.includes(searchTerm);
        })
      : feedbackItems;

    const fieldMarkup = (label, value) => window.AdminDashboardFormatters.isWrittenText(value)
      ? `<div class="n-desc"><strong>${window.AdminDashboardFormatters.escapeHtml(label)}:</strong> ${window.AdminDashboardFormatters.escapeHtml(value)}</div>`
      : '';
    setHtml('dashboardNotifList', filteredItems.length ? filteredItems.map((entry) => {
      const author = entry.name || 'Guest';
      const title = `${window.AdminDashboardFormatters.respondentCategoryLabel(entry.respondentCategory)} Feedback · ${author}`;
      const dateValue = entry.timestamp || entry.createdAt || '';
      const isNegative = entry.sentimentLabel === 'Negative';
      return `
        <div class="notif-item${isNegative ? ' is-negative' : ''}">
          <div class="notif-ic ${isNegative ? 'ic-red' : 'ic-blue'}"><i class="fas fa-comment-alt"></i></div>
          <div class="n-body">
            <div class="n-title">${window.AdminDashboardFormatters.escapeHtml(title)}</div>
            <div class="n-desc">${window.AdminDashboardFormatters.escapeHtml(entry.service || 'No service selected')}</div>
            ${fieldMarkup('Comment', entry.feedback)}
            ${fieldMarkup('Name of Staff to Thank or Commend', entry.appreciation)}
            ${fieldMarkup('Suggestion', entry.suggestions)}
            <div class="n-time">${window.AdminDashboardFormatters.escapeHtml(window.AdminDashboardFormatters.formatDate(dateValue))}</div>
          </div>
        </div>
      `;
    }).join('') : '<div class="empty-state"><i class="fas fa-comment-dots"></i><p>No recent experience entries found.</p></div>');

    const audit = Array.isArray(modules.audit) ? modules.audit : Object.values(modules.audit || {});
    setHtml('auditBody', audit.length ? audit.map((item) => `
      <tr>
        <td style="white-space:nowrap">${window.AdminDashboardFormatters.escapeHtml(window.AdminDashboardFormatters.formatDate(item.timestamp))}</td>
        <td>${window.AdminDashboardFormatters.escapeHtml(item.adminName || 'Admin')}</td>
        <td><span class="ab ab-l">${window.AdminDashboardFormatters.escapeHtml(item.action || '')}</span></td>
        <td style="font-size:11px;color:var(--ink-3)">${window.AdminDashboardFormatters.escapeHtml(item.referenceId || '')}</td>
        <td>${window.AdminDashboardFormatters.escapeHtml(item.detail || '')}</td>
      </tr>
    `).join('') : '<tr><td colspan="5" style="text-align:center;padding:20px;color:var(--ink-3)">No audit entries yet.</td></tr>');

    const comments = entries.filter((entry) => entry.feedback).slice(0, 3);
    setHtml('commentList', comments.length ? comments.map((entry) => `
      <div class="comment-item">
        <div class="c-who">${window.AdminDashboardFormatters.escapeHtml(entry.name || 'Guest')} <span class="c-dept">· ${window.AdminDashboardFormatters.escapeHtml(entry.service || 'Unassigned')}</span></div>
        <div class="c-text">${window.AdminDashboardFormatters.escapeHtml(entry.feedback)}</div>
      </div>
    `).join('') : '<div class="empty-state"><i class="far fa-comment-dots"></i><p>No comments yet.</p></div>');

    const queue = entries.filter((entry) => entry.status === 'pending').slice(0, 6);
    setHtml('queueList', queue.length ? queue.map((entry) => `
      <div class="queue-item">
        <div class="qi-info">
          <strong>${window.AdminDashboardFormatters.escapeHtml(entry.service || 'No service selected')}</strong>
          <p>${window.AdminDashboardFormatters.escapeHtml(entry.subject || entry.feedback || 'Feedback pending review.')}</p>
        </div>
        <div class="qi-actions"><button class="q-btn resolve-btn" onclick="updateStatus('${window.AdminDashboardFormatters.escapeHtml(entry.referenceId || '')}','resolved')">Resolve</button></div>
      </div>
    `).join('') : '<div class="empty-state"><i class="fas fa-check-circle"></i><p>Queue empty — all clear.</p></div>');
  }

  function renderDashboard(snapshot) {
    const summary = snapshot.summary || {};
    const entries = snapshot.entries || [];
    renderKpis(summary, entries);
    renderAlerts(summary);
    renderInsights(summary);
    renderReportSidebar(summary, entries);
    renderSummaryChips(entries);
    renderRecentFeedback(entries, { recent: snapshot.recentFilter || 'all' });
    renderPendingResolved(entries);
    renderModules(snapshot);
  }

  window.AdminDashboardRenderers = {
    renderKpis,
    renderAlerts,
    renderInsights,
    renderSummaryChips,
    renderPatientSummary,
    renderVisitorSummary,
    renderRespondentSummary,
    renderStaffRatingsTable,
    renderStaffOverallSummary,
    renderFeedbackTable,
    renderFeedbackCards,
    renderRecentFeedback,
    renderPendingResolved,
    renderModules,
    renderDashboard,
  };
})();
