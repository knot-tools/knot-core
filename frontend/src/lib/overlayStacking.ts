/**
 * Z-index and viewport anchors for Knot UI above Dolibarr chrome.
 * Dolibarr top menus/bookmarks commonly use z-index ≥ 10_000.
 *
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

/** Toast stack (top-right). */
export const KNOT_Z_TOAST = 100_010;

/** Global update nudge banner (below Dolibarr chrome, above page content). */
export const KNOT_Z_UPDATE_BANNER = 100_005;

/** Confirm, activation review, license activation. */
export const KNOT_Z_DIALOG = 100_020;

/** Command palette and other global modals. */
export const KNOT_Z_MODAL = 100_030;

/** Full-viewport takeovers (onboarding). */
export const KNOT_Z_FULLSCREEN = 100_040;

/**
 * CSS length for `top` on viewport-fixed alerts (below Dolibarr header).
 * Override on `#knot-app` via `--knot-dolibarr-chrome-top` when needed.
 */
export const KNOT_DOLIBARR_CHROME_TOP = 'var(--knot-dolibarr-chrome-top, 4.5rem)';

export function knotViewportTopOffset(extraRem = 0.5): string {
  if (extraRem <= 0) {
    return KNOT_DOLIBARR_CHROME_TOP;
  }
  return `calc(${KNOT_DOLIBARR_CHROME_TOP} + ${extraRem}rem)`;
}
