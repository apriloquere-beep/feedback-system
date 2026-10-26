(function () {
  'use strict';

  let toastRegion = null;
  let confirmOverlay = null;
  let confirmResolver = null;
  let previousFocus = null;

  function ensureToastRegion() {
    if (toastRegion) return toastRegion;
    toastRegion = document.createElement('div');
    toastRegion.className = 'admin-toast-region';
    toastRegion.setAttribute('aria-label', 'Notifications');
    document.body.appendChild(toastRegion);
    return toastRegion;
  }

  function toast(message, type = 'info') {
    const safeType = ['success', 'error', 'info', 'warning'].includes(type) ? type : 'info';
    const item = document.createElement('div');
    item.className = `admin-toast admin-toast-${safeType}`;
    item.setAttribute('role', safeType === 'error' ? 'alert' : 'status');
    item.setAttribute('aria-live', safeType === 'error' ? 'assertive' : 'polite');

    const icon = document.createElement('span');
    icon.className = 'admin-toast-icon';
    icon.setAttribute('aria-hidden', 'true');
    icon.textContent = { success: '✓', error: '!', warning: '!', info: 'i' }[safeType];

    const copy = document.createElement('span');
    copy.className = 'admin-toast-message';
    copy.textContent = String(message || 'Something went wrong.');

    const close = document.createElement('button');
    close.type = 'button';
    close.className = 'admin-toast-close';
    close.setAttribute('aria-label', 'Dismiss notification');
    close.textContent = '×';

    let timer = null;
    const dismiss = () => {
      if (timer) window.clearTimeout(timer);
      item.classList.add('is-leaving');
      window.setTimeout(() => item.remove(), 180);
    };
    close.addEventListener('click', dismiss);
    item.append(icon, copy, close);
    ensureToastRegion().appendChild(item);
    window.requestAnimationFrame(() => item.classList.add('is-visible'));
    timer = window.setTimeout(dismiss, safeType === 'error' ? 7000 : 4500);
    return item;
  }

  function ensureConfirmation() {
    if (confirmOverlay) return confirmOverlay;
    confirmOverlay = document.createElement('div');
    confirmOverlay.className = 'admin-confirm-overlay';
    confirmOverlay.hidden = true;
    confirmOverlay.innerHTML = `
      <section class="admin-confirm-dialog" role="dialog" aria-modal="true" aria-labelledby="adminConfirmTitle" aria-describedby="adminConfirmMessage">
        <div class="admin-confirm-icon" aria-hidden="true">!</div>
        <div class="admin-confirm-copy">
          <h2 id="adminConfirmTitle"></h2>
          <p id="adminConfirmMessage"></p>
        </div>
        <div class="admin-confirm-actions">
          <button type="button" class="btn-secondary admin-confirm-cancel">Cancel</button>
          <button type="button" class="btn-primary admin-confirm-accept"></button>
        </div>
      </section>`;
    document.body.appendChild(confirmOverlay);

    confirmOverlay.querySelector('.admin-confirm-cancel').addEventListener('click', () => closeConfirmation(false));
    confirmOverlay.querySelector('.admin-confirm-accept').addEventListener('click', () => closeConfirmation(true));
    confirmOverlay.addEventListener('click', event => {
      if (event.target === confirmOverlay) closeConfirmation(false);
    });
    document.addEventListener('keydown', event => {
      if (confirmOverlay.hidden) return;
      if (event.key === 'Escape') {
        event.preventDefault();
        closeConfirmation(false);
        return;
      }
      if (event.key !== 'Tab') return;
      const controls = Array.from(confirmOverlay.querySelectorAll('button:not([disabled])'));
      if (!controls.length) return;
      const first = controls[0];
      const last = controls[controls.length - 1];
      if (event.shiftKey && document.activeElement === first) {
        event.preventDefault();
        last.focus();
      } else if (!event.shiftKey && document.activeElement === last) {
        event.preventDefault();
        first.focus();
      }
    });
    return confirmOverlay;
  }

  function closeConfirmation(result) {
    if (!confirmOverlay || confirmOverlay.hidden) return;
    confirmOverlay.classList.remove('is-open');
    confirmOverlay.hidden = true;
    document.body.classList.remove('admin-confirm-open');
    const resolver = confirmResolver;
    confirmResolver = null;
    if (previousFocus && typeof previousFocus.focus === 'function') previousFocus.focus();
    previousFocus = null;
    if (resolver) resolver(result);
  }

  function confirmAction(options = {}) {
    if (confirmResolver) closeConfirmation(false);
    const overlay = ensureConfirmation();
    const tone = options.tone === 'danger' ? 'danger' : 'warning';
    overlay.dataset.tone = tone;
    overlay.querySelector('#adminConfirmTitle').textContent = options.title || 'Confirm action';
    overlay.querySelector('#adminConfirmMessage').textContent = options.message || 'Are you sure you want to continue?';
    const accept = overlay.querySelector('.admin-confirm-accept');
    accept.textContent = options.confirmLabel || 'Continue';
    accept.classList.toggle('is-danger', tone === 'danger');
    previousFocus = document.activeElement;
    overlay.hidden = false;
    document.body.classList.add('admin-confirm-open');
    window.requestAnimationFrame(() => overlay.classList.add('is-open'));
    window.setTimeout(() => overlay.querySelector('.admin-confirm-cancel').focus(), 0);
    return new Promise(resolve => { confirmResolver = resolve; });
  }

  function clearFieldError(input) {
    if (!input) return;
    const errorId = input.dataset.adminErrorId;
    if (errorId) document.getElementById(errorId)?.remove();
    input.removeAttribute('aria-invalid');
    if (input.getAttribute('aria-describedby') === errorId) input.removeAttribute('aria-describedby');
    delete input.dataset.adminErrorId;
    input.classList.remove('admin-field-invalid');
  }

  function setFieldError(input, message) {
    if (!input) return;
    clearFieldError(input);
    const id = `${input.id || 'adminField'}Error`;
    const error = document.createElement('p');
    error.id = id;
    error.className = 'admin-field-error';
    error.setAttribute('role', 'alert');
    error.textContent = message;
    input.dataset.adminErrorId = id;
    input.setAttribute('aria-invalid', 'true');
    input.setAttribute('aria-describedby', id);
    input.classList.add('admin-field-invalid');
    input.insertAdjacentElement('afterend', error);
  }

  document.addEventListener('input', event => {
    if (event.target?.matches?.('[data-admin-error-id]')) clearFieldError(event.target);
  });
  document.addEventListener('change', event => {
    if (event.target?.matches?.('[data-admin-error-id]')) clearFieldError(event.target);
  });

  window.AdminUI = Object.freeze({ toast, confirm: confirmAction, setFieldError, clearFieldError });
})();
