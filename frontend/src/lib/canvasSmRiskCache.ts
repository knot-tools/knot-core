/**
 * Short TTL caches for canvas SM lazy checks (verbs simulate + probable transitions).
 */

import { knotApi, type DolibarrVerb } from './api';

const TTL_MS = 30_000;

const verbsCache = new Map<string, { at: number; verbs: DolibarrVerb[] }>();
const probCache = new Map<
  string,
  { at: number; payload: Awaited<ReturnType<typeof knotApi.getStateMachineProbableTransitions>> }
>();

export function clearCanvasSmRiskCachesForTests(): void {
  verbsCache.clear();
  probCache.clear();
}

export async function getCachedDolibarrVerbsSimulated(slug: string): Promise<DolibarrVerb[]> {
  const hit = verbsCache.get(slug);
  const now = Date.now();
  if (hit && now - hit.at < TTL_MS) {
    return hit.verbs;
  }
  const verbs = await knotApi.getDolibarrVerbs(slug, true);
  verbsCache.set(slug, { at: now, verbs });
  return verbs;
}

export async function getCachedProbableTransitions(
  slug: string,
  id: number,
): Promise<Awaited<ReturnType<typeof knotApi.getStateMachineProbableTransitions>>> {
  const key = `${slug}:${id}`;
  const hit = probCache.get(key);
  const now = Date.now();
  if (hit && now - hit.at < TTL_MS) {
    return hit.payload;
  }
  const payload = await knotApi.getStateMachineProbableTransitions(slug, id);
  probCache.set(key, { at: now, payload });
  return payload;
}
