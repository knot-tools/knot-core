/**
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';

import ProductChangelogList from '../ProductChangelogList.vue';

describe('ProductChangelogList', () => {
  it('renders timeline entries', () => {
    const i18n = createI18n({
      legacy: false,
      locale: 'en',
      messages: {
        en: { marketplace: { changelogTitle: 'Changelog', changelogEmpty: 'Empty' } },
      },
    });

    const wrapper = mount(ProductChangelogList, {
      props: {
        entries: [{ version: '2.0.0', date: '2026-01-01', notes: '**Fix** bug' }],
      },
      global: { plugins: [i18n] },
    });

    expect(wrapper.text()).toContain('2.0.0');
    expect(wrapper.html()).toContain('<strong>Fix</strong>');
  });

  it('shows empty state', () => {
    const i18n = createI18n({
      legacy: false,
      locale: 'en',
      messages: {
        en: { marketplace: { changelogTitle: 'Changelog', changelogEmpty: 'Empty' } },
      },
    });

    const wrapper = mount(ProductChangelogList, {
      props: { entries: [] },
      global: { plugins: [i18n] },
    });

    expect(wrapper.text()).toContain('Empty');
  });
});
