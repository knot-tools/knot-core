/**
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { describe, expect, it, vi, beforeEach, afterEach } from 'vitest';
import { defineComponent, ref, nextTick } from 'vue';
import { mount } from '@vue/test-utils';
import { useLazyMount } from '../useLazyMount';

describe('useLazyMount', () => {
  beforeEach(() => {
    vi.stubGlobal(
      'IntersectionObserver',
      vi.fn(function IntersectionObserverMock(this: IntersectionObserver, cb: IntersectionObserverCallback) {
        this.observe = vi.fn();
        this.disconnect = vi.fn();
        this.unobserve = vi.fn();
        queueMicrotask(() => {
          cb([{ isIntersecting: true } as IntersectionObserverEntry], this as unknown as IntersectionObserver);
        });
      }),
    );
  });

  afterEach(() => {
    vi.unstubAllGlobals();
  });

  it('becomes visible when intersection observer fires', async () => {
    const Comp = defineComponent({
      setup() {
        const el = ref<HTMLElement | null>(null);
        const visible = useLazyMount(el);
        return { el, visible };
      },
      template: '<div ref="el">{{ visible ? "yes" : "no" }}</div>',
    });

    const wrapper = mount(Comp);
    await nextTick();
    await new Promise((resolve) => queueMicrotask(resolve));
    expect(wrapper.text()).toContain('yes');
  });

  it('disconnects observer on unmount', async () => {
    const disconnect = vi.fn();
    vi.stubGlobal(
      'IntersectionObserver',
      vi.fn(function IntersectionObserverMock(this: IntersectionObserver) {
        this.observe = vi.fn();
        this.disconnect = disconnect;
        this.unobserve = vi.fn();
      }),
    );

    const Comp = defineComponent({
      setup() {
        const el = ref<HTMLElement | null>(null);
        useLazyMount(el);
        return { el };
      },
      template: '<div ref="el">lazy</div>',
    });

    const wrapper = mount(Comp);
    wrapper.unmount();
    expect(disconnect).toHaveBeenCalled();
  });

  it('defaults to visible when IntersectionObserver is unavailable', async () => {
    vi.stubGlobal('IntersectionObserver', undefined);
    const Comp = defineComponent({
      setup() {
        const el = ref<HTMLElement | null>(null);
        const visible = useLazyMount(el);
        return { el, visible };
      },
      template: '<div ref="el">{{ visible ? "yes" : "no" }}</div>',
    });

    const wrapper = mount(Comp);
    await nextTick();
    expect(wrapper.text()).toContain('yes');
  });
});
