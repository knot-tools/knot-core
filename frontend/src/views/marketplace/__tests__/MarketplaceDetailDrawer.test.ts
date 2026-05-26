/**
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import { ref } from 'vue';

import MarketplaceDetailDrawer from '../MarketplaceDetailDrawer.vue';
import { knotMarketplaceBlockContextKey } from '../contextKey';

vi.mock('@/lib/marketplaceTrack', () => ({
  trackMarketplaceEvent: vi.fn(),
}));

describe('MarketplaceDetailDrawer', () => {
  it('shows pack preview content when open', async () => {
    const i18n = createI18n({
      legacy: false,
      locale: 'en',
      messages: {
        en: {
          marketplace: {
            tabPacks: 'Packs',
            tabTemplates: 'Templates',
            drawerAria: 'Drawer',
            drawerClose: 'Close',
            drawerTier: 'Tier',
            drawerVersion: 'Version',
            drawerOpenFull: 'Open',
          },
        },
      },
    });

    HTMLDialogElement.prototype.showModal = vi.fn();
    HTMLDialogElement.prototype.close = vi.fn();

    const wrapper = mount(MarketplaceDetailDrawer, {
      props: {
        open: true,
        target: { kind: 'product', slug: 'knot-pro-pack' },
      },
      attachTo: document.body,
      global: {
        plugins: [i18n],
        provide: {
          [knotMarketplaceBlockContextKey as symbol]: {
            marketplace: ref({
              packs: [{
                slug: 'knot-pro-pack',
                label: 'Pro Pack',
                description: 'Advanced connectors',
                tier: 'pro',
                version: '1.0.0',
              }],
              templates: [],
            }),
            navigate: vi.fn(),
          },
        },
      },
    });

    await flushPromises();
    expect(wrapper.text()).toContain('Pro Pack');
    expect(wrapper.text()).toContain('Advanced connectors');
    wrapper.unmount();
  });
});
