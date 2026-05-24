import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import KHero from '../KHero.vue';

describe('KHero', () => {
  it('renders the title and subtitle', () => {
    const wrapper = mount(KHero, {
      props: {
        title: 'Hello world',
        subtitle: 'Subline.',
      },
    });
    expect(wrapper.text()).toContain('Hello world');
    expect(wrapper.text()).toContain('Subline.');
  });

  it('wraps the highlight phrase with the gradient text-clip span', () => {
    const wrapper = mount(KHero, {
      props: {
        title: 'Orchestre tes workflows Dolibarr — visuellement.',
        highlight: 'visuellement.',
      },
    });
    const highlight = wrapper.find('.k-bg-clip-text');
    expect(highlight.exists()).toBe(true);
    expect(highlight.text()).toBe('visuellement.');
  });

  it('renders pill text and emoji when provided', () => {
    const wrapper = mount(KHero, {
      props: {
        title: 'X',
        pill: 'Bêta privée',
        pillEmoji: '🚀',
      },
    });
    expect(wrapper.text()).toContain('🚀');
    expect(wrapper.text()).toContain('Bêta privée');
  });

  it('exposes named slots actions, aside and right', () => {
    const wrapper = mount(KHero, {
      props: { title: 'X' },
      slots: {
        actions: '<button class="cta">Go</button>',
        right: '<span class="ext">refresh</span>',
      },
    });
    expect(wrapper.find('.cta').exists()).toBe(true);
    expect(wrapper.find('.ext').exists()).toBe(true);
  });
});
