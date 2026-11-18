(function () {
  function initMobileSidebar() {
    const sidebar = document.getElementById('sidebar');
    const topnav = document.querySelector('.topnav');
    if (!sidebar || !topnav || document.querySelector('.mobile-sidebar-toggle')) return;

    const toggle = document.createElement('button');
    toggle.type = 'button';
    toggle.className = 'mobile-sidebar-toggle';
    toggle.setAttribute('aria-label', 'Open navigation menu');
    toggle.setAttribute('aria-controls', 'sidebar');
    toggle.setAttribute('aria-expanded', 'false');
    toggle.innerHTML = '<span></span><span></span><span></span>';

    const overlay = document.createElement('div');
    overlay.className = 'mobile-sidebar-overlay';
    overlay.setAttribute('aria-hidden', 'true');

    topnav.prepend(toggle);
    document.body.appendChild(overlay);

    const setOpen = (open) => {
      document.body.classList.toggle('mobile-sidebar-open', open);
      toggle.setAttribute('aria-expanded', String(open));
      toggle.setAttribute('aria-label', open ? 'Close navigation menu' : 'Open navigation menu');
      overlay.setAttribute('aria-hidden', String(!open));
    };

    toggle.addEventListener('click', () => {
      setOpen(!document.body.classList.contains('mobile-sidebar-open'));
    });
    overlay.addEventListener('click', () => setOpen(false));
    sidebar.addEventListener('click', (event) => {
      const link = event.target.closest && event.target.closest('.sb-link[data-page]');
      if (!link || link.dataset.page === 'all-feedbacks' || !mobileQuery.matches) return;
      setOpen(false);
    });
    document.addEventListener('click', (event) => {
      const browseLink = event.target.closest && event.target.closest('.service-view-all, .trend-view-all');
      if (!browseLink || !mobileQuery.matches) return;

      event.preventDefault();
      event.stopImmediatePropagation();
      setOpen(true);

      const allFeedbackLink = document.querySelector('.sb-link[data-page="all-feedbacks"]');
      const submenu = document.getElementById('allFeedbackSubmenu');
      if (!allFeedbackLink || !submenu) return;

      if (!submenu.classList.contains('open')) {
        window.nav(allFeedbackLink);
      } else {
        allFeedbackLink.classList.add('sb-open');
        allFeedbackLink.setAttribute('aria-expanded', 'true');
        submenu.style.maxHeight = submenu.scrollHeight + 'px';
      }
      allFeedbackLink.classList.add('active');
    }, true);
    document.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && document.body.classList.contains('mobile-sidebar-open')) {
        setOpen(false);
        toggle.focus();
      }
    });

    const mobileQuery = window.matchMedia('(max-width: 768px)');
    const handleViewportChange = (event) => {
      if (!event.matches) setOpen(false);
    };
    if (mobileQuery.addEventListener) mobileQuery.addEventListener('change', handleViewportChange);
    else mobileQuery.addListener(handleViewportChange);
  }

  initMobileSidebar();

  const store = window.AdminDashboardStore;
  const renderers = window.AdminDashboardRenderers;
  const formatters = window.AdminDashboardFormatters;
  const service = window.AdminDashboardService;

  if (!store || !renderers || !formatters || !service) {
    return;
  }

  let currentStaffFilter = 'all';
  let currentStaffRoleView = 'active';
  let currentStaffSearch = '';
  let currentServiceFilter = 'all';
  let currentAuditRecords = null;
  let auditInitialized = false;
  let auditFilterRequestId = 0;
  let globalSearchTimer = null;
  let globalSearchController = null;
  let globalSearchRequestId = 0;
  const feedbackSearchTimers = new Map();
  const feedbackSearchControllers = new Map();
  const feedbackSearchRequestIds = new Map();
  const moduleSearchTimers = new Map();
  let recentCommentsTimer = null;
  let recentCommentsController = null;
  let recentCommentsRequestId = 0;
  let trendChartInst = null;
  let serviceChartInst = null;
  let distChartInst = null;
  let rptSentimentInst = null;
  let rptSentimentTrendInst = null;
  let rptRatingDistributionInst = null;
  let rptRatingTrendInst = null;
  let rptSentimentByServiceInst = null;

  // Sidebar pages share one filter toolbar and one rendering path.
  const FEEDBACK_PAGES = {
    patient: { bodyId: 'respPatientFbBody', summaryId: 'respPatientFbSummary' },
    companion: { bodyId: 'respCompanionFbBody', summaryId: 'respCompanionFbSummary' },
    business: { bodyId: 'respBusinessFbBody', summaryId: 'respBusinessFbSummary' },
    other: { bodyId: 'respOtherFbBody', summaryId: 'respOtherFbSummary' },
    pending: { bodyId: 'pendingList' },
    resolved: { bodyId: 'resolvedList' },
  };

  function getEl(id) {
    return document.getElementById(id);
  }

  function getFilterValue(id) {
    const element = getEl(id);
    return element ? element.value : '';
  }

  function getActivePage() {
    return document.querySelector('.page.active')?.id?.replace('page-', '') || 'dashboard';
  }

  function renderRespondentCategoryTable(categoryKey) {
    renderFeedbackPage(categoryKey);
  }

  function initFeedbackFilterBars() {
    document.querySelectorAll('[data-feedback-filters]').forEach((bar) => {
      const page = bar.dataset.feedbackFilters;
      const hasStatus = !['pending', 'resolved'].includes(page);
      bar.classList.toggle('no-status', !hasStatus);
      bar.innerHTML = `
        <input class="form-input feedback-search" type="search" data-filter="search" placeholder="Search..." aria-label="Search feedback">
        ${hasStatus ? `<select class="form-select" data-filter="status" aria-label="Status">
          <option value="">All Status</option><option value="pending">Pending</option>
          <option value="in_review">In Review</option><option value="resolved">Resolved</option>
        </select>` : ''}
        <select class="form-select" data-filter="service" aria-label="Service"><option value="">All Services</option></select>
        <select class="form-select" data-filter="rating" aria-label="Rating">
          <option value="">All Ratings</option><option value="5">5.0</option><option value="4">4.0</option>
          <option value="3">3.0</option><option value="2">2.0</option><option value="1">1.0</option>
          <option value="na">N/A</option>
        </select>
        <div class="feedback-date-control">
          <select class="form-select" data-filter="datePreset" aria-label="Date">
            <option value="">All Dates</option><option value="today">Today</option>
            <option value="week">This Week</option><option value="month">This Month</option>
            <option value="custom">Custom Date / Date Range</option>
          </select>
          <div class="feedback-date-range" hidden>
            <input class="form-input" type="date" data-filter="dateFrom" aria-label="From date">
            <input class="form-input" type="date" data-filter="dateTo" aria-label="To date">
          </div>
        </div>
        <span class="feedback-search-status" data-search-status role="status" aria-live="polite"></span>`;
      const update = (event) => {
        if (!event.target.matches('[data-filter]')) return;
        const customDate = bar.querySelector('[data-filter="datePreset"]').value === 'custom';
        bar.querySelector('.feedback-date-range').hidden = !customDate;
        bar.querySelector('.feedback-date-control').classList.toggle('custom-date', customDate);
        renderFeedbackPage(page);
      };
      bar.addEventListener('input', update);
      bar.addEventListener('change', update);
    });
  }

  function syncFeedbackServiceOptions() {
    const services = (store.getModules().services || []).filter((item) => item.status === 'active');
    document.querySelectorAll('[data-feedback-filters] [data-filter="service"]').forEach((select) => {
      const selected = select.value;
      const signature = services.map((item) => `${item.id}:${item.name}`).join('|');
      if (select.dataset.catalogSignature === signature) return;
      select.replaceChildren(new Option('All Services', ''), ...services.map((item) => new Option(item.name, String(item.id))));
      if (services.some((item) => String(item.id) === selected)) select.value = selected;
      select.dataset.catalogSignature = signature;
    });
  }

  function renderFeedbackPage(page) {
    const config = FEEDBACK_PAGES[page];
    const bar = document.querySelector(`[data-feedback-filters="${page}"]`);
    if (!config || !bar) return;
    syncFeedbackServiceOptions();
    const read = (key) => bar.querySelector(`[data-filter="${key}"]`)?.value || '';
    const filters = {
      search: read('search'), status: read('status'), service: read('service'),
      rating: read('rating'), datePreset: read('datePreset'),
      dateFrom: read('dateFrom'), dateTo: read('dateTo'),
    };
    if (filters.service) filters.serviceName = bar.querySelector('[data-filter="service"]')?.selectedOptions[0]?.textContent || '';
    if (config.summaryId) filters.respondent = page;
    else filters.statusGroup = page;
    if (filters.search.trim()) {
      searchFeedbackPage(page, config, filters);
      return;
    }
    cancelFeedbackSearch(page);
    const entries = formatters.filterEntries(store.getEntries(), filters);
    const status = bar.querySelector('[data-search-status]');
    if (status) {
      status.textContent = '';
      status.classList.remove('is-loading');
    }
    if (config.summaryId) {
      renderers.renderRespondentSummary(entries, config.summaryId);
      renderers.renderFeedbackTable(config.bodyId, entries, page, 'No feedbacks match your filters.');
    } else {
      renderers.renderFeedbackCards(config.bodyId, entries, '<div class="empty-state"><p>No feedbacks match your filters.</p></div>');
    }
  }

  function cancelFeedbackSearch(page) {
    if (feedbackSearchTimers.has(page)) {
      window.clearTimeout(feedbackSearchTimers.get(page));
      feedbackSearchTimers.delete(page);
    }
    const controller = feedbackSearchControllers.get(page);
    if (controller) {
      controller.abort();
      feedbackSearchControllers.delete(page);
    }
    feedbackSearchRequestIds.set(page, (feedbackSearchRequestIds.get(page) || 0) + 1);
  }

  function searchFeedbackPage(page, config, filters) {
    const bar = document.querySelector(`[data-feedback-filters="${page}"]`);
    const status = bar?.querySelector('[data-search-status]');
    if (feedbackSearchTimers.has(page)) window.clearTimeout(feedbackSearchTimers.get(page));
    const currentController = feedbackSearchControllers.get(page);
    if (currentController) {
      currentController.abort();
      feedbackSearchControllers.delete(page);
    }
    const requestId = (feedbackSearchRequestIds.get(page) || 0) + 1;
    feedbackSearchRequestIds.set(page, requestId);
    if (status) {
      status.textContent = 'Searching...';
      status.classList.add('is-loading');
    }
    const loadingRow = '<tr><td colspan="6" style="text-align:center;padding:20px">Searching...</td></tr>';
    if (config.summaryId) {
      renderers.renderRespondentSummary([], config.summaryId);
      const body = document.getElementById(config.bodyId);
      if (body) body.innerHTML = loadingRow;
    } else {
      const body = document.getElementById(config.bodyId);
      if (body) body.innerHTML = '<div class="empty-state"><p>Searching...</p></div>';
    }

    const timer = window.setTimeout(async () => {
      feedbackSearchTimers.delete(page);
      const controller = new AbortController();
      feedbackSearchControllers.set(page, controller);
      try {
        const apiFilters = { search: filters.search };
        if (config.summaryId) apiFilters.respondent = page;
        const result = await service.loadDashboardData(apiFilters, { signal: controller.signal });
        if (requestId !== feedbackSearchRequestIds.get(page)) return;
        const localFilters = { ...filters, search: '' };
        const entries = formatters.filterEntries(result.entries.map(formatters.normalizeFeedback), localFilters);
        const emptyMessage = filters.search.trim() ? 'No results found.' : 'No feedbacks match your filters.';
        if (config.summaryId) {
          renderers.renderRespondentSummary(entries, config.summaryId);
          renderers.renderFeedbackTable(config.bodyId, entries, page, emptyMessage);
        } else {
          renderers.renderFeedbackCards(
            config.bodyId,
            entries,
            `<div class="empty-state"><p>${formatters.escapeHtml(emptyMessage)}</p></div>`
          );
        }
        if (status) {
          status.textContent = `${entries.length} matching record${entries.length === 1 ? '' : 's'}`;
          status.classList.remove('is-loading');
        }
      } catch (error) {
        if (controller.signal.aborted || requestId !== feedbackSearchRequestIds.get(page)) return;
        if (status) {
          status.textContent = 'Search failed. Please try again.';
          status.classList.remove('is-loading');
        }
        if (config.summaryId) {
          renderers.renderFeedbackTable(config.bodyId, [], page, 'Search failed. Please try again.');
        } else {
          renderers.renderFeedbackCards(config.bodyId, [], '<div class="empty-state"><p>Search failed. Please try again.</p></div>');
        }
        AdminUI.toast(error.message || 'Unable to search feedback.', 'error');
      } finally {
        if (requestId === feedbackSearchRequestIds.get(page)) feedbackSearchControllers.delete(page);
      }
    }, 250);
    feedbackSearchTimers.set(page, timer);
  }

  initFeedbackFilterBars();

  function destroyChart(instance) {
    if (instance) {
      try {
        instance.destroy();
      } catch (error) {
        // Ignore chart teardown failures.
      }
    }
    return null;
  }

  function buildBuckets(entries, granularity) {
    const map = new Map();
    entries.forEach((entry) => {
      const key = formatters.formatDateKey(entry.timestamp, granularity);
      map.set(key, (map.get(key) || 0) + 1);
    });
    return Array.from(map.entries()).map(([label, count]) => ({ label, count })).slice(-10);
  }

  function setChartState(canvas, emptyState, hasData) {
    if (canvas) canvas.style.display = hasData ? '' : 'none';
    if (emptyState) {
      emptyState.hidden = hasData;
      emptyState.style.display = hasData ? 'none' : 'grid';
    }
  }

  function makeBarChart(canvasId, labels, data, color = '#0f766e', stacked = false) {
    const canvas = getEl(canvasId);
    if (!canvas || typeof Chart === 'undefined') {
      return null;
    }

    return new Chart(canvas, {
      type: 'bar',
      data: {
        labels,
        datasets: [{
          label: canvasId,
          data,
          backgroundColor: `${color}cc`,
          borderColor: color,
          borderWidth: 1,
          borderRadius: 4,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
          x: { stacked, grid: { display: false }, ticks: { font: { size: 10 } } },
          y: { stacked, beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { font: { size: 10 }, stepSize: 1 } },
        },
      },
    });
  }

  function makeDoughnutChart(canvasId, labels, data, colors) {
    const canvas = getEl(canvasId);
    if (!canvas || typeof Chart === 'undefined') {
      return null;
    }

    return new Chart(canvas, {
      type: 'doughnut',
      data: {
        labels,
        datasets: [{
          data,
          backgroundColor: colors,
          borderWidth: 2,
          borderColor: '#fff',
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '60%',
        plugins: { legend: { position: 'right', labels: { font: { size: 10 }, padding: 10, boxWidth: 12 } } },
      },
    });
  }

  function setSidebarBadges(snapshot) {
    const total = snapshot.summary.total || snapshot.entries.length;
    const pending = snapshot.summary.pending || snapshot.entries.filter((entry) => entry.status === 'pending').length;

    const badgeAll = getEl('badge-all');
    const badgePending = getEl('badge-pending');

    if (badgeAll) {
      badgeAll.textContent = String(total);
      badgeAll.classList.toggle('hide', total === 0);
    }

    if (badgePending) {
      badgePending.textContent = String(pending);
      badgePending.classList.toggle('hide', pending === 0);
    }

  }

  function renderTrendChart() {
    const snapshot = store.getSnapshot();
    const buckets = buildBuckets(snapshot.entries, 'weekly');
    const emptyState = getEl('trendEmptyState');
    const canvas = getEl('trendChart');

    trendChartInst = destroyChart(trendChartInst);
    const validBuckets = buckets.filter((item) => Number.isFinite(item.count) && item.count > 0 && item.label);
    const hasData = validBuckets.length > 0;
    setChartState(canvas, emptyState, hasData);
    if (!hasData || !canvas || typeof Chart === 'undefined') return;

    trendChartInst = new Chart(canvas, {
      type: 'line',
      data: {
        labels: validBuckets.map((item) => item.label),
        datasets: [{
          data: validBuckets.map((item) => item.count),
          borderColor: '#087f5b',
          borderWidth: 2.4,
          backgroundColor: (context) => {
            const { chart } = context;
            const { ctx, chartArea } = chart;
            if (!chartArea) return 'rgba(16, 185, 129, 0.08)';
            const gradient = ctx.createLinearGradient(0, chartArea.top, 0, chartArea.bottom);
            gradient.addColorStop(0, 'rgba(8, 127, 91, 0.16)');
            gradient.addColorStop(1, 'rgba(8, 127, 91, 0.01)');
            return gradient;
          },
          fill: true,
          tension: 0.36,
          pointRadius: 3.5,
          pointHoverRadius: 5.5,
          pointBackgroundColor: '#087f5b',
          pointBorderColor: '#ffffff',
          pointBorderWidth: 2,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
          legend: { display: false },
          tooltip: {
            displayColors: false,
            callbacks: {
              title: (items) => items[0]?.label || '',
              label: (item) => `${item.parsed.y} feedback submission${item.parsed.y === 1 ? '' : 's'}`,
            },
          },
        },
        scales: {
          x: {
            grid: { color: 'rgba(148, 163, 184, 0.10)' },
            ticks: { color: '#71817b', maxRotation: 0, autoSkip: true, maxTicksLimit: 6, font: { family: 'Sora', size: 10 } },
          },
          y: {
            beginAtZero: true,
            ticks: { color: '#71817b', precision: 0, padding: 8, font: { family: 'Sora', size: 10 } },
            grid: { color: 'rgba(113, 129, 123, 0.12)' },
          },
        },
      },
    });
  }

  function renderDistChart() {
    const snapshot = store.getSnapshot();
    const entries = snapshot.entries;

    serviceChartInst = destroyChart(serviceChartInst);
    distChartInst = destroyChart(distChartInst);
    const serviceCanvas = getEl('serviceChart');
    const serviceEmptyState = getEl('serviceEmptyState');
    const serviceCounts = {};
    entries.forEach((entry) => {
      const label = String(entry.service || '').trim();
      if (!label) return;
      serviceCounts[label] = (serviceCounts[label] || 0) + 1;
    });
    const services = Object.entries(serviceCounts)
      .sort((left, right) => right[1] - left[1])
      .slice(0, 6);
    const hasServiceData = services.length > 0;
    setChartState(serviceCanvas, serviceEmptyState, hasServiceData);
    if (serviceCanvas && hasServiceData && typeof Chart !== 'undefined') {
      const serviceValueLabels = {
        id: 'serviceValueLabels',
        afterDatasetsDraw(chart) {
          const { ctx } = chart;
          const dataset = chart.data.datasets[0];
          const meta = chart.getDatasetMeta(0);
          ctx.save();
          ctx.fillStyle = '#17324d';
          ctx.font = '600 12px Sora';
          ctx.textAlign = 'center';
          ctx.textBaseline = 'bottom';
          meta.data.forEach((bar, index) => {
            ctx.fillText(String(dataset.data[index]), bar.x, bar.y - 8);
          });
          ctx.restore();
        },
      };
      const serviceLabels = services.map(([label]) => {
        const lines = [];
        let line = '';
        String(label).split(/\s+/).forEach((word) => {
          if (line && `${line} ${word}`.length > 17) {
            lines.push(line);
            line = word;
          } else {
            line = line ? `${line} ${word}` : word;
          }
        });
        if (line) lines.push(line);
        return lines;
      });
      serviceChartInst = new Chart(serviceCanvas, {
        type: 'bar',
        data: {
          labels: serviceLabels,
          datasets: [{
            data: services.map(([, count]) => count),
            backgroundColor: '#239b72',
            hoverBackgroundColor: '#087f5b',
            borderRadius: 5,
            borderSkipped: false,
            maxBarThickness: 34,
          }],
        },
        plugins: [serviceValueLabels],
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: {
              displayColors: false,
              callbacks: { label: (item) => `${item.parsed.y} feedback submission${item.parsed.y === 1 ? '' : 's'}` },
            },
          },
          scales: {
            x: { grid: { display: false }, ticks: { color: '#687d8f', maxRotation: 0, minRotation: 0, autoSkip: true, maxTicksLimit: 6, font: (context) => { const label = context.chart.data.labels[context.index]; const length = Array.isArray(label) ? label.join(' ').length : String(label || '').length; return { family: 'Sora', size: length > 28 ? 8 : length > 18 ? 9 : 10 }; }, padding: 8 } },
            y: { beginAtZero: true, grace: '12%', ticks: { color: '#687d8f', precision: 0, padding: 8, font: { family: 'Sora', size: 10 } }, grid: { color: 'rgba(113, 129, 123, 0.14)' }, border: { display: false } },
          },
        },
      });
    }

    const canvas = getEl('sentimentChart');
    const emptyState = getEl('sentimentEmptyState');
    const legend = getEl('sentimentLegend');
    const labels = ['Positive', 'Neutral', 'Negative'];
    const colors = ['#239b72', '#94a3a0', '#e7655b'];
    const counts = labels.map((label) => entries.filter((entry) => entry.sentimentLabel === label).length);
    const total = counts.reduce((sum, count) => sum + count, 0);
    const hasSentimentData = counts.some((count) => count > 0);

    setChartState(canvas, emptyState, hasSentimentData);
    if (legend) {
      legend.hidden = !hasSentimentData;
      legend.style.display = hasSentimentData ? 'grid' : 'none';
      legend.innerHTML = labels.map((label, index) => {
        const percentage = total ? Math.round((counts[index] / total) * 100) : 0;
        return `<div class="sentiment-legend-item"><span class="sentiment-legend-dot" style="background:${colors[index]}"></span><span class="sentiment-legend-name">${label}</span><span class="sentiment-legend-value">${counts[index]} <small>(${percentage}%)</small></span></div>`;
      }).join('');
    }
    if (!canvas || typeof Chart === 'undefined' || !hasSentimentData) return;

    const centerText = {
      id: 'sentimentCenterText',
      afterDraw(chart) {
        const { ctx, chartArea } = chart;
        if (!chartArea) return;
        ctx.save();
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        const centerX = (chartArea.left + chartArea.right) / 2;
        const centerY = (chartArea.top + chartArea.bottom) / 2;
        ctx.fillStyle = '#17221f';
        ctx.font = '600 24px Sora';
        ctx.fillText(String(total), centerX, centerY - 6);
        ctx.fillStyle = '#71817b';
        ctx.font = '500 9px Sora';
        ctx.fillText('ANALYZED', centerX, centerY + 14);
        ctx.restore();
      },
    };

    distChartInst = new Chart(canvas, {
      type: 'doughnut',
      data: { labels, datasets: [{ data: counts, backgroundColor: colors, hoverOffset: 3, borderColor: '#ffffff', borderWidth: 3 }] },
      plugins: [centerText],
      options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '70%',
        plugins: {
          legend: { display: false },
          tooltip: {
            displayColors: false,
            callbacks: {
              label: (item) => `${item.label}: ${item.parsed} (${total ? Math.round((item.parsed / total) * 100) : 0}%)`,
            },
          },
        },
      },
    });
  }

  function renderReportCharts() {
    const snapshot = store.getSnapshot();
    const entries = snapshot.entries;
    const report = formatters.reportTotals(entries);
    const sentimentLabels = ['Positive', 'Neutral', 'Negative'];
    const sentimentColors = {
      Positive: '#16a34a', Neutral: '#64748b', Negative: '#dc2626',
    };
    const sentimentColor = (label) => sentimentColors[label] || '#475569';

    rptSentimentInst = destroyChart(rptSentimentInst);
    rptSentimentTrendInst = destroyChart(rptSentimentTrendInst);
    rptRatingDistributionInst = destroyChart(rptRatingDistributionInst);
    rptSentimentByServiceInst = destroyChart(rptSentimentByServiceInst);

    const ratingCanvas = getEl('rptRatingDistribution');
    const ratingEmpty = getEl('ratingDistributionEmpty');
    const ratingCounts = formatters.ratingDistribution(entries);
    const hasRatingData = ratingCounts.some((count) => count > 0);
    setChartState(ratingCanvas, ratingEmpty, hasRatingData);
    if (ratingCanvas && hasRatingData && typeof Chart !== 'undefined') {
      rptRatingDistributionInst = new Chart(ratingCanvas, {
        type: 'bar',
        data: {
          labels: ['1 Star', '2 Stars', '3 Stars', '4 Stars', '5 Stars'],
          datasets: [{ label: 'Feedback Responses', data: ratingCounts, backgroundColor: ['#dc2626', '#ea580c', '#d97706', '#65a30d', '#16a34a'], borderRadius: 4 }],
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: (item) => `${item.parsed.y} feedback response${item.parsed.y === 1 ? '' : 's'}` } },
          },
          scales: {
            x: { grid: { display: false }, ticks: { font: { size: 10 } } },
            y: { beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { precision: 0, stepSize: 1 } },
          },
        },
      });
    }

    renderRatingTrendChart();

    const sentimentServiceCanvas = getEl('rptSentimentByService');
    const sentimentServiceEmpty = getEl('sentimentByServiceEmpty');
    const catalogServices = Array.isArray(snapshot.modules?.services)
      ? snapshot.modules.services.filter((service) => service.status === 'active')
      : [];
    const serviceSentiments = formatters.sentimentByService(entries, catalogServices);
    const hasServiceSentimentData = serviceSentiments.some((serviceItem) => serviceItem.hasAnalyzedSentiment);
    setChartState(sentimentServiceCanvas, sentimentServiceEmpty, hasServiceSentimentData);
    if (sentimentServiceCanvas && hasServiceSentimentData && typeof Chart !== 'undefined') {
      rptSentimentByServiceInst = new Chart(sentimentServiceCanvas, {
        type: 'bar',
        data: {
          labels: serviceSentiments.map((serviceItem) => serviceItem.label),
          datasets: sentimentLabels.map((label) => ({
            label,
            data: serviceSentiments.map((serviceItem) => serviceItem.percentages[label] || 0),
            backgroundColor: sentimentColor(label),
            borderRadius: 3,
          })),
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: {
            legend: { position: 'top', labels: { font: { size: 10 }, padding: 10, boxWidth: 12 } },
            tooltip: { callbacks: { label: (item) => serviceSentiments[item.dataIndex].hasAnalyzedSentiment
              ? `${item.dataset.label}: ${Number(item.parsed.y).toFixed(1)}% (${serviceSentiments[item.dataIndex][item.dataset.label] || 0} feedback${(serviceSentiments[item.dataIndex][item.dataset.label] || 0) === 1 ? '' : 's'})`
              : 'No analyzed sentiment' } },
          },
          scales: {
            x: { stacked: true, grid: { display: false }, ticks: { font: { size: 10 }, maxRotation: 35, minRotation: 0 } },
            y: { stacked: true, min: 0, max: 100, beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { callback: (value) => `${value}%`, stepSize: 20 } },
          },
        },
      });
    }

    const sentimentCanvas = getEl('rptSentiment');
    const sentimentEmpty = getEl('sentimentReportEmptyState');
    const hasSentimentData = report.sentimentAnalyzedCount > 0;
    setChartState(sentimentCanvas, sentimentEmpty, hasSentimentData);
    if (sentimentCanvas && hasSentimentData && typeof Chart !== 'undefined') {
      rptSentimentInst = new Chart(sentimentCanvas, {
        type: 'pie',
        data: {
          labels: sentimentLabels,
          datasets: [{ data: sentimentLabels.map((label) => report.sentimentCounts[label]), backgroundColor: sentimentLabels.map(sentimentColor), borderWidth: 2, borderColor: '#fff' }],
        },
        options: { responsive: true, maintainAspectRatio: false, plugins: { legend: { position: 'right', labels: { font: { size: 10 }, padding: 10, boxWidth: 12 } } } },
      });
    }

    const mode = getEl('sentTrendSel')?.value || 'monthly';
    const sentimentPeriods = formatters.reportSentimentTrend(entries, mode);
    const sentimentTrendCanvas = getEl('rptSentimentTrend');
    const sentimentTrendEmpty = getEl('sentimentTrendEmptyState');
    const hasSentimentTrend = sentimentPeriods.length > 0;
    setChartState(sentimentTrendCanvas, sentimentTrendEmpty, hasSentimentTrend);
    if (hasSentimentTrend && sentimentTrendCanvas && typeof Chart !== 'undefined') {
      rptSentimentTrendInst = new Chart(sentimentTrendCanvas, {
        type: 'bar',
        data: {
          labels: sentimentPeriods.map((period) => period.label),
          datasets: sentimentLabels.map((sentiment) => ({
            label: sentiment,
            data: sentimentPeriods.map((period) => period.counts[sentiment] || 0),
            backgroundColor: sentimentColor(sentiment),
            borderRadius: 3,
          })),
        },
        options: {
          responsive: true,
          maintainAspectRatio: false,
          plugins: { legend: { position: 'top', labels: { font: { size: 10 }, padding: 10, boxWidth: 12 } } },
          scales: { x: { stacked: true, grid: { display: false }, ticks: { font: { size: 10 } } }, y: { stacked: true, beginAtZero: true, grid: { color: '#f1f5f9' }, ticks: { stepSize: 1 } } },
        },
      });
    }

    const servicePerfBody = getEl('servicePerfBody');
    if (servicePerfBody) {
      const rows = formatters.serviceComparison(entries);

      servicePerfBody.innerHTML = rows.length
        ? rows.map((row, index) => {
            const scoreClass = row.avg === null ? 'na' : row.avg >= 4 ? 'hi' : row.avg >= 3 ? 'mid' : 'lo';
            const status = row.avg === null ? '<span class="pill pill-slate">—</span>' : row.avg >= 4 ? '<span class="pill pill-green">High</span>' : row.avg >= 3 ? '<span class="pill pill-amber">Moderate</span>' : '<span class="pill pill-red">Low</span>';
            const satisfactionClass = row.satisfaction === null ? 'na' : row.satisfaction >= 85 ? 'hi' : row.satisfaction >= 50 ? 'mid' : 'lo';
            const satisfactionDisplay = row.satisfaction === null ? '—' : `${row.satisfaction.toFixed(0)}%`;
            return `<tr><td style="color:var(--ink-3)">${index + 1}</td><td><strong>${formatters.escapeHtml(row.label)}</strong></td><td>${row.count}</td><td>${row.ratingCount}</td><td><span class="score ${scoreClass}">${row.avg === null ? '—' : row.avg.toFixed(1)}</span></td><td><span class="score ${satisfactionClass}">${satisfactionDisplay}</span></td><td>${status}</td></tr>`;
          }).join('')
        : '<tr><td colspan="7" style="text-align:center;padding:20px;color:var(--ink-3)">No data yet.</td></tr>';
    }
  }

  // Staff Ratings (formerly "Staff Performance") — the 10 fixed staff-role
  // rating questions (st0-st9) from the feedback form, not individual named
  // employees. Labels/active-state come from the `staff` table (module
  // payload's staffRoles, kept fresh via store.refreshModules()); the
  // rating math itself is still computed purely from each entry's st0-st9
  // answers. See formatters.computeStaffRoleStats() for the aggregation
  // (per-role 5/4/3/2/1/N/A counts + N/A-excluded average) and
  // renderers.renderStaffRatingsTable/renderStaffOverallSummary for display.
  function renderStaffTable() {
    const staffRoles = store.getModules().staffRoles;
    const stats = formatters.computeStaffRoleStats(store.getEntries(), staffRoles);
    renderers.renderStaffOverallSummary(stats.overall);
    renderers.renderStaffRatingsTable('staffBody', stats.roles, currentStaffFilter, currentStaffSearch, currentStaffRoleView);
  }

  function renderRecentTable() {
    renderers.renderRecentFeedback(store.getEntries());
  }

  function syncDashboardKpis(snapshot) {
    const total = snapshot.entries.length;
    const pending = snapshot.entries.filter((entry) => entry.status === 'pending').length;
    const resolved = snapshot.entries.filter((entry) => entry.status === 'resolved').length;
    const setText = (id, value) => { const element = getEl(id); if (element) element.textContent = String(value); };
    setText('valTotal', total);
    setText('valPending', pending);
    setText('valResolved', resolved);
  }

  function renderModulesPage() {
    window.AdminDashboardServiceFilter = currentServiceFilter;
    renderers.renderModules(store.getSnapshot());
    renderAudit();
  }

  function renderAuditPage() {
    renderers.renderModules(store.getSnapshot());
    if (!auditInitialized) {
      auditInitialized = true;
      const dateFilter = getEl('auditDateFilter');
      dateFilter.value = 'today';
      currentAuditRecords = [];
      renderAudit();
      loadAuditDateFilter(auditDateRange('today'));
      return;
    }
    renderAudit();
  }

  function renderNotifs() {
    renderers.renderModules(store.getSnapshot());
    renderAudit();
  }

  function renderAudit() {
    const auditRecords = currentAuditRecords === null
      ? store.getModules().audit
      : currentAuditRecords;
    const emptyMessage = getFilterValue('auditDateFilter') === 'custom' && !getFilterValue('auditCustomDate')
      ? 'Select a date to view audit entries.'
      : 'No audit entries found.';
    renderers.renderAuditTable(auditRecords, getFilterValue('auditSearch'), emptyMessage);
  }

  function debounceModuleSearch(key, callback, delay = 200) {
    if (moduleSearchTimers.has(key)) window.clearTimeout(moduleSearchTimers.get(key));
    moduleSearchTimers.set(key, window.setTimeout(() => {
      moduleSearchTimers.delete(key);
      callback();
    }, delay));
  }

  function handleRecentCommentsSearch() {
    const input = getEl('notifSearch');
    const list = getEl('dashboardNotifList');
    const status = getEl('notifSearchStatus');
    if (!input || !list) return;
    const query = input.value.trim();
    if (recentCommentsTimer !== null) {
      window.clearTimeout(recentCommentsTimer);
      recentCommentsTimer = null;
    }
    if (recentCommentsController) {
      recentCommentsController.abort();
      recentCommentsController = null;
    }
    const requestId = ++recentCommentsRequestId;
    if (!query) {
      if (status) {
        status.textContent = '';
        status.classList.remove('is-loading');
      }
      list.removeAttribute('aria-busy');
      renderers.renderRecentComments(store.getEntries());
      return;
    }

    list.setAttribute('aria-busy', 'true');
    if (status) {
      status.textContent = 'Searching...';
      status.classList.add('is-loading');
    }
    list.innerHTML = '<div class="empty-state"><p>Searching...</p></div>';
    recentCommentsTimer = window.setTimeout(async () => {
      recentCommentsTimer = null;
      const controller = new AbortController();
      recentCommentsController = controller;
      try {
        const result = await service.loadDashboardData({ search: query, searchScope: 'comments' }, { signal: controller.signal });
        if (requestId !== recentCommentsRequestId) return;
        const entries = result.entries.map(formatters.normalizeFeedback);
        renderers.renderRecentComments(entries, 'No results found.');
        const matchCount = entries.filter((entry) => [entry.feedback, entry.suggestions, entry.appreciation].some(formatters.isWrittenText)).length;
        if (status) {
          status.textContent = `${matchCount} matching record${matchCount === 1 ? '' : 's'}`;
          status.classList.remove('is-loading');
        }
      } catch (error) {
        if (controller.signal.aborted || requestId !== recentCommentsRequestId) return;
        list.innerHTML = '<div class="empty-state"><p>Search failed. Please try again.</p></div>';
        if (status) {
          status.textContent = 'Search failed.';
          status.classList.remove('is-loading');
        }
        AdminUI.toast(error.message || 'Unable to search comments.', 'error');
      } finally {
        if (requestId === recentCommentsRequestId) {
          list.removeAttribute('aria-busy');
          recentCommentsController = null;
        }
      }
    }, 250);
  }

  function initializeDashboardSearches() {
    getEl('serviceSearch')?.addEventListener('input', () => debounceModuleSearch('services', renderModulesPage));
    getEl('auditSearch')?.addEventListener('input', () => debounceModuleSearch('audit', renderAudit));
    getEl('notifSearch')?.addEventListener('input', handleRecentCommentsSearch);
  }

  function localDateValue(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  }

  function auditDateRange(filter, today = new Date()) {
    const end = new Date(today.getFullYear(), today.getMonth(), today.getDate());
    if (filter === 'today') {
      const date = localDateValue(end);
      return { date };
    }
    if (filter === 'this-week') {
      const start = new Date(end);
      const daysSinceMonday = (start.getDay() + 6) % 7;
      start.setDate(start.getDate() - daysSinceMonday);
      return { date_from: localDateValue(start), date_to: localDateValue(end) };
    }
    if (filter === 'last-month') {
      const start = new Date(end.getFullYear(), end.getMonth() - 1, 1);
      const lastDay = new Date(end.getFullYear(), end.getMonth(), 0);
      return { date_from: localDateValue(start), date_to: localDateValue(lastDay) };
    }
    return {};
  }

  async function loadAuditDateFilter(filters) {
    const requestId = ++auditFilterRequestId;
    try {
      const records = await service.loadAuditRecords(filters);
      if (requestId !== auditFilterRequestId) return;
      currentAuditRecords = records;
      renderAudit();
    } catch (error) {
      if (requestId !== auditFilterRequestId) return;
      AdminUI.toast(error.message || 'Unable to filter audit records.', 'error');
    }
  }

  function openAuditDatePicker() {
    const dateInput = getEl('auditCustomDate');
    if (!dateInput) return;

    try {
      if (typeof dateInput.showPicker === 'function') dateInput.showPicker();
      else dateInput.click();
    } catch (error) {
      if (!(error instanceof DOMException)) throw error;
      AdminUI.toast(error.message || 'Unable to open the date picker.', 'error');
    }
  }

  function handleAuditDateFilterChange() {
    const filter = getFilterValue('auditDateFilter');

    if (filter === 'custom') {
      const date = getFilterValue('auditCustomDate');
      if (date) {
        getEl('auditDateFilter').value = 'custom-date';
        loadAuditDateFilter({ date });
      } else {
        auditFilterRequestId += 1;
        currentAuditRecords = [];
        renderAudit();
      }
      openAuditDatePicker();
      return;
    }

    if (filter === 'custom-date') {
      const date = getFilterValue('auditCustomDate');
      if (date) {
        loadAuditDateFilter({ date });
      } else {
        getEl('auditDateFilter').value = 'custom';
        openAuditDatePicker();
      }
      return;
    }

    loadAuditDateFilter(filter === 'all' ? {} : auditDateRange(filter));
  }

  function handleAuditCustomDateChange() {
    const date = getFilterValue('auditCustomDate');
    if (!date) {
      auditFilterRequestId += 1;
      getEl('auditDateFilter').value = 'custom';
      currentAuditRecords = [];
      renderAudit();
      return;
    }

    const dateFilter = getEl('auditDateFilter');
    let selectedDateOption = dateFilter.querySelector('option[data-selected-audit-date]');
    if (!selectedDateOption) {
      selectedDateOption = document.createElement('option');
      selectedDateOption.dataset.selectedAuditDate = 'true';
      dateFilter.appendChild(selectedDateOption);
    }
    selectedDateOption.value = 'custom-date';
    selectedDateOption.textContent = `Custom: ${date}`;
    dateFilter.value = 'custom-date';

    loadAuditDateFilter({ date });
  }

  function renderVisiblePage() {
    const page = getActivePage();
    switch (page) {
      case 'dashboard':
        renderRecentTable();
        renderTrendChart();
        renderDistChart();
        break;
      case 'respondent-patient':
      case 'respondent-companion':
      case 'respondent-business':
      case 'respondent-other':
        renderRespondentCategoryTable(page.replace('respondent-', ''));
        break;
      case 'pending':
      case 'resolved':
        renderFeedbackPage(page);
        break;
      case 'reports':
        renderReportCharts();
        break;
      case 'staff':
        renderStaffTable();
        break;
      case 'services':
      case 'notifications':
        renderModulesPage();
        break;
      case 'audit':
        renderAuditPage();
        break;
      case 'settings':
        break;
      default:
        renderRecentTable();
        break;
    }
  }

  async function refreshAndRender(force = false) {
    await store.refreshAll(force);
    renderAll();
  }

  function renderRatingTrendChart() {
    const entries = store.getEntries();
    const mode = getEl('ratingTrendSel')?.value || 'monthly';
    const points = formatters.ratingTrend(entries, mode);
    const canvas = getEl('rptRatingTrend');
    const emptyState = getEl('ratingTrendEmpty');

    rptRatingTrendInst = destroyChart(rptRatingTrendInst);
    setChartState(canvas, emptyState, points.length > 0);
    if (!canvas || !points.length || typeof Chart === 'undefined') return;

    rptRatingTrendInst = new Chart(canvas, {
      type: 'line',
      data: {
        labels: points.map((point) => point.label),
        datasets: [{
          label: 'Average Rating',
          data: points.map((point) => point.average),
          borderColor: '#087f5b',
          backgroundColor: 'rgba(8,127,91,.12)',
          borderWidth: 2.4,
          pointBackgroundColor: '#087f5b',
          pointRadius: 3,
          pointHoverRadius: 5,
          fill: true,
          tension: 0.25,
        }],
      },
      options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
          legend: { display: false },
          tooltip: { callbacks: { label: (item) => `Average: ${Number(item.parsed.y).toFixed(2)} / 5 (${points[item.dataIndex].count} response${points[item.dataIndex].count === 1 ? '' : 's'})` } },
        },
        scales: {
          x: { grid: { display: false }, ticks: { font: { size: 10 }, maxRotation: 35, minRotation: 0 } },
          y: { min: 1, max: 5, grid: { color: '#f1f5f9' }, ticks: { stepSize: 1 } },
        },
      },
    });
  }

  function localDateValue(date) {
    const year = date.getFullYear();
    const month = String(date.getMonth() + 1).padStart(2, '0');
    const day = String(date.getDate()).padStart(2, '0');
    return `${year}-${month}-${day}`;
  }

  function setActiveAnalyticsPreset(value) {
    document.querySelectorAll('[data-analytics-range]').forEach((button) => {
      button.classList.toggle('active', String(button.dataset.analyticsRange) === String(value));
    });
  }

  function updateAnalyticsFilterStatus(filters, count) {
    const status = getEl('analyticsFilterStatus');
    if (!status) return;
    const parts = [];
    if (filters.dateFrom || filters.dateTo) parts.push(`${filters.dateFrom || 'Beginning'} to ${filters.dateTo || 'Today'}`);
    if (filters.service) {
      const select = getEl('analyticsServiceFilter');
      parts.push(select?.selectedOptions?.[0]?.textContent || 'Selected service');
    }
    status.classList.remove('is-error');
    status.textContent = `${parts.length ? parts.join(' · ') : 'All time · All services'} · ${count} feedback record${count === 1 ? '' : 's'}`;
  }

  window.applyAnalyticsFilters = async function () {
    const dateFrom = getFilterValue('analyticsStartDate');
    const dateTo = getFilterValue('analyticsEndDate');
    const serviceId = getFilterValue('analyticsServiceFilter');
    const status = getEl('analyticsFilterStatus');
    if (dateFrom && dateTo && dateFrom > dateTo) {
      if (status) {
        status.classList.add('is-error');
        status.textContent = 'Start Date cannot be later than End Date.';
      }
      return false;
    }

    store.setFilters({
      search: '', status: '', sentiment: '', priority: '', feedbackType: '', respondent: '',
      minRating: '', maxRating: '', service: serviceId, dateFrom, dateTo,
    });
    store.markDirty();
    try {
      await store.refreshFeedback(true);
      window.renderAll();
      updateAnalyticsFilterStatus(store.getSnapshot().filters, store.getEntries().length);
      return true;
    } catch (error) {
      if (status) {
        status.classList.add('is-error');
        status.textContent = error.message || 'Unable to apply dashboard filters.';
      }
      return false;
    }
  };

  window.setAnalyticsDatePreset = async function (range, button) {
    const start = getEl('analyticsStartDate');
    const end = getEl('analyticsEndDate');
    if (!start || !end) return false;
    if (range === 'all') {
      start.value = '';
      end.value = '';
    } else {
      const days = Number(range);
      const today = new Date();
      const first = new Date(today.getFullYear(), today.getMonth(), today.getDate());
      first.setDate(first.getDate() - Math.max(0, days - 1));
      start.value = localDateValue(first);
      end.value = localDateValue(today);
    }
    setActiveAnalyticsPreset(range);
    if (button) button.classList.add('active');
    return window.applyAnalyticsFilters();
  };

  ['analyticsStartDate', 'analyticsEndDate'].forEach((id) => {
    getEl(id)?.addEventListener('change', () => setActiveAnalyticsPreset('custom'));
  });

  window.loadEntries = function () {
    return store.getEntries();
  };

  window.getFilteredEntries = function () {
    const category = getActivePage().replace('respondent-', '');
    const filters = { search: getFilterValue('globalSearch') };
    if (FEEDBACK_PAGES[category]?.summaryId) filters.respondent = category;
    return formatters.filterEntries(store.getEntries(), filters);
  };

  window.syncFeedback = async function () {
    await store.refreshFeedback(true);
    const alerts = store.getSummary().alerts || [];
    try {
      await fetch('/Feedback/api/notifications.php', {
        method: 'POST',
        headers: csrfHeaders({ 'Content-Type': 'application/json', 'Accept': 'application/json' }),
        body: JSON.stringify({ alerts }),
      });
      await syncNotifications();
    } catch (error) {
      console.warn('Alert notification sync failed', error);
    }
    return true;
  };

  window.syncCatalog = async function () {
    await store.refreshModules(true);
    return true;
  };

  window.loadDashboard = async function () {
    await refreshAndRender(true);
  };

  window.toggleDark = function () {
    document.body.classList.toggle('dark');
  };

  window.nav = function (el) {
    if (!el) return;
    const target = el.dataset.page;
    if (!target) return;

    document.querySelectorAll('.sb-link').forEach((link) => link.classList.remove('active'));
    el.classList.add('active');
    if (el.classList.contains('sub')) {
      const sibling = el.previousElementSibling;
      if (sibling && sibling.classList.contains('sb-link')) sibling.classList.add('active');
    }

    document.querySelectorAll('.page').forEach((page) => page.classList.remove('active'));
    const pageElement = document.getElementById(`page-${target}`);
    if (pageElement) pageElement.classList.add('active');

    const titleMap = {
      dashboard: 'Dashboard',
      'respondent-patient': formatters.respondentCategoryLabel('patient'),
      'respondent-companion': formatters.respondentCategoryLabel('companion'),
      'respondent-business': formatters.respondentCategoryLabel('business'),
      'respondent-other': formatters.respondentCategoryLabel('other'),
      pending: 'Pending Review',
      resolved: 'Resolved',
      reports: 'Reports',
      staff: 'Staff Ratings',
      services: 'Services',
      notifications: 'Notifications',
      audit: 'Audit Log',
      settings: 'Settings',
    };
    const pageTitle = getEl('pageTitle');
    if (pageTitle) pageTitle.textContent = titleMap[target] || target;

    getEl('scrollArea')?.scrollTo({ top: 0, behavior: 'smooth' });
    renderVisiblePage();
    if (target === 'notifications') {
      syncNotifications();
    }
  };

  window.renderDashboard = function () {
    const snapshot = store.getSnapshot();
    renderers.renderDashboard(snapshot);
    syncDashboardKpis(snapshot);
    setSidebarBadges(snapshot);
    renderRecentTable();
    renderTrendChart();
    renderDistChart();
    updateAnalyticsFilterStatus(snapshot.filters || {}, snapshot.entries.length);
    renderVisiblePage();
  };

  window.renderAll = function () {
    const snapshot = store.getSnapshot();
    renderers.renderDashboard(snapshot);
    syncDashboardKpis(snapshot);
    setSidebarBadges(snapshot);
    renderRecentTable();
    updateAnalyticsFilterStatus(snapshot.filters || {}, snapshot.entries.length);
    renderVisiblePage();
  };

  window.renderAllFbTable = function () {
    const category = getActivePage().replace('respondent-', '');
    renderFeedbackPage(category);
  };

  window.renderPatientFbTable = function () {
    renderRespondentCategoryTable('patient');
  };

  window.renderVisitorFbTable = function () {
    renderRespondentCategoryTable('business');
  };

  window.renderRespondentCategoryTable = function (categoryKey) {
    renderRespondentCategoryTable(categoryKey);
  };

  window.renderFeedbackPage = function (page) {
    renderFeedbackPage(page);
  };

  window.renderFbLists = function () {
    const page = getActivePage();
    if (page === 'pending' || page === 'resolved') renderFeedbackPage(page);
  };

  window.renderRecentTable = function () {
    renderRecentTable();
  };

  window.renderStaffTable = function () {
    renderStaffTable();
  };

  window.renderServices = function () {
    renderModulesPage();
  };

  window.renderNotifs = function () {
    renderNotifs();
  };

  window.renderAudit = function () {
    renderAudit();
  };

  window.handleAuditDateFilterChange = handleAuditDateFilterChange;
  window.handleAuditCustomDateChange = handleAuditCustomDateChange;
  window.initializeAuditLog = renderAuditPage;

  window.renderTrendChart = function () {
    renderTrendChart();
  };

  window.renderDistChart = function () {
    renderDistChart();
  };

  window.renderReportCharts = function () {
    renderReportCharts();
  };

  window.renderSentimentTrendChart = function () {
    renderReportCharts();
  };

  window.renderRatingTrendChart = function () {
    renderRatingTrendChart();
  };

  window.filterStaff = function (level, btn) {
    currentStaffFilter = level;
    document.querySelectorAll('.staff-filters .f-chip').forEach((chip) => chip.classList.remove('active'));
    if (btn) btn.classList.add('active');
    renderStaffTable();
  };

  window.setStaffRoleView = function (view) {
    currentStaffRoleView = ['active', 'archived', 'all'].includes(view) ? view : 'active';
    renderStaffTable();
  };

  window.filterServices = function (level, btn) {
    currentServiceFilter = level;
    document.querySelectorAll('.service-filters .f-chip').forEach((chip) => chip.classList.remove('active'));
    if (btn) btn.classList.add('active');
    renderModulesPage();
  };

  window.searchStaffRatings = function (query) {
    currentStaffSearch = String(query || '');
    debounceModuleSearch('staff', renderStaffTable);
  };

  function renderGlobalSearchMessage(message, loading = false) {
    const input = getEl('globalSearch');
    const results = getEl('globalSearchResults');
    if (!input || !results) return;

    const status = document.createElement('div');
    status.className = `global-search-status${loading ? ' is-loading' : ''}`;
    status.setAttribute('role', 'status');
    if (loading) {
      const spinner = document.createElement('span');
      spinner.className = 'global-search-spinner';
      spinner.setAttribute('aria-hidden', 'true');
      status.appendChild(spinner);
    }
    const text = document.createElement('span');
    text.textContent = message;
    status.appendChild(text);
    results.replaceChildren(status);
    results.hidden = false;
    input.setAttribute('aria-expanded', 'true');
  }

  function dismissGlobalSearch() {
    if (globalSearchTimer !== null) {
      window.clearTimeout(globalSearchTimer);
      globalSearchTimer = null;
    }
    if (globalSearchController) {
      globalSearchController.abort();
      globalSearchController = null;
    }
    globalSearchRequestId++;

    const input = getEl('globalSearch');
    const results = getEl('globalSearchResults');
    if (input) {
      input.setAttribute('aria-busy', 'false');
      input.setAttribute('aria-expanded', 'false');
    }
    if (results) {
      results.hidden = true;
      results.replaceChildren();
    }
  }

  function renderGlobalSearchResults(entries, totalMatches, query) {
    const input = getEl('globalSearch');
    const results = getEl('globalSearchResults');
    if (!input || !results) return;

    results.replaceChildren();
    if (totalMatches === 0) {
      renderGlobalSearchMessage('No results found');
      return;
    }

    const count = document.createElement('div');
    count.className = 'global-search-status';
    count.setAttribute('role', 'status');
    count.textContent = totalMatches > entries.length
      ? `Showing ${entries.length} of ${totalMatches} results`
      : `${totalMatches} result${totalMatches === 1 ? '' : 's'}`;
    results.appendChild(count);

    const list = document.createElement('div');
    list.className = 'global-search-list';
    list.setAttribute('role', 'listbox');
    list.setAttribute('aria-label', 'Matching feedback');
    entries.forEach((entry) => {
      const referenceId = String(entry.referenceId || '');
      if (!referenceId) return;

      const button = document.createElement('button');
      button.type = 'button';
      button.className = 'global-search-result';
      button.dataset.referenceId = referenceId;
      button.setAttribute('role', 'option');
      button.setAttribute('aria-selected', 'false');

      const title = document.createElement('span');
      title.className = 'global-search-result-title';
      title.textContent = String(entry.subject || '').trim() || `Feedback ${referenceId}`;
      button.appendChild(title);

      const service = String(entry.serviceOther || entry.feedbackServiceOther || entry.service || '').trim();
      const subservice = String(entry.serviceSubtypeOther || entry.feedbackServiceSubtypeOther || entry.serviceSubtype || '').trim();
      const respondent = entry.isAnonymous
        ? 'Anonymous'
        : String(entry.fullName || entry.email || '').trim();
      const details = document.createElement('span');
      details.className = 'global-search-result-meta';
      details.textContent = [
        referenceId,
        String(entry.facility || '').trim(),
        service,
        subservice,
        respondent,
      ].filter(Boolean).join(' · ');
      button.appendChild(details);

      const textFields = [entry.feedback, entry.suggestions, entry.appreciation]
        .map(value => String(value || '').trim())
        .filter(Boolean);
      const normalizedQuery = query.toLowerCase();
      const preview = textFields.find(value => value.toLowerCase().includes(normalizedQuery)) || textFields[0];
      if (preview) {
        const snippet = document.createElement('span');
        snippet.className = 'global-search-result-preview';
        snippet.textContent = preview.length > 180 ? `${preview.slice(0, 180)}...` : preview;
        button.appendChild(snippet);
      }

      list.appendChild(button);
    });
    results.appendChild(list);
    results.hidden = false;
    input.setAttribute('aria-expanded', 'true');
  }

  function initializeGlobalSearch() {
    const input = getEl('globalSearch');
    const results = getEl('globalSearchResults');
    const wrapper = input && input.closest('.global-search-wrap');
    if (!input || !results || !wrapper) return;

    input.addEventListener('input', () => window.handleSearch(input.value));
    input.addEventListener('keydown', (event) => {
      if (event.key === 'Escape' && !results.hidden) {
        event.preventDefault();
        event.stopPropagation();
        dismissGlobalSearch();
      } else if (event.key === 'Enter' && !results.hidden) {
        const firstResult = results.querySelector('.global-search-result');
        if (firstResult) {
          event.preventDefault();
          firstResult.click();
        }
      }
    });
    results.addEventListener('click', (event) => {
      const result = event.target.closest && event.target.closest('.global-search-result[data-reference-id]');
      if (!result) return;
      const referenceId = result.dataset.referenceId;
      dismissGlobalSearch();
      input.value = '';
      if (typeof window.viewFeedback === 'function') {
        window.viewFeedback(referenceId);
      } else {
        AdminUI.toast('Unable to open feedback details.', 'error');
      }
    });
    document.addEventListener('click', (event) => {
      if (!wrapper.contains(event.target) && !results.hidden) dismissGlobalSearch();
    });
  }

  window.handleSearch = function (query) {
    const input = getEl('globalSearch');
    if (!input) return;

    const searchTerm = String(query || '').trim();
    if (globalSearchTimer !== null) {
      window.clearTimeout(globalSearchTimer);
      globalSearchTimer = null;
    }
    if (globalSearchController) {
      globalSearchController.abort();
      globalSearchController = null;
    }
    const requestId = ++globalSearchRequestId;
    if (!searchTerm) {
      dismissGlobalSearch();
      return;
    }

    input.setAttribute('aria-busy', 'true');
    renderGlobalSearchMessage('Searching...', true);
    globalSearchTimer = window.setTimeout(async () => {
      globalSearchTimer = null;
      const controller = new AbortController();
      globalSearchController = controller;
      try {
        const result = await service.searchFeedback(searchTerm, { signal: controller.signal });
        if (requestId !== globalSearchRequestId) return;
        renderGlobalSearchResults(result.entries, result.totalMatches, searchTerm);
      } catch (error) {
        if (controller.signal.aborted || requestId !== globalSearchRequestId) return;
        renderGlobalSearchMessage('Search failed. Please try again.');
        AdminUI.toast(error.message || 'Unable to search feedback.', 'error');
      } finally {
        if (requestId === globalSearchRequestId) {
          input.setAttribute('aria-busy', 'false');
          globalSearchController = null;
        }
      }
    }, 250);
  };

  initializeGlobalSearch();
  initializeDashboardSearches();

  window.updateStatus = async function (refId, status) {
    if (!refId) return AdminUI.toast('No reference ID.', 'error');
    const normalized = formatters.normalizeStatus(status);
    const label = normalized.replace(/_/g, ' ').replace(/\b\w/g, char => char.toUpperCase());
    if (!(await AdminUI.confirm({
      title: 'Change feedback status?',
      message: `Change feedback ${refId} to ${label}?`,
      confirmLabel: 'Change status',
    }))) return;
    try {
      await service.updateFeedback(refId, normalized, {});
      store.markDirty();
      await refreshAndRender(true);
      AdminUI.toast(`Feedback ${refId} is now ${label}.`, 'success');
    } catch (error) {
      AdminUI.toast(error.message || 'Failed to update status.', 'error');
    }
  };

  window.deleteFeedback = async function (refId) {
    if (!refId) return AdminUI.toast('No reference ID.', 'error');
    if (!(await AdminUI.confirm({
      title: 'Delete feedback permanently?',
      message: `Delete feedback ${refId}? This cannot be undone.`,
      confirmLabel: 'Delete feedback',
      tone: 'danger',
    }))) return;
    try {
      await service.deleteFeedback(refId);
      store.markDirty();
      await refreshAndRender(true);
      AdminUI.toast(`Feedback ${refId} was deleted.`, 'success');
    } catch (error) {
      AdminUI.toast(error.message || 'Failed to delete.', 'error');
    }
  };

})();
