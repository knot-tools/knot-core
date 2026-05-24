<!--
  State-machine hint badge for dolibarr.object nodes on the canvas (V2.7.1).
-->
<script setup lang="ts">
import { computed, inject } from 'vue';
import { useI18n } from 'vue-i18n';
import { AlertTriangle, OctagonAlert } from 'lucide-vue-next';
import type { CanvasSmRiskDisplay } from '../composables/useCanvasChangeStatusRisk';
import { KNOT_CANVAS_SM_INSPECTOR } from '../lib/knotCanvasSmInspector';

const props = defineProps<{
  risk: CanvasSmRiskDisplay;
}>();

const { t } = useI18n();
const inspector = inject(KNOT_CANVAS_SM_INSPECTOR, null);

const tooltip = computed(() => {
  if (props.risk.severity === 'error' && props.risk.detail) return props.risk.detail;
  if (props.risk.severity === 'warning') {
    return t('canvasSm.warningTooltip');
  }
  return '';
});

const ariaLabel = computed(() => {
  if (props.risk.severity === 'error') return t('canvasSm.badgeAriaError');
  if (props.risk.severity === 'warning') return t('canvasSm.badgeAriaWarning');
  return '';
});

function focusInspector(): void {
  inspector?.focusChangeStatusHints();
}
</script>

<template>
  <div
    v-if="risk.severity === 'loading'"
    class="k-absolute k-top-1 k-right-1 k-flex k-items-center k-gap-0.5 k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-px-1 k-py-0.5 k-text-[10px] k-text-knot-text-muted"
    role="status"
    :aria-label="t('canvasSm.loadingAria')"
  >
    <span class="k-inline-block k-size-2 k-rounded-full k-bg-knot-primary k-animate-pulse" />
  </div>

  <div
    v-else-if="risk.severity === 'warning'"
    class="k-absolute k-top-1 k-right-1 k-flex k-flex-col k-items-end k-gap-0.5"
  >
    <span
      class="k-inline-flex k-items-center k-gap-0.5 k-rounded-knot-sm k-border k-border-knot-warning/40 k-bg-knot-warning-soft k-px-1 k-py-0.5 k-text-knot-warning"
      :title="tooltip"
      role="img"
      :aria-label="ariaLabel"
    >
      <AlertTriangle :size="12" :stroke-width="2" />
    </span>
    <button
      type="button"
      class="k-text-[9px] k-font-semibold k-uppercase k-tracking-wide k-text-knot-primary hover:k-underline focus:k-outline-none focus:k-ring-2 focus:k-ring-knot-primary/30 k-rounded-sm"
      @click.stop.prevent="focusInspector"
    >
      {{ t('canvasSm.viewDetails') }}
    </button>
  </div>

  <div
    v-else-if="risk.severity === 'error'"
    class="k-absolute k-top-1 k-right-1 k-flex k-flex-col k-items-end k-gap-0.5"
  >
    <span
      class="k-inline-flex k-items-center k-gap-0.5 k-rounded-knot-sm k-border k-border-knot-danger/40 k-bg-knot-danger-soft k-px-1 k-py-0.5 k-text-knot-danger"
      :title="tooltip"
      role="img"
      :aria-label="ariaLabel"
    >
      <OctagonAlert :size="12" :stroke-width="2" />
    </span>
    <button
      type="button"
      class="k-text-[9px] k-font-semibold k-uppercase k-tracking-wide k-text-knot-primary hover:k-underline focus:k-outline-none focus:k-ring-2 focus:k-ring-knot-primary/30 k-rounded-sm"
      @click.stop.prevent="focusInspector"
    >
      {{ t('canvasSm.viewDetails') }}
    </button>
  </div>
</template>
