/**
 * Vitest global setup.
 *
 * Runs once before any test file is loaded. Stubs the few browser
 * APIs that Knot components touch but jsdom does not implement
 * (matchMedia, ResizeObserver, IntersectionObserver, fetch).
 */

import { vi } from 'vitest';

if (typeof window !== 'undefined') {
  if (!window.matchMedia) {
    window.matchMedia = vi.fn().mockImplementation((query: string) => ({
      matches: false,
      media: query,
      onchange: null,
      addListener: vi.fn(),
      removeListener: vi.fn(),
      addEventListener: vi.fn(),
      removeEventListener: vi.fn(),
      dispatchEvent: vi.fn(),
    }));
  }

  if (!('ResizeObserver' in window)) {
    (window as unknown as { ResizeObserver: typeof ResizeObserver }).ResizeObserver =
      class {
        observe(): void {}
        unobserve(): void {}
        disconnect(): void {}
      } as unknown as typeof ResizeObserver;
  }

  if (!('IntersectionObserver' in window)) {
    (window as unknown as { IntersectionObserver: typeof IntersectionObserver }).IntersectionObserver =
      class {
        observe(): void {}
        unobserve(): void {}
        disconnect(): void {}
        takeRecords(): IntersectionObserverEntry[] {
          return [];
        }
        root = null;
        rootMargin = '';
        thresholds = [];
      } as unknown as typeof IntersectionObserver;
  }
}

if (typeof globalThis.fetch === 'undefined') {
  globalThis.fetch = vi.fn(async () =>
    new Response(JSON.stringify({ data: null }), {
      status: 200,
      headers: { 'Content-Type': 'application/json' },
    })
  );
}
