(function () {
  const store = window.AdminDashboardStore;
  const formatters = window.AdminDashboardFormatters;
  const overlay = document.getElementById('adminPrintPeriodOverlay');
  const periodSelect = document.getElementById('adminPrintPeriod');
  const specificDate = document.getElementById('adminPrintDate');
  const specificDateFields = document.getElementById('adminPrintSpecificDate');
  const startDate = document.getElementById('adminPrintStartDate');
  const endDate = document.getElementById('adminPrintEndDate');
  const dateRangeFields = document.getElementById('adminPrintDateRange');
  const errorMessage = document.getElementById('adminPrintPeriodError');
  const reportRoot = document.getElementById('adminPrintableReport');

  if (!store || !formatters || !overlay || !periodSelect || !specificDate || !specificDateFields
    || !startDate || !endDate || !dateRangeFields || !errorMessage || !reportRoot) return;

  const SENTIMENTS = ['Positive', 'Neutral', 'Negative'];
  const SENTIMENT_COLORS = ['#16845b', '#667085', '#c93636'];
  let activeCharts = [];
  let returnFocus = null;

  function pad(value) {
    return String(value).padStart(2, '0');
  }

  function localDateValue(date) {
    return `${date.getFullYear()}-${pad(date.getMonth() + 1)}-${pad(date.getDate())}`;
  }

  function parseDate(value) {
    if (!/^\d{4}-\d{2}-\d{2}$/.test(value)) return null;
    const [year, month, day] = value.split('-').map(Number);
    const date = new Date(year, month - 1, day);
    return date.getFullYear() === year && date.getMonth() === month - 1 && date.getDate() === day
      ? date
      : null;
  }

  function rollingDates(period) {
    const today = new Date();
    today.setHours(0, 0, 0, 0);
    const dateTo = localDateValue(today);
    if (period === 'all') return { dateFrom: '', dateTo };
    if (period === 'today') return { dateFrom: dateTo, dateTo };
    if (period === 'specific') {
      const selected = specificDate.value;
      if (!parseDate(selected)) throw new Error('Choose a valid specific date.');
      return { dateFrom: selected, dateTo: selected };
    }
    if (period === 'custom') {
      const from = startDate.value;
      const to = endDate.value;
      if (!parseDate(from) || !parseDate(to)) throw new Error('Choose valid start and end dates.');
      if (from > to) throw new Error('End Date cannot be earlier than Start Date.');
      return { dateFrom: from, dateTo: to };
    }
    if (period !== '7' && period !== '30') throw new Error('Choose a valid report period.');

    const dateFrom = new Date(today);
    dateFrom.setDate(dateFrom.getDate() - (Number(period) - 1));
    return { dateFrom: localDateValue(dateFrom), dateTo };
  }

  function dateLabel(value, includeYear = true) {
    const date = parseDate(value);
    if (!date) return '';
    return date.toLocaleDateString('en-US', {
      month: 'short',
      day: 'numeric',
      ...(includeYear ? { year: 'numeric' } : {}),
    });
  }

  function resolvePeriodLabel(entries, period, dateFrom, dateTo) {
    const validDates = entries
      .map((entry) => {
        const raw = String(entry.timestamp || entry.date || '').slice(0, 10);
        return parseDate(raw) ? raw : '';
      })
      .filter(Boolean)
      .sort();
    if (period === 'all') {
      const earliest = validDates[0];
      if (!earliest) return `All time through ${dateLabel(dateTo)}`;
      const earliestDate = parseDate(earliest);
      const today = parseDate(dateTo);
      const firstLabel = dateLabel(earliest, earliestDate.getFullYear() !== today.getFullYear());
      return `${firstLabel} – ${dateLabel(dateTo)} (All time)`;
    }

    const start = parseDate(dateFrom);
    const end = parseDate(dateTo);
    const firstLabel = dateLabel(dateFrom, start.getFullYear() !== end.getFullYear());
    if (period === 'today') return `${dateLabel(dateTo)} (Today)`;
    if (period === 'specific') return `${dateLabel(dateFrom)} (Specific date)`;
    if (period === 'custom') return `${firstLabel} – ${dateLabel(dateTo)} (Custom date range)`;
    return `${firstLabel} – ${dateLabel(dateTo)} (Last ${period} days)`;
  }

  function escape(value) {
    return formatters.escapeHtml(String(value ?? ''));
  }

  function percent(value, denominator) {
    return denominator ? `${(value * 100 / denominator).toFixed(1)}%` : '—';
  }

  function formatNumber(value, digits = 1) {
    return Number.isFinite(value) ? value.toFixed(digits) : '—';
  }

  function renderStatusTable(entries) {
    const statuses = [
      ['Pending', 'pending'],
      ['In review', 'in_review'],
      ['Resolved', 'resolved'],
      ['Archived', 'archived'],
    ];
    return statuses.map(([label, key]) => {
      const count = entries.filter((entry) => entry.status === key).length;
      return `<tr><th scope="row">${label}</th><td>${count}</td><td>${percent(count, entries.length)}</td></tr>`;
    }).join('');
  }

  function renderKpis(entries) {
    const totals = formatters.reportTotals(entries);
    const average = entries.reduce((sum, entry) => {
      const rating = formatters.reportRating(entry);
      return sum + (rating === null ? 0 : rating);
    }, 0);
    const averageRating = totals.ratingCount ? average / totals.ratingCount : null;
    const inReview = entries.filter((entry) => entry.status === 'in_review').length;
    const archived = entries.filter((entry) => entry.status === 'archived').length;
    const cards = [
      ['Total feedback', totals.total, `All feedback in period · N=${totals.total}`],
      ['Pending', entries.filter((entry) => entry.status === 'pending').length, `Of ${totals.total} feedback records`],
      ['In review', inReview, `Of ${totals.total} feedback records`],
      ['Resolved', totals.resolved, `Of ${totals.total} feedback records`],
      ['Resolution rate', totals.resolutionRate === null ? '—' : `${totals.resolutionRate.toFixed(1)}%`, `${totals.resolved} resolved / ${totals.total} feedback`],
      ['Overall satisfaction', totals.satisfaction === null ? '—' : `${totals.satisfaction.toFixed(1)}%`, `${totals.satisfied} satisfied / ${totals.ratingCount} rated`],
      ['Average rating', averageRating === null ? '—' : `${formatNumber(averageRating)} / 5`, `${totals.ratingCount} valid ratings · N/A excluded`],
      ['Archived', archived, `Of ${totals.total} feedback records`],
    ];
    return cards.map(([label, value, denominator]) => `
      <article class="print-kpi">
        <h3>${label}</h3>
        <strong>${value}</strong>
        <p>${denominator}</p>
      </article>
    `).join('');
  }

  function renderRatingTable(entries) {
    const counts = formatters.ratingDistribution(entries);
    const totals = formatters.reportTotals(entries);
    const rows = counts.map((count, index) => `
      <tr><th scope="row">${index + 1} star${index === 0 ? '' : 's'}</th><td>${count}</td><td>${percent(count, totals.ratingCount)}</td></tr>
    `).join('');
    return {
      counts,
      html: totals.ratingCount
        ? `<table class="print-table"><thead><tr><th>Rating</th><th>Count</th><th>% of rated feedback (N=${totals.ratingCount})</th></tr></thead><tbody>${rows}</tbody></table>`
        : '<p class="print-empty">No rated feedback recorded for this period.</p>',
    };
  }

  function renderSentimentTable(entries) {
    const totals = formatters.reportTotals(entries);
    if (!totals.sentimentAnalyzedCount) {
      return {
        totals,
        html: '<p class="print-empty">No analyzed sentiment data recorded for this period.</p>',
      };
    }
    const rows = SENTIMENTS.map((label) => {
      const count = totals.sentimentCounts[label] || 0;
      return `<tr><th scope="row">${label}</th><td>${count}</td><td>${percent(count, totals.sentimentAnalyzedCount)}</td></tr>`;
    }).join('');
    return {
      totals,
      html: `<table class="print-table"><thead><tr><th>Sentiment</th><th>Count</th><th>% of feedback (N=${totals.sentimentAnalyzedCount})</th></tr></thead><tbody>${rows}</tbody></table>`,
    };
  }

  function renderRatingTrendTable(entries, granularity) {
    const points = formatters.ratingTrend(entries, granularity);
    if (!points.length) return { points, html: '<p class="print-empty">No rated feedback with a valid submission date recorded for this period.</p>' };
    const rows = points.map((point) => `
      <tr><th scope="row">${escape(point.label)}</th><td>${point.count}</td><td>${formatNumber(point.average, 2)} / 5</td></tr>
    `).join('');
    return {
      points,
      html: `<table class="print-table"><thead><tr><th>Period</th><th>Rated feedback (N)</th><th>Average rating</th></tr></thead><tbody>${rows}</tbody></table>`,
    };
  }

  function renderSentimentTrendTable(entries, granularity) {
    const periods = formatters.reportSentimentTrend(entries, granularity);
    if (!periods.length) return '<p class="print-empty">No data recorded for this period.</p>';
    const headings = SENTIMENTS.map((label) => `<th>${label} count / %</th>`).join('');
    const rows = periods.map((period) => {
      const values = SENTIMENTS.map((label) => {
        const count = period.counts[label] || 0;
        return `<td>${count} / ${percent(count, period.total)}</td>`;
      }).join('');
      return `<tr><th scope="row">${escape(period.label)}</th><td>${period.total}</td>${values}</tr>`;
    }).join('');
    return `<table class="print-table sentiment-trend-table"><thead><tr><th>Submission period</th><th>Total (N)</th>${headings}</tr></thead><tbody>${rows}</tbody></table>`;
  }

  function renderServiceTable(entries) {
    const rows = formatters.serviceComparison(entries);
    if (!rows.length) return '<p class="print-empty">No service feedback recorded for this period.</p>';
    return `<table class="print-table"><thead><tr><th>Service</th><th>Feedback (N)</th><th>Rated (N)</th><th>Average rating</th><th>Overall satisfaction</th></tr></thead><tbody>${rows.map((row) => `
      <tr>
        <th scope="row">${escape(row.label)}</th>
        <td>${row.count}</td>
        <td>${row.ratingCount}</td>
        <td>${row.avg === null ? '—' : `${formatNumber(row.avg, 2)} / 5`}</td>
        <td>${row.satisfaction === null ? '—' : `${formatNumber(row.satisfaction, 1)}% (${row.ratingCount} rated)`}</td>
      </tr>`).join('')}</tbody></table>`;
  }

  function filterDescription(filters, modules, periodLabel) {
    const parts = [`Period: ${periodLabel}`];
    if (filters.service) {
      const service = (modules.services || []).find((item) => String(item.id) === String(filters.service));
      parts.push(`Service: ${service ? service.name : filters.service}`);
    } else {
      parts.push('Service: All services');
    }
    if (filters.status) parts.push(`Status: ${filters.status}`);
    if (filters.feedbackType) parts.push(`Feedback type: ${filters.feedbackType}`);
    if (filters.respondent) parts.push(`Respondent: ${filters.respondent}`);
    if (filters.sentiment) parts.push(`Sentiment: ${filters.sentiment}`);
    if (filters.priority) parts.push(`Priority: ${filters.priority}`);
    if (filters.rating) parts.push(`Rating: ${filters.rating}`);
    if (filters.statusGroup) parts.push(`Status group: ${filters.statusGroup}`);
    if (filters.search) parts.push(`Search: ${filters.search}`);
    if (filters.minRating) parts.push(`Minimum rating: ${filters.minRating}`);
    if (filters.maxRating) parts.push(`Maximum rating: ${filters.maxRating}`);
    return parts.join(' · ');
  }

  function executiveSummary(entries, totals, averageRating) {
    if (!entries.length) return 'No data recorded for this period. No satisfaction, resolution, or rating estimate is available.';
    const parts = [`${totals.total} feedback record${totals.total === 1 ? '' : 's'} recorded`];
    parts.push(`${totals.resolved} resolved (${totals.resolutionRate.toFixed(1)}% of ${totals.total})`);
    parts.push(totals.satisfaction === null
      ? 'overall satisfaction unavailable because no valid 1–5 ratings were recorded'
      : `overall satisfaction ${totals.satisfaction.toFixed(1)}% (${totals.satisfied} of ${totals.ratingCount} rated feedback)`);
    parts.push(averageRating === null ? 'average rating unavailable' : `average rating ${formatNumber(averageRating, 2)} of 5 across ${totals.ratingCount} valid ratings`);
    return `${parts.join('; ')}.`;
  }

  function buildReport(entries, snapshot, period, dates) {
    const totals = formatters.reportTotals(entries);
    const ratingTotal = entries.reduce((sum, entry) => {
      const rating = formatters.reportRating(entry);
      return sum + (rating === null ? 0 : rating);
    }, 0);
    const averageRating = totals.ratingCount ? ratingTotal / totals.ratingCount : null;
    const periodLabel = resolvePeriodLabel(entries, period, dates.dateFrom, dates.dateTo);
    const generated = new Intl.DateTimeFormat('en-PH', {
      dateStyle: 'medium',
      timeStyle: 'short',
      timeZone: 'Asia/Manila',
    }).format(new Date());
    const ratingTrend = renderRatingTrendTable(entries, document.getElementById('ratingTrendSel')?.value || 'monthly');
    const sentimentData = renderSentimentTable(entries);
    const ratingData = renderRatingTable(entries);
    const filters = filterDescription(snapshot.filters || {}, snapshot.modules || {}, periodLabel);

    reportRoot.innerHTML = `
      <div class="print-running-header">
        <strong>Calape Primary Care Facility</strong>
        <span>SFMS Healthcare Feedback Analytics Report</span>
        <span>${escape(periodLabel)}</span>
        <span>Generated ${escape(generated)}</span>
        <span class="print-confidential">Confidential – for official use</span>
      </div>
      <div class="print-running-footer">Page <span class="print-page-number"></span></div>
      <section class="print-sheet print-page-one">
        <header class="print-title">
          <p>Calape Primary Care Facility · SFMS</p>
          <h1>Healthcare Feedback Analytics Report</h1>
          <p class="print-period">${escape(periodLabel)}</p>
        </header>
        <section class="print-details">
          <h2>Report details</h2>
          <dl>
            <dt>Facility</dt><dd>Calape Primary Care Facility</dd>
            <dt>Period</dt><dd>${escape(periodLabel)}</dd>
            <dt>Filters</dt><dd>${escape(filters)}</dd>
            <dt>Generated</dt><dd>${escape(generated)} (Philippine time)</dd>
          </dl>
        </section>
        <section class="print-summary">
          <h2>Executive summary</h2>
          <p>${escape(executiveSummary(entries, totals, averageRating))}</p>
        </section>
        <section class="print-kpis">
          <h2>Key performance indicators</h2>
          <div class="print-kpi-grid">${renderKpis(entries)}</div>
        </section>
        <section class="print-status-section">
          <h2>Case status</h2>
          <table class="print-table">
            <thead><tr><th>Status</th><th>Count</th><th>% of all feedback (N=${totals.total})</th></tr></thead>
            <tbody>${renderStatusTable(entries)}</tbody>
          </table>
        </section>
      </section>
      <section class="print-sheet print-page-two">
        <h1 class="print-section-title">Rating and sentiment analysis</h1>
        <section class="print-chart-block">
          <h2>Rating distribution</h2>
          <div class="print-chart-wrap ${totals.ratingCount ? '' : 'is-empty'}">
            ${totals.ratingCount ? '<canvas id="printRatingDistribution" aria-label="Rating distribution, number of feedback entries by rounded star rating"></canvas>' : '<p class="print-empty">No rated feedback recorded for this period.</p>'}
          </div>
          ${ratingData.html}
        </section>
        <section class="print-chart-block print-rating-trend">
          <h2>Average rating over time</h2>
          ${ratingTrend.points.length >= 3 ? '<div class="print-chart-wrap"><canvas id="printRatingTrend" aria-label="Average rating over time"></canvas></div>' : '<p class="print-empty">Fewer than 3 dated rating periods; table shown instead of a trend chart.</p>'}
          ${ratingTrend.html}
        </section>
        <section class="print-chart-block print-sentiment-dist">
          <h2>Sentiment distribution</h2>
          <div class="print-chart-wrap ${totals.total ? '' : 'is-empty'}">
            ${totals.sentimentAnalyzedCount ? '<canvas id="printSentimentDistribution" aria-label="Distribution of analyzed Positive, Neutral, and Negative sentiment"></canvas>' : '<p class="print-empty">No analyzed sentiment data recorded for this period.</p>'}
          </div>
          ${sentimentData.html}
        </section>
      </section>
      <section class="print-sheet print-page-three">
        <h1 class="print-section-title">Service analysis and methodology</h1>
        <section class="print-table-section">
          <h2>Sentiment trend by submission period</h2>
          ${renderSentimentTrendTable(entries, document.getElementById('sentTrendSel')?.value || 'monthly')}
        </section>
        <section class="print-table-section">
          <h2>Service comparison</h2>
          ${renderServiceTable(entries)}
        </section>
        <section class="print-methodology">
          <h2>Methodology and definitions</h2>
          <ul>
            <li>Satisfaction = feedback with average rating ≥ 4.0 ÷ feedback with a valid 1–5 rating × 100 (N/A excluded).</li>
            <li>Resolution rate = resolved ÷ all feedback in the period × 100.</li>
            <li>Rating distribution uses each entry’s average rounded to the nearest star.</li>
            <li>Dates are by submission time.</li>
            <li>Sentiment uses the feedback Comment and Suggestion fields and is reported only as Positive, Neutral, or Negative.</li>
          </ul>
        </section>
        <section class="print-signoff">
          <h2>Review and approval</h2>
          <div><span>Prepared by</span><i></i><span>Date</span><i></i></div>
          <div><span>Reviewed by</span><i></i><span>Date</span><i></i></div>
          <div><span>Approved by</span><i></i><span>Date</span><i></i></div>
        </section>
      </section>`;

    reportRoot.hidden = false;
    reportRoot.setAttribute('aria-hidden', 'false');
    drawCharts(entries, ratingTrend.points, sentimentData.totals);
  }

  function drawCharts(entries, ratingPoints, totals) {
    activeCharts.forEach((chart) => chart.destroy());
    activeCharts = [];
    const hasChartCanvas = Boolean(
      document.getElementById('printRatingDistribution')
      || document.getElementById('printRatingTrend')
      || document.getElementById('printSentimentDistribution')
    );
    if (hasChartCanvas && typeof Chart === 'undefined') {
      throw new Error('Chart.js is unavailable; the report could not be rendered safely.');
    }

    const ratingCounts = formatters.ratingDistribution(entries);
    const ratingCanvas = document.getElementById('printRatingDistribution');
    if (ratingCanvas) {
      activeCharts.push(new Chart(ratingCanvas, {
        type: 'bar',
        data: {
          labels: ['1 star', '2 stars', '3 stars', '4 stars', '5 stars'],
          datasets: [{ label: 'Feedback entries', data: ratingCounts, backgroundColor: ['#b83c34', '#c76a25', '#c48a1a', '#749330', '#18835c'] }],
        },
        options: {
          responsive: false,
          animation: false,
          plugins: {
            title: { display: true, text: 'Rating distribution' },
            legend: { display: true, position: 'bottom' },
          },
          scales: {
            x: { title: { display: true, text: 'Average rating rounded to nearest star' } },
            y: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Feedback entries (count)' } },
          },
        },
      }));
    }

    const trendCanvas = document.getElementById('printRatingTrend');
    if (trendCanvas && ratingPoints.length >= 3) {
      activeCharts.push(new Chart(trendCanvas, {
        type: 'line',
        data: {
          labels: ratingPoints.map((point) => point.label),
          datasets: [{
            label: 'Average rating (/ 5)',
            data: ratingPoints.map((point) => point.average),
            borderColor: '#147655',
            backgroundColor: 'rgba(20,118,85,.12)',
            pointRadius: 3,
            tension: 0.2,
          }],
        },
        options: {
          responsive: false,
          animation: false,
          plugins: {
            title: { display: true, text: 'Average rating by submission period' },
            legend: { display: true, position: 'bottom' },
          },
          scales: {
            x: { title: { display: true, text: 'Submission period' } },
            y: { min: 1, max: 5, ticks: { stepSize: 1 }, title: { display: true, text: 'Average rating (1–5)' } },
          },
        },
      }));
    }

    const sentimentCanvas = document.getElementById('printSentimentDistribution');
    if (sentimentCanvas && totals.sentimentAnalyzedCount > 0) {
      activeCharts.push(new Chart(sentimentCanvas, {
        type: 'bar',
        data: {
          labels: SENTIMENTS,
          datasets: [{ label: 'Feedback entries', data: SENTIMENTS.map((label) => totals.sentimentCounts[label] || 0), backgroundColor: SENTIMENT_COLORS }],
        },
        options: {
          responsive: false,
          animation: false,
          plugins: {
            title: { display: true, text: 'Sentiment distribution' },
            legend: {
              display: true,
              position: 'bottom',
              labels: {
                generateLabels: () => SENTIMENTS.map((label, index) => ({
                  text: label,
                  fillStyle: SENTIMENT_COLORS[index],
                  strokeStyle: SENTIMENT_COLORS[index],
                  lineWidth: 0,
                  hidden: false,
                  index,
                })),
              },
              onClick: () => {},
            },
            tooltip: {
              callbacks: {
                label: (item) => `${item.label}: ${item.parsed} (${percent(item.parsed, totals.sentimentAnalyzedCount)})`,
              },
            },
          },
          scales: {
            x: { title: { display: true, text: 'Analyzed sentiment category' } },
            y: { beginAtZero: true, ticks: { precision: 0 }, title: { display: true, text: 'Feedback entries (count)' } },
          },
        },
      }));
    }
  }

  function openPeriodDialog(button) {
    returnFocus = button;
    periodSelect.value = 'all';
    const today = localDateValue(new Date());
    [specificDate, startDate, endDate].forEach((input) => {
      input.max = today;
      input.setCustomValidity('');
    });
    specificDate.value = '';
    startDate.value = '';
    endDate.value = '';
    updateDateFields();
    errorMessage.hidden = true;
    errorMessage.textContent = '';
    overlay.hidden = false;
    periodSelect.focus();
  }

  function updateDateFields() {
    specificDateFields.hidden = periodSelect.value !== 'specific';
    dateRangeFields.hidden = periodSelect.value !== 'custom';
    specificDate.required = periodSelect.value === 'specific';
    startDate.required = periodSelect.value === 'custom';
    endDate.required = periodSelect.value === 'custom';
  }

  function showPeriodError(message) {
    errorMessage.textContent = message;
    errorMessage.hidden = false;
    errorMessage.focus();
  }

  function closePeriodDialog() {
    overlay.hidden = true;
    if (returnFocus && typeof returnFocus.focus === 'function') returnFocus.focus();
  }

  function setPrintBusy(isBusy) {
    overlay.querySelectorAll('button').forEach((button) => { button.disabled = isBusy; });
    document.querySelectorAll('[data-admin-print]').forEach((button) => { button.disabled = isBusy; });
  }

  async function preparePrint() {
    const period = periodSelect.value;
    let dates;
    try {
      dates = rollingDates(period);
      const today = localDateValue(new Date());
      if (dates.dateFrom && !parseDate(dates.dateFrom)) throw new Error('Invalid start date.');
      if (dates.dateFrom > today || dates.dateTo > today) throw new Error('Future dates are not allowed.');
      if (!parseDate(dates.dateTo) || (dates.dateFrom && dates.dateFrom > dates.dateTo)) {
        throw new Error('Invalid report date range.');
      }
      setPrintBusy(true);
      await store.refreshAll();
      store.setFilters({ dateFrom: dates.dateFrom, dateTo: dates.dateTo });
      store.markDirty();
      await store.refreshFeedback(true);
      if (typeof window.renderAll === 'function') window.renderAll();
      const snapshot = store.getSnapshot();
      const entries = formatters.filterEntries(snapshot.entries, {
        datePreset: 'custom',
        dateFrom: dates.dateFrom,
        dateTo: dates.dateTo,
      });
      document.body.classList.add('admin-report-printing');
      buildReport(entries, snapshot, period, dates);
      overlay.hidden = true;
      await document.fonts?.ready;
      await new Promise((resolve) => requestAnimationFrame(() => requestAnimationFrame(resolve)));
      activeCharts.forEach((chart) => chart.resize());
      window.print();
    } catch (error) {
      document.body.classList.remove('admin-report-printing');
      activeCharts.forEach((chart) => chart.destroy());
      activeCharts = [];
      reportRoot.replaceChildren();
      reportRoot.hidden = true;
      reportRoot.setAttribute('aria-hidden', 'true');
      overlay.hidden = false;
      showPeriodError(error && error.message
        ? `Unable to prepare the report: ${error.message}`
        : 'Unable to prepare the report. Please try again.');
    } finally {
      setPrintBusy(false);
    }
  }

  document.querySelectorAll('[data-admin-print]').forEach((button) => {
    button.addEventListener('click', () => openPeriodDialog(button));
  });
  periodSelect.addEventListener('change', () => {
    errorMessage.hidden = true;
    updateDateFields();
  });
  [specificDate, startDate, endDate].forEach((input) => {
    input.addEventListener('change', () => {
      input.setCustomValidity('');
      errorMessage.hidden = true;
    });
  });
  overlay.querySelector('[data-print-period-cancel]')?.addEventListener('click', closePeriodDialog);
  overlay.querySelector('[data-print-period-confirm]')?.addEventListener('click', preparePrint);
  overlay.addEventListener('click', (event) => {
    if (event.target === overlay) closePeriodDialog();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && !overlay.hidden) closePeriodDialog();
  });
  window.addEventListener('afterprint', () => {
    document.body.classList.remove('admin-report-printing');
    activeCharts.forEach((chart) => chart.destroy());
    activeCharts = [];
    reportRoot.replaceChildren();
    reportRoot.hidden = true;
    reportRoot.setAttribute('aria-hidden', 'true');
    if (returnFocus && typeof returnFocus.focus === 'function') returnFocus.focus();
  });
})();
