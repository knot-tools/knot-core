<!--
  Notify-only update badge — polls GET /api/updates.php (Phase 7d).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, onMounted } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowUpCircle, Loader2 } from 'lucide-vue-next';
import { useUpdatesPoll } from '../../composables/useUpdatesPoll';

const { t } = useI18n();

const { loading, snapshot, load } = useUpdatesPoll();

onMounted(() => {
  void load();
});

const updatesHref = computed(() => {
  if (typeof window === 'undefined') {
    return '?mode=updates';
  }
  const raw = ((window as unknown as { KNOT_BASE_URL?: string }).KNOT_BASE_URL ?? '').trim();
  if (raw === '') {
    return '?mode=updates';
  }
  try {
    const url = new URL(raw, window.location.origin);
    url.searchParams.set('mode', 'updates');

    return `${url.pathname}${url.search}`;
  } catch {
    const pathOnly = raw.split('?')[0] ?? raw;

    return `${pathOnly}?mode=updates`;
  }
});

const pendingEntries = computed(() =>
  (snapshot.value?.entries ?? []).filter((e) => e.hasUpdate),
);

const label = computed(() => {
  const n = pendingEntries.value.length;
  if (n === 0) {
    return t('updatesBadge.upToDate');
  }
  if (n === 1) {
    const slug = pendingEntries.value[0]?.slug ?? '';
    return t('updatesBadge.oneUpdate', { slug });
  }
  return t('updatesBadge.manyUpdates', { count: n });
});
</script>

<template>
  <div
    class="k-flex k-items-center k-gap-2 k-px-3 k-py-2 k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface-soft k-text-sm"
    data-testid="updates-badge"
  >
    <a
      class="k-flex k-min-w-0 k-flex-1 k-items-center k-gap-2 k-text-inherit k-no-underline hover:k-opacity-90"
      :href="updatesHref"
      :aria-label="t('updatesBadge.openUpdates')"
    >
      <Loader2 v-if="loading" :size="16" class="k-animate-spin k-text-knot-text-soft" />
      <ArrowUpCircle
        v-else
        :size="16"
        :class="pendingEntries.length ? 'k-text-knot-warning' : 'k-text-knot-success'"
      />
      <span class="k-truncate k-text-knot-text-muted">{{ label }}</span>
    </a>
    <button
      type="button"
      class="k-ml-auto k-text-xs k-text-knot-primary k-font-semibold hover:k-underline"
      :disabled="loading"
      @click.stop="load(true)"
    >
      {{ t('updatesBadge.refresh') }}
    </button>
  </div>
</template>
