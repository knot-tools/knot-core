/**
 * Host leftnav sync for extension children (ADR unified sidebar option B).
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 *
 * - Marks active child from location.hash
 * - Applies journey / nav badges registered via KnotCore.navigationBadges
 */

export type KnotNavChildBadgeState = 'signed' | 'in_progress' | 'locked' | 'upcoming' | 'idle';

export interface KnotNavChildBadge {
  state: KnotNavChildBadgeState;
  stepNumber?: number;
}

export type KnotNavBadgesProvider = () => Record<string, KnotNavChildBadge>;

const providers = new Map<string, KnotNavBadgesProvider>();
const defaultIconHtml = new WeakMap<HTMLElement, string>();

function normalizeHash(raw: string): string {
  let h = raw.trim();
  if (h === '' || h === '#') {
    return '#/';
  }
  if (!h.startsWith('#')) {
    h = `#${h}`;
  }
  const q = h.indexOf('?');
  if (q >= 0) {
    h = h.slice(0, q);
  }
  if (h === '#') {
    return '#/';
  }
  return h.toLowerCase();
}

export function syncExtensionNavActive(doc: Document = document): void {
  const current = normalizeHash(doc.defaultView?.location.hash ?? '#/');
  const children = doc.querySelectorAll<HTMLAnchorElement>('[data-knot-ext-nav-child]');
  children.forEach((el) => {
    // Native Core children leave hash empty — PHP owns is-active via ?mode=.
    // normalizeHash('') => '#/' would mark EVERY empty-hash child active.
    const rawHash = (el.getAttribute('data-knot-ext-nav-hash') ?? '').trim();
    if (rawHash === '' || rawHash === '#') {
      return;
    }
    const hash = normalizeHash(rawHash);
    const active = current === hash || current.startsWith(`${hash}/`);
    el.classList.toggle('is-active', active);
    if (active) {
      el.setAttribute('aria-current', 'page');
    } else {
      el.removeAttribute('aria-current');
    }
  });
}

function renderBadgeIcon(badge: KnotNavChildBadge): string {
  if (badge.state === 'signed') {
    return '<span aria-hidden="true">✓</span>';
  }
  if (badge.state === 'in_progress') {
    const n = badge.stepNumber && badge.stepNumber > 0 ? String(badge.stepNumber) : '•';
    return `<span aria-hidden="true">${n}</span>`;
  }
  if (badge.state === 'locked') {
    return '<i class="fas fa-lock" aria-hidden="true"></i>';
  }
  if (badge.state === 'upcoming' && badge.stepNumber && badge.stepNumber > 0) {
    return `<span aria-hidden="true">${badge.stepNumber}</span>`;
  }
  return '';
}

export function applyExtensionNavBadges(doc: Document = document): void {
  providers.forEach((provider, extensionId) => {
    let badges: Record<string, KnotNavChildBadge> = {};
    try {
      badges = provider() ?? {};
    } catch {
      badges = {};
    }
    const root = doc.querySelector(`[data-knot-ext-nav="${extensionId.replace(/"/g, '')}"]`);
    if (!root) {
      return;
    }
    root.querySelectorAll<HTMLAnchorElement>('[data-knot-ext-nav-key]').forEach((el) => {
      const navKey = el.getAttribute('data-knot-ext-nav-key') ?? '';
      const iconEl = el.querySelector<HTMLElement>('[data-knot-ext-nav-icon]');
      if (!iconEl) {
        return;
      }
      if (!defaultIconHtml.has(iconEl)) {
        defaultIconHtml.set(iconEl, iconEl.innerHTML);
      }
      const badge = badges[navKey];
      if (!badge || badge.state === 'idle') {
        el.removeAttribute('data-knot-nav-state');
        iconEl.innerHTML = defaultIconHtml.get(iconEl) ?? iconEl.innerHTML;
        return;
      }
      el.setAttribute('data-knot-nav-state', badge.state);
      const html = renderBadgeIcon(badge);
      if (html !== '') {
        iconEl.innerHTML = html;
      } else {
        iconEl.innerHTML = defaultIconHtml.get(iconEl) ?? iconEl.innerHTML;
      }
    });
  });
}

export function refreshExtensionHostNav(doc: Document = document): void {
  syncExtensionNavActive(doc);
  applyExtensionNavBadges(doc);
}

export function createNavigationBadgesApi(target: Window = window): {
  register: (extensionId: string, provider: KnotNavBadgesProvider) => () => void;
  notify: () => void;
} {
  const notify = (): void => {
    refreshExtensionHostNav(target.document);
    target.dispatchEvent(new CustomEvent('knot:navigation-badges-updated'));
  };

  return {
    register(extensionId: string, provider: KnotNavBadgesProvider): () => void {
      if (extensionId.trim() === '' || typeof provider !== 'function') {
        return () => undefined;
      }
      providers.set(extensionId, provider);
      notify();
      return () => {
        if (providers.get(extensionId) === provider) {
          providers.delete(extensionId);
          notify();
        }
      };
    },
    notify,
  };
}

/** Boot host nav listeners once (hash + load). Idempotent. */
export function installExtensionHostNav(target: Window = window): void {
  const w = target as Window & { __knotExtHostNavInstalled?: boolean };
  if (w.__knotExtHostNavInstalled) {
    refreshExtensionHostNav(target.document);
    return;
  }
  w.__knotExtHostNavInstalled = true;
  const onChange = (): void => refreshExtensionHostNav(target.document);
  target.addEventListener('hashchange', onChange);
  target.addEventListener('popstate', onChange);
  if (target.document.readyState === 'loading') {
    target.document.addEventListener('DOMContentLoaded', onChange, { once: true });
  } else {
    onChange();
  }
}
