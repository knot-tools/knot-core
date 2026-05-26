/**
 * Marketplace editorial freshness for the Dolibarr Knot left nav.
 * Persists last-seen `updatedAt` and toggles `html[data-marketplace-unread]`
 * for CSS (see `css/knot-host.css`).
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

const LAST_SEEN_KEY = 'knot.marketplace.lastSeen';
const CACHED_UPDATED_AT_KEY = 'knot.marketplace.cachedEditorialUpdatedAt';

function safeGetItem(key: string): string | null {
  try {
    return localStorage.getItem(key);
  } catch {
    return null;
  }
}

function safeSetItem(key: string, value: string): void {
  try {
    localStorage.setItem(key, value);
  } catch {
    //
  }
}

export function readMarketplaceLastSeen(): string | null {
  return safeGetItem(LAST_SEEN_KEY);
}

export function readCachedEditorialUpdatedAt(): string | null {
  return safeGetItem(CACHED_UPDATED_AT_KEY);
}

/**
 * True when `updatedAt` is strictly newer than `lastSeen` (ISO-8601 lexicographic compare).
 */
export function editorialIsNewerThanLastSeen(updatedAt: string | undefined, lastSeen: string | null): boolean {
  if (typeof updatedAt !== 'string' || updatedAt === '') {
    return false;
  }
  if (lastSeen === null || lastSeen === '') {
    return true;
  }
  return updatedAt.localeCompare(lastSeen) > 0;
}

export function persistEditorialUpdatedAtCache(updatedAt: string | undefined): void {
  if (typeof updatedAt !== 'string' || updatedAt === '') {
    return;
  }
  safeSetItem(CACHED_UPDATED_AT_KEY, updatedAt);
}

/**
 * Sets or clears `data-marketplace-unread` on `document.documentElement`.
 */
export function applyMarketplaceUnreadDocumentFlag(updatedAt: string | undefined, lastSeenReference: string | null): void {
  if (editorialIsNewerThanLastSeen(updatedAt, lastSeenReference)) {
    document.documentElement.setAttribute('data-marketplace-unread', 'true');
  } else {
    document.documentElement.removeAttribute('data-marketplace-unread');
  }
}

/**
 * After a successful Marketplace load while the user is viewing the SPA route,
 * treat editorial as seen and clear the document flag.
 */
export function markMarketplaceEditorialSeen(updatedAt: string | undefined): void {
  if (typeof updatedAt !== 'string' || updatedAt === '') {
    return;
  }
  safeSetItem(LAST_SEEN_KEY, updatedAt);
  safeSetItem(CACHED_UPDATED_AT_KEY, updatedAt);
  document.documentElement.removeAttribute('data-marketplace-unread');
}

/**
 * Non-marketplace routes: infer unread from the last cached `updatedAt` vs `lastSeen`
 * (cache is refreshed on each successful `/api/marketplace.php` load).
 */
export function syncMarketplaceUnreadFromLocalCache(): void {
  const lastSeen = readMarketplaceLastSeen();
  const cached = readCachedEditorialUpdatedAt();
  applyMarketplaceUnreadDocumentFlag(cached ?? undefined, lastSeen);
}
