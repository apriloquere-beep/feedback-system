// Sends the user's language choice to the server — $_SESSION['selected_language']
// (via lang/load.php) is the single source of truth for the active language
// across the whole project — then reloads the page so every element
// re-renders server-side (lang/resolve.php + $t[]) in that language.
//
// This file intentionally does NOT decide the active language itself and
// does NOT store it anywhere client-side (no localStorage): every page load
// already arrives correctly translated from the server, so there is nothing
// for JavaScript to patch afterward.
(function () {
  const LANGUAGE_MAP = {
    english: 'english', en: 'english',
    bisaya: 'bisaya', bis: 'bisaya', ceb: 'bisaya',
    tagalog: 'tagalog', tl: 'tagalog',
  };

  // The one canonical normalization entry point on the client side, mirroring
  // normalize_selected_language() in lang/normalize.php on the server.
  function normalizeLanguage(lang) {
    return LANGUAGE_MAP[String(lang || '').toLowerCase()] || 'english';
  }

  window.setLanguage = async function (lang) {
    const canonical = normalizeLanguage(lang);
    try {
      const response = await fetch('/Feedback/lang/load.php?lang=' + encodeURIComponent(canonical), { cache: 'no-store' });
      if (response.ok) {
        // Consume the body so the request completes cleanly; the page
        // reload below is what actually applies the language, not this
        // response — lang/load.php's real effect is the session write.
        await response.json().catch(() => {});
      }
    } catch (error) {
      // Still reload even if the request failed outright — the dropdown
      // link's own href (?lang=...) is itself a valid explicit request that
      // lang/resolve.php picks up on the next page load regardless.
    }
    const url = new URL(window.location.href);
    url.searchParams.delete('lang');
    window.location.href = url.toString();
  };

  // A couple of older pages (not part of this refactor) still mark <html>
  // with a 'lang-loading' class to hide the page until client-side
  // translation finished. Nothing else clears that class anymore, so this
  // does it unconditionally on load to avoid leaving those pages hidden.
  function clearLegacyLoadingGuard() {
    document.documentElement.classList.remove('lang-loading');
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', clearLegacyLoadingGuard);
  } else {
    clearLegacyLoadingGuard();
  }
})();
