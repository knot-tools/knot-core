<!--
  Non-blocking recommendation banner when workflows use Pro Pack connectors
  without an active license. Positive framing: Pro Pack extends Core.

  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { AlertTriangle, ShoppingBag, ExternalLink } from 'lucide-vue-next';
import { knotApi, type MigrationScanResponse } from '../../lib/api';
import { isMarketplaceUiEnabled } from '../../lib/marketplaceUi';

const { t } = useI18n();

const props = defineProps<{
  /** Instance-wide scan for WorkflowsView. */
  global?: boolean;
  /** Single-workflow scan for EditorView. */
  workflowId?: number | null;
}>();

const data = ref<MigrationScanResponse | null>(null);
const loading = ref(false);

const isGlobalScan = computed(() => props.global === true);

async function load() {
  if (!isGlobalScan.value && (props.workflowId === undefined || props.workflowId === null)) {
    data.value = null;
    return;
  }
  loading.value = true;
  try {
    if (isGlobalScan.value) {
      data.value = await knotApi.migrationScan();
    } else {
      data.value = await knotApi.migrationScan(props.workflowId as number);
    }
  } catch {
    data.value = null;
  } finally {
    loading.value = false;
  }
}

onMounted(load);
watch(() => [props.workflowId, props.global] as const, load);

const impactedRow = computed(() => data.value?.impacted?.[0] ?? null);
const distinctConnectors = computed(() => {
  if (isGlobalScan.value) {
    return data.value?.summary?.connectorIdsImpacted ?? [];
  }
  return impactedRow.value?.distinctConnectorIds ?? [];
});
const impactedWorkflowCount = computed(() => data.value?.summary?.impactedWorkflows ?? 0);
const showBanner = computed(() => {
  if (loading.value || !data.value) {
    return false;
  }
  if (isGlobalScan.value) {
    return impactedWorkflowCount.value > 0;
  }
  return impactedRow.value !== null;
});
const marketplaceChromeEnabled = computed(() => isMarketplaceUiEnabled());
</script>

<template>
  <div
    v-if="showBanner"
    class="k-bg-knot-warning-soft k-border k-border-knot-warning/40 k-text-knot-warning k-px-4 k-py-3 k-rounded-knot-sm k-flex k-items-start k-gap-3 k-text-sm"
    role="alert"
  >
    <AlertTriangle :size="18" class="k-mt-0.5 k-shrink-0" />
    <div class="k-min-w-0 k-flex-1 k-space-y-1">
      <div class="k-font-semibold k-text-knot-text">
        <template v-if="isGlobalScan">
          {{ t('proPackRecommendation.globalTitle', {
            count: impactedWorkflowCount,
            connectors: distinctConnectors.length,
          }) }}
        </template>
        <template v-else>
          {{ t('proPackRecommendation.workflowTitle', {
            connectors: distinctConnectors.length,
          }) }}
        </template>
      </div>
      <div class="k-text-knot-text-muted k-text-xs k-flex k-flex-wrap k-gap-1.5 k-items-center">
        <span>{{ t('proPackRecommendation.affectedLabel') }}</span>
        <code
          v-for="cid in distinctConnectors"
          :key="cid"
          class="k-bg-knot-warning/20 k-text-knot-warning k-px-1.5 k-py-0.5 k-rounded k-font-mono k-text-[11px]"
        >
          {{ cid }}
        </code>
      </div>
      <div class="k-text-knot-text-muted k-text-xs">
        {{ t('proPackRecommendation.body') }}
      </div>
    </div>
    <div class="k-flex k-items-center k-gap-2 k-shrink-0">
      <a
        v-if="marketplaceChromeEnabled"
        href="?mode=pro-pack&tab=connectors"
        class="k-px-3 k-py-1.5 k-text-xs k-font-semibold k-bg-knot-warning k-text-white k-rounded-knot-sm hover:k-bg-knot-warning/80 k-flex k-items-center k-gap-1.5"
      >
        <ShoppingBag :size="12" /> {{ t('proPackRecommendation.catalogCta') }}
      </a>
      <a
        href="https://license.knot.tools"
        target="_blank"
        rel="noopener"
        class="k-text-xs k-text-knot-text-muted hover:k-text-knot-text k-flex k-items-center k-gap-1"
      >
        {{ t('proPackRecommendation.buyCta') }} <ExternalLink :size="11" />
      </a>
    </div>
  </div>
</template>
