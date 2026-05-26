/**
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import { ref } from 'vue';

import ProductTabs from '../ProductTabs.vue';
import { knotMarketplaceBlockContextKey } from '../contextKey';

vi.mock('@/lib/marketplaceTrack', () => ({
  trackMarketplaceEvent: vi.fn(),
}));

function mountTabs(tabs: Array<Record<string, unknown>>) {
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    messages: {
      en: {
        marketplace: {
          productTabsAria: 'Tabs',
          featuresTitle: 'Features',
          richTextTitle: 'Details',
          galleryTitle: 'Gallery',
          changelogTitle: 'Changelog',
        },
      },
    },
  });

  return mount(ProductTabs, {
    props: { tabs, productSlug: 'knot-pro-pack' },
    global: {
      plugins: [i18n],
      provide: {
        [knotMarketplaceBlockContextKey as symbol]: {
          route: ref({ kind: 'product', slug: 'knot-pro-pack' }),
        },
      },
    },
  });
}

describe('ProductTabs', () => {
  it('hides tabs with visible=false', () => {
    const wrapper = mountTabs([
      { id: 'overview', kind: 'richtext', label: 'Overview', visible: true, body: 'Hello' },
      { id: 'hidden', kind: 'richtext', label: 'Hidden', visible: false, body: 'Nope' },
    ]);
    expect(wrapper.findAll('[role="tab"]').length).toBe(1);
  });

  it('syncs active tab from ?tab= query on mount', async () => {
    window.history.replaceState({}, '', '/?tab=features');
    const wrapper = mountTabs([
      { id: 'overview', kind: 'richtext', label: 'Overview', visible: true, body: 'A' },
      { id: 'features', kind: 'features', label: 'Features', visible: true, items: [] },
    ]);
    await wrapper.vm.$nextTick();
    expect(wrapper.find('#tab-features').attributes('aria-selected')).toBe('true');
    window.history.replaceState({}, '', '/');
  });
});
