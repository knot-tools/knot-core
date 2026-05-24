(function () {
  'use strict';

  window.Knot = window.Knot || {};
  window.Knot.version = window.KNOT_VERSION || '2.0.0';
  window.Knot.ready = true;

  var NAV_COLLAPSE_KEY = 'knot.nav.collapsed';

  function initNavCollapse() {
    var nav = document.querySelector('.knot-nav');
    if (!nav) {
      return;
    }

    var collapsed = false;
    try {
      collapsed = window.localStorage.getItem(NAV_COLLAPSE_KEY) === '1';
    } catch (e) {
      collapsed = false;
    }

    function apply(state) {
      document.body.classList.toggle('knot-nav-collapsed', state);
      nav.classList.toggle('is-collapsed', state);
      var toggle = nav.querySelector('[data-knot-nav-collapse]');
      if (!toggle) {
        return;
      }
      toggle.setAttribute('aria-expanded', state ? 'false' : 'true');
      var icon = toggle.querySelector('i');
      if (icon) {
        icon.className = state ? 'fas fa-chevron-right' : 'fas fa-chevron-left';
      }
    }

    apply(collapsed);

    var toggleBtn = nav.querySelector('[data-knot-nav-collapse]');
    if (toggleBtn) {
      toggleBtn.addEventListener('click', function () {
        collapsed = !collapsed;
        try {
          window.localStorage.setItem(NAV_COLLAPSE_KEY, collapsed ? '1' : '0');
        } catch (e) {
          // private mode — still toggle for the session
        }
        apply(collapsed);
      });
    }
  }

  function refreshInboxBadge() {
    var apiBase = window.KNOT_API_BASE;
    if (!apiBase) return;
    fetch(apiBase + '/approvals.php', { credentials: 'same-origin' })
      .then(function (r) { return r.ok ? r.json() : null; })
      .then(function (data) {
        if (!data || !data.success) return;
        var pending = (data.data && Array.isArray(data.data.approvals))
          ? data.data.approvals.filter(function (a) { return a.status === 'pending'; }).length
          : 0;
        var inboxLink = document.querySelector('.knot-nav__item[href*="mode=inbox"]');
        if (!inboxLink) return;
        var existing = inboxLink.querySelector('.knot-nav__badge');
        if (pending <= 0) {
          if (existing) existing.remove();
          return;
        }
        if (!existing) {
          existing = document.createElement('span');
          existing.className = 'knot-nav__badge';
          existing.style.cssText = 'margin-left:auto;background:#ef4444;color:#fff;border-radius:9999px;padding:2px 7px;font-size:10px;font-weight:700;line-height:1;';
          inboxLink.appendChild(existing);
        }
        existing.textContent = pending > 99 ? '99+' : String(pending);
      })
      .catch(function () { /* silent */ });
  }

  function onReady() {
    initNavCollapse();
    refreshInboxBadge();
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', onReady);
  } else {
    onReady();
  }
  setInterval(refreshInboxBadge, 30000);
})();
