/**
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import { ref } from 'vue';

import MarketplaceRelatedRow from '../MarketplaceRelatedRow.vue';
import { knotMarketplaceBlockContextKey } from '../contextKey';

describe('MarketplaceRelatedRow', () => {
  it('emits preview in preview mode', async () => {
    const openDrawerPreview = vi.fn();
    const i18n = createI18n({
      legacy: false,
      locale: 'en',
      messages: {
        en: {
          marketplace: {
            relatedTitle: 'Related',
            relatedEmpty: 'Empty',
            relatedPreview: 'Preview',
          },
        },
      },
    });

    const wrapper = mount(MarketplaceRelatedRow, {
      props: { slugs: ['knot-pro-pack'], previewMode: true },
      global: {
        plugins: [i18n],
        provide: {
          [knotMarketplaceBlockContextKey as symbol]: {
            marketplace: ref({
              packs: [{ slug: 'knot-pro-pack', label: 'Pro Pack', description: 'Desc', tier: 'pro' }],
              templates: [],
            }),
            openDrawerPreview,
            navigate: vi.fn(),
          },
        },
      },
    });

    await wrapper.find('button').trigger('click');
    expect(openDrawerPreview).toHaveBeenCalledWith({ kind: 'product', slug: 'knot-pro-pack' });
  });
});
