import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import MarketplaceView from '../MarketplaceView.vue';

vi.mock('@/lib/api', () => ({
  knotApi: {
    marketplace: vi.fn().mockResolvedValue({
      packs: [],
      templates: [],
      packsMeta: { stale: false },
      templatesMeta: { stale: false },
    }),
    listBundledTemplates: vi.fn().mockResolvedValue([]),
    licenseStatus: vi.fn().mockResolvedValue({ extensions: [] }),
  },
}));

describe('MarketplaceView', () => {
  it('does not render a Migration Pro Pack tab', async () => {
    const i18n = createI18n({
      legacy: false,
      locale: 'en',
      messages: {
        en: {
          marketplace: {
            title: 'Marketplace',
            tabPacks: 'Packs',
            tabTemplates: 'Templates',
            tabBundled: 'Bundled',
            tabMigration: 'Pro Pack migration',
            pricingCta: 'Pricing',
            searchPlaceholder: 'Search',
          },
        },
      },
    });

    const wrapper = mount(MarketplaceView, {
      global: { plugins: [i18n] },
    });

    await vi.waitFor(() => {
      expect(wrapper.text()).toMatch(/Marketplace/i);
    });

    expect(wrapper.text()).not.toMatch(/Pro Pack migration/i);
    const tabLabels = wrapper.findAll('nav button').map((btn) => btn.text());
    expect(tabLabels.some((label) => /migration/i.test(label))).toBe(false);
  });
});
