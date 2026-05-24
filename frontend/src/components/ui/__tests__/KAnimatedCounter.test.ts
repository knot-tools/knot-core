import { mount, flushPromises } from '@vue/test-utils';
import { describe, expect, it, beforeEach, vi } from 'vitest';
import KAnimatedCounter from '../KAnimatedCounter.vue';

describe('KAnimatedCounter', () => {
  beforeEach(() => {
    // Force reduced-motion so the counter snaps to its final value
    // and tests stay deterministic without faking RAF.
    Object.defineProperty(window, 'matchMedia', {
      writable: true,
      value: vi.fn().mockImplementation((query: string) => ({
        matches: query.includes('reduce'),
        media: query,
        onchange: null,
        addEventListener: vi.fn(),
        removeEventListener: vi.fn(),
        addListener: vi.fn(),
        removeListener: vi.fn(),
        dispatchEvent: vi.fn(),
      })),
    });
  });

  it('renders the value formatted with thousand separators', async () => {
    const wrapper = mount(KAnimatedCounter, { props: { value: 1234 } });
    await flushPromises();
    expect(wrapper.text()).toMatch(/1[,.\s\u202F]?234/);
  });

  it('honours decimals and suffix', async () => {
    const wrapper = mount(KAnimatedCounter, {
      props: { value: 0.97, decimals: 2, suffix: ' %' },
    });
    await flushPromises();
    expect(wrapper.text()).toContain('0.97');
    expect(wrapper.text()).toContain('%');
  });

  it('honours prefix', async () => {
    const wrapper = mount(KAnimatedCounter, {
      props: { value: 199, prefix: '€ ' },
    });
    await flushPromises();
    expect(wrapper.text()).toMatch(/€\s?199/);
  });

  it('exposes the target value via aria-label for screen readers', () => {
    const wrapper = mount(KAnimatedCounter, {
      props: { value: 42, suffix: ' workflows' },
    });
    expect(wrapper.attributes('aria-label')).toBe('42 workflows');
  });
});
