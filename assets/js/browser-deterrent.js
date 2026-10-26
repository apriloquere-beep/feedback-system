(function () {
    'use strict';

    var warningId = 'sfms-devtools-warning';
    var warningVisible = false;

    function isEditableTarget(target) {
        if (!target) {
            return false;
        }

        var tagName = target.tagName ? target.tagName.toLowerCase() : '';
        return tagName === 'input' || tagName === 'textarea' || tagName === 'select' || target.isContentEditable;
    }

    function blockDevToolsShortcuts(event) {
        var key = (event.key || '').toLowerCase();
        var isDevToolsShortcut = key === 'f12'
            || (event.ctrlKey && event.shiftKey && (key === 'i' || key === 'j' || key === 'c'))
            || (event.metaKey && event.altKey && (key === 'i' || key === 'j' || key === 'c'));

        if (isDevToolsShortcut) {
            event.preventDefault();
            event.stopPropagation();
        }
    }

    function protectContextMenu(event) {
        if (!isEditableTarget(event.target)) {
            event.preventDefault();
        }
    }

    function addWarningStyles() {
        if (document.getElementById('sfms-devtools-warning-styles')) {
            return;
        }

        var style = document.createElement('style');
        style.id = 'sfms-devtools-warning-styles';
        style.textContent = '#sfms-devtools-warning{position:fixed;z-index:2147483647;left:16px;right:16px;bottom:16px;display:flex;align-items:center;gap:12px;padding:12px 14px;border:1px solid #f59e0b;border-radius:8px;background:#fffbeb;color:#78350f;box-shadow:0 8px 24px rgba(15,23,42,.2);font:600 14px/1.4 system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif}#sfms-devtools-warning button{margin-left:auto;border:1px solid #d97706;border-radius:6px;padding:4px 9px;background:#fff;color:#92400e;font:inherit;cursor:pointer}#sfms-devtools-warning button:focus-visible{outline:2px solid #92400e;outline-offset:2px}@media(max-width:600px){#sfms-devtools-warning{left:8px;right:8px;bottom:8px;font-size:13px}}';
        document.head.appendChild(style);
    }

    function showWarning() {
        if (warningVisible || document.getElementById(warningId)) {
            return;
        }

        addWarningStyles();

        var warning = document.createElement('div');
        warning.id = warningId;
        warning.setAttribute('role', 'alert');
        warning.innerHTML = '<span>Developer tools detected. Browser restrictions are active; server-side security still protects this system.</span><button type="button" aria-label="Dismiss developer tools warning">Dismiss</button>';
        warning.querySelector('button').addEventListener('click', function () {
            warning.remove();
            warningVisible = false;
        });
        document.body.appendChild(warning);
        warningVisible = true;
    }

    function devToolsLikelyOpen() {
        var desktopViewport = window.innerWidth > 768 && window.matchMedia('(pointer:fine)').matches;
        var widthGap = window.outerWidth - window.innerWidth;
        var heightGap = window.outerHeight - window.innerHeight;
        return desktopViewport && (widthGap > 160 || heightGap > 160);
    }

    function monitorDevTools() {
        if (devToolsLikelyOpen()) {
            showWarning();
        }
    }

    document.addEventListener('keydown', blockDevToolsShortcuts, true);
    document.addEventListener('contextmenu', protectContextMenu, true);
    window.addEventListener('resize', monitorDevTools);
    window.setInterval(monitorDevTools, 1500);
    monitorDevTools();
})();
