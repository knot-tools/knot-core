/**
 * Shared poll for GET /api/updates.php (badge + floating banner + Updates page + suite health).
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */
import { ref, type Ref } from 'vue';
import { knotApi, type UpdatesCheckResponse } from '../lib/api';

type PollState = {
  loading: Ref<boolean>;
  snapshot: Ref<UpdatesCheckResponse | null>;
  error: Ref<string | null>;
  load: (forceRefresh?: boolean) => Promise<UpdatesCheckResponse | null>;
};

let shared: PollState | null = null;

/** In-flight coalesce so Dashboard badge + SuiteHealth + banner share one request. */
let inFlight: Promise<UpdatesCheckResponse | null> | null = null;
let inFlightForce = false;

export function useUpdatesPoll(): PollState {
  if (shared !== null) {
    return shared;
  }

  const loading = ref(false);
  const snapshot = ref<UpdatesCheckResponse | null>(null);
  const error = ref<string | null>(null);

  async function load(forceRefresh = false): Promise<UpdatesCheckResponse | null> {
    if (inFlight !== null) {
      // A force check upgrades a non-force in-flight call by starting a new one
      // after the current settles; callers awaiting force still get fresh data.
      if (!forceRefresh || inFlightForce) {
        return inFlight;
      }
      await inFlight;
    }

    loading.value = true;
    error.value = null;
    inFlightForce = forceRefresh;
    inFlight = (async () => {
      try {
        const data = await knotApi.updates({ force: forceRefresh });
        snapshot.value = data;
        return data;
      } catch (err) {
        error.value = err instanceof Error ? err.message : 'updates load failed';
        // Keep last good snapshot on refresh failure so badge/dashboard do not
        // flip to a false "up to date" when a later force check already succeeded.
        return snapshot.value;
      } finally {
        loading.value = false;
        inFlight = null;
        inFlightForce = false;
      }
    })();

    return inFlight;
  }

  shared = { loading, snapshot, error, load };
  void load();

  return shared;
}

/** Test helper — reset singleton between specs. */
export function resetUpdatesPollForTests(): void {
  shared = null;
  inFlight = null;
  inFlightForce = false;
}
