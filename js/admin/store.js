(function () {
  const state = {
    entries: [],
    summary: {},
    modules: {},
    filters: {
      search: '',
      service: '',
      status: '',
      feedbackType: '',
      minRating: '',
      maxRating: '',
      dateFrom: '',
      dateTo: '',
    },
    loading: false,
    hydrated: false,
    lastLoadedAt: 0,
    lastModuleLoadedAt: 0,
    refreshPromise: null,
    modulePromise: null,
    renderCycleId: 0,
  };

  const CACHE_TTL_MS = 30 * 1000;

  function computeTrend(entries, granularity) {
    const buckets = new Map();
    entries.forEach((entry) => {
      const key = window.AdminDashboardFormatters.formatDateKey(entry.timestamp, granularity);
      buckets.set(key, (buckets.get(key) || 0) + 1);
    });

    return Array.from(buckets.entries()).map(([label, count]) => ({ label, count })).slice(-10);
  }

  function computeServiceStats(entries) {
    const map = new Map();
    entries.forEach((entry) => {
      const serviceId = entry.serviceId || null;
      const key = serviceId ? `id:${serviceId}` : 'unassigned';
      const label = entry.service || 'Unassigned';
      if (!map.has(key)) {
        map.set(key, { serviceId, label, count: 0, total: 0 });
      }

      const bucket = map.get(key);
      bucket.count += 1;
      bucket.total += Number(entry.rating || 0);
    });

    return Array.from(map.values())
      .map((item) => ({
        ...item,
        averageRating: item.count ? item.total / item.count : 0,
      }))
      .sort((left, right) => right.count - left.count || right.averageRating - left.averageRating);
  }

  function computeSummary(entries, modules = {}, apiSummary = {}) {
    const total = entries.length;
    const pending = entries.filter((entry) => entry.status === 'pending').length;
    const inReview = entries.filter((entry) => entry.status === 'in_review').length;
    const resolved = entries.filter((entry) => entry.status === 'resolved').length;
    const averageRating = total ? entries.reduce((sum, entry) => sum + Number(entry.rating || 0), 0) / total : 0;
    const resolutionRate = total ? (resolved / total) * 100 : 0;
    const sentimentCounts = { Positive: 0, Neutral: 0, Negative: 0 };
    entries.forEach((entry) => {
      if (Object.prototype.hasOwnProperty.call(sentimentCounts, entry.sentimentLabel)) {
        sentimentCounts[entry.sentimentLabel] += 1;
      }
    });
    const topComments = entries
      .filter((entry) => entry.feedback)
      .slice(0, 3)
      .map((entry) => ({
        timestamp: entry.timestamp,
        name: entry.name,
        service: entry.service,
        message: entry.feedback,
      }));

    const serviceStats = computeServiceStats(entries);
    const topService = serviceStats[0] || null;

    return {
      ...apiSummary,
      total,
      pending,
      inReview,
      resolved,
      averageRating,
      resolutionRate,
      sentimentCounts,
      trend: {
        daily: computeTrend(entries, 'daily'),
        weekly: computeTrend(entries, 'weekly'),
        monthly: computeTrend(entries, 'monthly'),
      },
      serviceStats,
      topService,
      recentComments: topComments,
      recentActivity: entries.slice(0, 5).map((entry) => ({
        timestamp: entry.timestamp,
        name: entry.name,
        service: entry.service,
        message: entry.subject || entry.feedback,
      })),
      alerts: apiSummary.alerts || [],
    };
  }

  function setLoading(isLoading) {
    state.loading = isLoading;
    const spinner = document.getElementById('loadingSpinner');
    if (spinner) {
      spinner.style.display = isLoading ? 'flex' : 'none';
    }
  }

  async function refreshFeedback(force = false) {
    if (!force && state.refreshPromise) {
      return state.refreshPromise;
    }

    if (!force && state.hydrated && (Date.now() - state.lastLoadedAt) < CACHE_TTL_MS) {
      return state;
    }

    const promise = (async () => {
      setLoading(true);
      const payload = await window.AdminDashboardService.loadDashboardData(state.filters);
      const entries = (payload.entries || []).map((entry) => window.AdminDashboardFormatters.normalizeFeedback(entry));
      state.entries = window.AdminDashboardFormatters.flagDuplicates(entries);
      state.summary = computeSummary(state.entries, state.modules, payload.summary || {});
      state.lastLoadedAt = Date.now();
      state.hydrated = true;
      state.renderCycleId += 1;
      return state;
    })().finally(() => {
      state.refreshPromise = null;
      setLoading(false);
    });

    state.refreshPromise = promise;
    return promise;
  }

  async function refreshModules(force = false) {
    if (!force && state.modulePromise) {
      return state.modulePromise;
    }

    if (!force && state.lastModuleLoadedAt && (Date.now() - state.lastModuleLoadedAt) < CACHE_TTL_MS) {
      return state.modules;
    }

    const promise = (async () => {
      const modules = await window.AdminDashboardService.loadAdminModules('all');
      state.modules = modules || {};
      state.lastModuleLoadedAt = Date.now();
      state.summary = computeSummary(state.entries, state.modules, state.summary);
      state.renderCycleId += 1;
      return state.modules;
    })().finally(() => {
      state.modulePromise = null;
    });

    state.modulePromise = promise;
    return promise;
  }

  async function refreshAll(force = false) {
    setLoading(true);
    try {
      await Promise.all([
        refreshModules(force),
        refreshFeedback(force),
      ]);
      return getSnapshot();
    } finally {
      setLoading(false);
    }
  }

  function getEntries() {
    return state.entries.slice();
  }

  function getModules() {
    return { ...state.modules };
  }

  function getSummary() {
    return { ...state.summary };
  }

  function getSnapshot() {
    return {
      entries: getEntries(),
      modules: getModules(),
      summary: getSummary(),
      filters: { ...state.filters },
      loading: state.loading,
      renderCycleId: state.renderCycleId,
    };
  }

  function setFilters(nextFilters = {}) {
    state.filters = { ...state.filters, ...nextFilters };
    return state.filters;
  }

  function resetFilters() {
    state.filters = {
      search: '',
      service: '',
      status: '',
      feedbackType: '',
      minRating: '',
      maxRating: '',
      dateFrom: '',
      dateTo: '',
    };
  }

  function markDirty() {
    state.hydrated = false;
    state.lastLoadedAt = 0;
    state.lastModuleLoadedAt = 0;
  }

  window.AdminDashboardStore = {
    state,
    setLoading,
    refreshFeedback,
    refreshModules,
    refreshAll,
    getEntries,
    getModules,
    getSummary,
    getSnapshot,
    setFilters,
    resetFilters,
    markDirty,
  };
})();
