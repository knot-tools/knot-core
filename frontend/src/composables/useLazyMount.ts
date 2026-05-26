/**
 * IntersectionObserver helper — defer heavy subtree mount until near viewport.
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { onMounted, onUnmounted, ref, type Ref } from 'vue';

export interface UseLazyMountOptions {
  rootMargin?: string;
  threshold?: number;
}

export function useLazyMount(
  target: Ref<HTMLElement | null>,
  options: UseLazyMountOptions = {},
): Ref<boolean> {
  const visible = ref(false);
  let observer: IntersectionObserver | null = null;

  onMounted(() => {
    const el = target.value;
    if (!el || typeof IntersectionObserver === 'undefined') {
      visible.value = true;
      return;
    }

    observer = new IntersectionObserver(
      (entries) => {
        if (entries.some((entry) => entry.isIntersecting)) {
          visible.value = true;
          observer?.disconnect();
          observer = null;
        }
      },
      {
        rootMargin: options.rootMargin ?? '200px',
        threshold: options.threshold ?? 0,
      },
    );
    observer.observe(el);
  });

  onUnmounted(() => {
    observer?.disconnect();
    observer = null;
  });

  return visible;
}
