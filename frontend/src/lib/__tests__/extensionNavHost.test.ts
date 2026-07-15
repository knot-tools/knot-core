/**
 * Host extension nav sync unit tests.
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { afterEach, describe, expect, it } from 'vitest';
import {
  applyExtensionNavBadges,
  createNavigationBadgesApi,
  syncExtensionNavActive,
} from '../extensionNavHost';

describe('extensionNavHost', () => {
  afterEach(() => {
    document.body.innerHTML = '';
    window.location.hash = '';
  });

  it('marks the child matching location.hash as active', () => {
    document.body.innerHTML = `
      <div data-knot-ext-nav="knot-migration">
        <a data-knot-ext-nav-child="a" data-knot-ext-nav-key="discovery" data-knot-ext-nav-hash="#/discovery"></a>
        <a data-knot-ext-nav-child="b" data-knot-ext-nav-key="cutover" data-knot-ext-nav-hash="#/cutover"></a>
      </div>
    `;
    window.location.hash = '#/cutover';
    syncExtensionNavActive(document);
    expect(document.querySelector('[data-knot-ext-nav-key="cutover"]')?.classList.contains('is-active')).toBe(true);
    expect(document.querySelector('[data-knot-ext-nav-key="discovery"]')?.classList.contains('is-active')).toBe(false);
  });

  it('does not force-active Core children with empty hash attribute', () => {
    document.body.innerHTML = `
      <div data-knot-ext-nav="knot-core">
        <a class="is-active" data-knot-ext-nav-child="c1" data-knot-ext-nav-key="dashboard" data-knot-ext-nav-hash=""></a>
        <a data-knot-ext-nav-child="c2" data-knot-ext-nav-key="workflows" data-knot-ext-nav-hash=""></a>
      </div>
    `;
    window.location.hash = '#/';
    syncExtensionNavActive(document);
    expect(document.querySelector('[data-knot-ext-nav-key="dashboard"]')?.classList.contains('is-active')).toBe(true);
    expect(document.querySelector('[data-knot-ext-nav-key="workflows"]')?.classList.contains('is-active')).toBe(false);
  });

  it('paints signed / in_progress badges from a registered provider', () => {
    document.body.innerHTML = `
      <div data-knot-ext-nav="knot-migration">
        <a data-knot-ext-nav-key="discovery">
          <span data-knot-ext-nav-icon><i class="fas fa-compass"></i></span>
        </a>
        <a data-knot-ext-nav-key="cutover">
          <span data-knot-ext-nav-icon><i class="fas fa-exchange-alt"></i></span>
        </a>
      </div>
    `;
    const api = createNavigationBadgesApi(window);
    api.register('knot-migration', () => ({
      discovery: { state: 'signed' },
      cutover: { state: 'in_progress', stepNumber: 3 },
    }));
    applyExtensionNavBadges(document);
    expect(document.querySelector('[data-knot-ext-nav-key="discovery"]')?.getAttribute('data-knot-nav-state')).toBe('signed');
    expect(document.querySelector('[data-knot-ext-nav-key="discovery"] [data-knot-ext-nav-icon]')?.textContent).toContain('✓');
    expect(document.querySelector('[data-knot-ext-nav-key="cutover"]')?.getAttribute('data-knot-nav-state')).toBe('in_progress');
    expect(document.querySelector('[data-knot-ext-nav-key="cutover"] [data-knot-ext-nav-icon]')?.textContent).toContain('3');
  });
});
