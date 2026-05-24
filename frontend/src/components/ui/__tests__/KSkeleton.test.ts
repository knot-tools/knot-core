import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import KSkeleton from '../KSkeleton.vue';

describe('KSkeleton', () => {
  it('renders with default block dimensions', () => {
    const wrapper = mount(KSkeleton);
    const el = wrapper.element as HTMLElement;
    expect(el.style.width).toBe('100%');
    expect(el.style.height).toBe('16px');
    expect(wrapper.attributes('role')).toBe('status');
  });

  it('honours custom width and height props', () => {
    const wrapper = mount(KSkeleton, {
      props: { width: '60%', height: '20px' },
    });
    const el = wrapper.element as HTMLElement;
    expect(el.style.width).toBe('60%');
    expect(el.style.height).toBe('20px');
  });

  it('uses size for circle variant', () => {
    const wrapper = mount(KSkeleton, {
      props: { variant: 'circle', size: '40px' },
    });
    const el = wrapper.element as HTMLElement;
    expect(el.style.width).toBe('40px');
    expect(el.style.height).toBe('40px');
    expect(wrapper.classes()).toContain('k-rounded-knot-pill');
  });

  it('exposes a screen-reader label', () => {
    const wrapper = mount(KSkeleton, {
      props: { label: 'Loading workflows' },
    });
    expect(wrapper.attributes('aria-label')).toBe('Loading workflows');
    expect(wrapper.attributes('aria-live')).toBe('polite');
  });
});
