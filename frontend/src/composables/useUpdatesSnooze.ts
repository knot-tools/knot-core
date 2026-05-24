/**
 * localStorage snooze / ignore for update floating banner (S2).
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */
const SNOOZE_MS = 7 * 24 * 60 * 60 * 1000;

function entityId(): number {
  if (typeof window === 'undefined') {
    return 1;
  }
  const raw = (window as unknown as { KNOT_ENTITY?: number }).KNOT_ENTITY;
  return typeof raw === 'number' && raw > 0 ? raw : 1;
}

function snoozeKey(slug: string, version: string): string {
  return `knot:updates-snooze:v1:${entityId()}:${slug}:${version}`;
}

function ignoreKey(slug: string, version: string): string {
  return `knot:updates-ignore:v1:${entityId()}:${slug}:${version}`;
}

export function isUpdateBannerHidden(slug: string, version: string): boolean {
  if (typeof window === 'undefined') {
    return false;
  }
  if (window.localStorage.getItem(ignoreKey(slug, version)) === '1') {
    return true;
  }
  const raw = window.localStorage.getItem(snoozeKey(slug, version));
  if (raw === null) {
    return false;
  }
  const expiry = Number.parseInt(raw, 10);
  return Number.isFinite(expiry) && Date.now() < expiry;
}

export function snoozeUpdateBanner(slug: string, version: string): void {
  if (typeof window === 'undefined') {
    return;
  }
  window.localStorage.setItem(snoozeKey(slug, version), String(Date.now() + SNOOZE_MS));
}

export function ignoreUpdateVersion(slug: string, version: string): void {
  if (typeof window === 'undefined') {
    return;
  }
  window.localStorage.setItem(ignoreKey(slug, version), '1');
}
