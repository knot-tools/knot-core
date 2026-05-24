/**
 * Shared poll for GET /api/updates.php (badge + floating banner).
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */
import { ref, type Ref } from 'vue';
import { knotApi, type UpdatesCheckResponse } from '../lib/api';

type PollState = {
  loading: Ref<boolean>;
  snapshot: Ref<UpdatesCheckResponse | null>;
  error: Ref<string | null>;
  load: (forceRefresh?: boolean) => Promise<void>;
};

let shared: PollState | null = null;

export function useUpdatesPoll(): PollState {
  if (shared !== null) {
    return shared;
  }

  const loading = ref(false);
  const snapshot = ref<UpdatesCheckResponse | null>(null);
  const error = ref<string | null>(null);

  async function load(forceRefresh = false): Promise<void> {
    loading.value = true;
    error.value = null;
    try {
      snapshot.value = await knotApi.updates({ force: forceRefresh });
    } catch (err) {
      error.value = err instanceof Error ? err.message : 'updates load failed';
      snapshot.value = null;
    } finally {
      loading.value = false;
    }
  }

  shared = { loading, snapshot, error, load };
  void load();

  return shared;
}

/** Test helper — reset singleton between specs. */
export function resetUpdatesPollForTests(): void {
  shared = null;
}
