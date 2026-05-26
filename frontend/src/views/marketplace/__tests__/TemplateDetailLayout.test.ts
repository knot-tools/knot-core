/**
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import { ref } from 'vue';

import TemplateDetailLayout from '../TemplateDetailLayout.vue';
import { knotMarketplaceBlockContextKey } from '../contextKey';

vi.mock('@/lib/marketplaceTrack', () => ({
  trackMarketplaceEvent: vi.fn(),
}));

vi.mock('@/lib/api', () => ({
  knotApi: { instantiateTemplate: vi.fn() },
}));

describe('TemplateDetailLayout', () => {
  it('renders template hero and use CTA', () => {
    const i18n = createI18n({
      legacy: false,
      locale: 'en',
      messages: {
        en: {
          marketplace: {
            useTemplate: 'Use',
            templateBlockEmpty: 'Empty',
            proBadgeLabel: 'Pro',
            enterpriseBadgeLabel: 'Enterprise',
            productTabsAria: 'Tabs',
            tabOverview: 'Overview',
            richTextTitle: 'Details',
            miniPreviewLegend: '{nodes} nodes · {edges} links',
            miniPreviewHint: 'Hint',
          },
        },
      },
    });

    const wrapper = mount(TemplateDetailLayout, {
      global: {
        plugins: [i18n],
        provide: {
          [knotMarketplaceBlockContextKey as symbol]: {
            route: ref({ kind: 'template', slug: 'invoice-reminder' }),
            marketplace: ref({
              packs: [],
              templates: [{
                slug: 'invoice-reminder',
                label: 'Invoice reminder',
                description: 'Send reminders',
                category: 'billing',
                tier: 'free',
                locked: false,
                definition: { nodes: [{ id: '1' }], edges: [] },
              }],
              editorial: { templatePages: {} },
            }),
          },
        },
      },
    });

    expect(wrapper.text()).toContain('Invoice reminder');
    expect(wrapper.text()).toContain('Use');
  });
});
