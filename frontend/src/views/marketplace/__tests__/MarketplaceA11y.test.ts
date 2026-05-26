/**
 * Marketplace editorial primitives — axe smoke (Vitest + jsdom).
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import { axe } from 'vitest-axe';

import HeroBlock from '../blocks/HeroBlock.vue';
import FaqBlock from '../blocks/FaqBlock.vue';

describe('Marketplace a11y (axe)', () => {
  it('home-style Hero + FAQ snapshot has no critical violations', async () => {
    const i18n = createI18n({
      legacy: false,
      locale: 'en',
      messages: {
        en: {
          marketplace: {
            title: 'Marketplace',
            subtitle: 'Discover packs and templates',
            ctaBlockButton: 'Learn more',
            faqTitle: 'Questions',
            faqEmpty: 'No questions yet',
            editorialUntitled: 'Untitled',
          },
        },
      },
    });

    const wrapper = mount(
      {
        components: { HeroBlock, FaqBlock },
        template: `
          <div class="marketplace-a11y-fixture k-space-y-4">
            <HeroBlock title="Knot Marketplace" subtitle="Editorial preview" />
            <FaqBlock
              title="Common questions"
              :items="[
                { id: 'a', question: 'What is Knot?', answer: 'A Dolibarr workflow module.' },
                { id: 'b', question: 'Is data local?', answer: 'Yes, self-hosted.' },
              ]"
            />
          </div>
        `,
      },
      {
        attachTo: document.body,
        global: { plugins: [i18n] },
      },
    );

    const node = wrapper.element as HTMLElement;
    const results = await axe(node, {
      rules: {
        'color-contrast': { enabled: false },
      },
    });

    expect(results).toHaveNoViolations();
    wrapper.unmount();
  });
});
