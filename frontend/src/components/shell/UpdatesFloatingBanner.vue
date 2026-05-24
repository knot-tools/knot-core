<!--
  Global update nudge banner — S2 snooze (7d + ignore version).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { ArrowUpCircle, X } from 'lucide-vue-next';
import { useUpdatesPoll } from '../../composables/useUpdatesPoll';
import {
  ignoreUpdateVersion,
  isUpdateBannerHidden,
  snoozeUpdateBanner,
} from '../../composables/useUpdatesSnooze';
import { KNOT_Z_UPDATE_BANNER, knotViewportTopOffset } from '../../lib/overlayStacking';
import { productDisplayName } from '../../lib/productDisplayName';

const props = defineProps<{ mode: string }>();

const { t } = useI18n();
const { snapshot, load } = useUpdatesPoll();
const dismissedLocally = ref(false);

const pendingEntries = computed(() =>
  (snapshot.value?.entries ?? []).filter((e) => {
    if (!e.hasUpdate) {
      return false;
    }
    const version = e.latestVersion ?? e.installedVersion ?? 'unknown';
    return !isUpdateBannerHidden(e.slug, version);
  }),
);

const visible = computed(
  () =>
    props.mode !== 'updates'
    && !dismissedLocally.value
    && pendingEntries.value.length > 0,
);

const label = computed(() => {
  const n = pendingEntries.value.length;
  if (n === 1) {
    const e = pendingEntries.value[0];
    return t('updatesFloatingBanner.oneUpdate', {
      product: productDisplayName(e?.slug ?? '', t),
      version: e?.latestVersion ?? '',
    });
  }
  return t('updatesFloatingBanner.manyUpdates', { count: n });
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

function applySnoozeToPending(): void {
  for (const e of pendingEntries.value) {
    const version = e.latestVersion ?? e.installedVersion ?? 'unknown';
    snoozeUpdateBanner(e.slug, version);
  }
  dismissedLocally.value = true;
}

function applyIgnoreToPending(): void {
  for (const e of pendingEntries.value) {
    const version = e.latestVersion ?? e.installedVersion ?? 'unknown';
    ignoreUpdateVersion(e.slug, version);
  }
  dismissedLocally.value = true;
}

onMounted(() => {
  void load();
});

watch(pendingEntries, () => {
  dismissedLocally.value = false;
});
</script>

<template>
  <Teleport to="body">
    <div
      v-if="visible"
      role="status"
      data-testid="updates-floating-banner"
      class="k-fixed k-left-0 k-right-0 k-border-b k-border-knot-warning/40 k-bg-knot-warning-soft k-shadow-md"
      :style="{ top: knotViewportTopOffset(0), zIndex: KNOT_Z_UPDATE_BANNER }"
    >
      <div
        class="k-mx-auto k-flex k-max-w-6xl k-flex-wrap k-items-center k-gap-3 k-px-4 k-py-3 k-text-sm"
      >
        <ArrowUpCircle :size="20" class="k-shrink-0 k-text-knot-warning" aria-hidden="true" />
        <p class="k-min-w-0 k-flex-1 k-font-medium k-text-knot-text">{{ label }}</p>
        <div class="k-flex k-flex-wrap k-items-center k-gap-2">
          <a
            class="k-inline-flex k-items-center k-rounded-md k-bg-knot-primary k-px-3 k-py-1.5 k-text-xs k-font-semibold k-text-white hover:k-opacity-90"
            :href="updatesHref"
          >
            {{ t('updatesFloatingBanner.viewUpdates') }}
          </a>
          <button
            type="button"
            class="k-rounded-md k-border k-border-knot-border k-bg-knot-surface k-px-3 k-py-1.5 k-text-xs k-font-semibold hover:k-bg-knot-surface-soft"
            @click="applySnoozeToPending"
          >
            {{ t('updatesFloatingBanner.snooze') }}
          </button>
          <button
            type="button"
            class="k-rounded-md k-border k-border-transparent k-px-3 k-py-1.5 k-text-xs k-font-semibold k-text-knot-text-muted hover:k-underline"
            @click="applyIgnoreToPending"
          >
            {{ t('updatesFloatingBanner.ignoreVersion') }}
          </button>
          <button
            type="button"
            class="k-rounded-md k-p-1 k-text-knot-text-muted hover:k-bg-knot-surface-soft"
            :aria-label="t('updatesFloatingBanner.dismissAria')"
            @click="applySnoozeToPending"
          >
            <X :size="18" />
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
