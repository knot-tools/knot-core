/**
 * Dolibarr object registry modes — must match Knot\Dolibarr\ObjectRegistryMode (PHP).
 */

export const OBJECT_REGISTRY_ALL_EXCEPT_UNVERIFIED = 'all_except_unverified';
export const OBJECT_REGISTRY_ALL = 'all';
export const OBJECT_REGISTRY_CURATED = 'curated';
export const OBJECT_REGISTRY_DISCOVERY = 'discovery';
export const OBJECT_REGISTRY_DISCOVERY_UNVERIFIED = 'discovery_unverified';

export const OBJECT_REGISTRY_MODES = [
  OBJECT_REGISTRY_ALL_EXCEPT_UNVERIFIED,
  OBJECT_REGISTRY_ALL,
  OBJECT_REGISTRY_CURATED,
  OBJECT_REGISTRY_DISCOVERY,
  OBJECT_REGISTRY_DISCOVERY_UNVERIFIED,
] as const;

export type ObjectRegistryMode = (typeof OBJECT_REGISTRY_MODES)[number];

export function normalizeObjectRegistryMode(raw: unknown): ObjectRegistryMode {
  if (typeof raw === 'string' && (OBJECT_REGISTRY_MODES as readonly string[]).includes(raw)) {
    return raw as ObjectRegistryMode;
  }
  return OBJECT_REGISTRY_ALL_EXCEPT_UNVERIFIED;
}

export function isDiscoveryUnverifiedExpert(mode: string): boolean {
  return mode === OBJECT_REGISTRY_DISCOVERY_UNVERIFIED;
}
