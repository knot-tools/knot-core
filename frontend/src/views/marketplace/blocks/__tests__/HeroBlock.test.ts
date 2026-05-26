/**
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { describe, expect, it, afterEach, beforeEach, vi } from 'vitest';
import { mount, flushPromises } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';

import HeroBlock from '../HeroBlock.vue';

function mountHero(props: Record<string, unknown> = {}) {
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    messages: {
      en: {
        marketplace: {
          title: 'Marketplace',
          subtitle: 'Sub',
          ctaBlockButton: 'Go',
        },
      },
    },
  });
  return mount(HeroBlock, {
    props,
    global: { plugins: [i18n] },
  });
}

describe('HeroBlock', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    vi.setSystemTime(new Date('2026-06-15T12:00:00.000Z'));
  });

  afterEach(() => {
    vi.useRealTimers();
  });

  it('picks the hero entry whose period contains the current instant', async () => {
    const wrapper = mountHero({
      heroes: [
        {
          title: 'Winter',
          period: { from: '2026-01-01T00:00:00Z', until: '2026-03-01T00:00:00Z' },
        },
        {
          title: 'Summer',
          period: { from: '2026-05-01T00:00:00Z', until: '2026-08-01T00:00:00Z' },
        },
      ],
    });
    await flushPromises();
    expect(wrapper.get('h1').text()).toContain('Summer');
  });

  it('falls back to the first timeless hero when no period matches', async () => {
    const wrapper = mountHero({
      heroes: [
        { title: 'Evergreen' },
        {
          title: 'Expired',
          period: { until: '2020-01-01T00:00:00Z' },
        },
      ],
    });
    await flushPromises();
    expect(wrapper.get('h1').text()).toContain('Evergreen');
  });
});
