/**
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';

import ProductScreenshotGallery from '../ProductScreenshotGallery.vue';

describe('ProductScreenshotGallery', () => {
  it('lazy-loads all but the first screenshot', () => {
    const i18n = createI18n({
      legacy: false,
      locale: 'en',
      messages: {
        en: {
          marketplace: {
            galleryTitle: 'Gallery',
            galleryEmpty: 'Empty',
            galleryImageFallbackAlt: 'Shot',
            galleryClose: 'Close',
          },
        },
      },
    });

    const wrapper = mount(ProductScreenshotGallery, {
      props: {
        items: [
          { src: 'https://cdn.knot.tools/a.png', alt: 'A' },
          { src: 'https://cdn.knot.tools/b.png', alt: 'B' },
        ],
      },
      global: { plugins: [i18n] },
    });

    const imgs = wrapper.findAll('img');
    expect(imgs[0]?.attributes('loading')).toBe('eager');
    expect(imgs[1]?.attributes('loading')).toBe('lazy');
  });
});
