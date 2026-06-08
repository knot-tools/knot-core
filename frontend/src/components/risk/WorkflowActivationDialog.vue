<!--
  WorkflowActivationDialog — V2.5 UX-3
  Mandatory review screen before activating a workflow.

  - First-time activation: cannot be skipped, even for "safe" workflows.
  - Subsequent activations: can be skipped on opt-out (safe + caution only).
                            critical workflows ALWAYS show this dialog.
  - On critical workflows: confirm button is disabled for `criticalDelayMs`
    milliseconds (default 3000) to force a deliberate beat.

  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<template>
  <Teleport to="body">
    <div
      v-if="modelValue"
      data-knot-test="knot-activation-dialog"
      class="k-fixed k-inset-0 k-flex k-items-center k-justify-center k-bg-black/50 k-backdrop-blur-sm k-px-4"
      :style="overlayStyle"
      role="presentation"
      @click.self="onCancel"
    >
      <div
        class="k-w-full k-max-w-lg k-rounded-knot-md k-bg-knot-surface k-text-knot-text k-p-6 k-shadow-knot-lg k-border k-border-knot-border"
        role="dialog"
        aria-modal="true"
      >
        <header class="k-mb-4 k-flex k-items-start k-gap-3">
          <component
            :is="headerIcon"
            :size="28"
            :class="headerIconClass"
          />
          <div class="k-min-w-0">
            <h2 class="k-text-lg k-font-semibold k-text-knot-text">{{ headerTitle }}</h2>
            <p class="k-mt-1 k-text-sm k-text-knot-text-muted">
              {{ headerSubtitle }}
            </p>
          </div>
        </header>

        <section class="k-mb-5 k-space-y-3">
          <div class="k-rounded-knot-sm k-border k-border-knot-border k-bg-knot-surface-soft k-p-3 k-text-sm">
            <p class="k-font-medium k-text-knot-text">{{ workflowLabel }}</p>
            <p class="k-mt-1 k-text-xs k-text-knot-text-muted">
              {{ summary }}
            </p>
          </div>
          <ul v-if="criticalNodes.length" class="k-space-y-2 k-text-sm">
            <li
              v-for="node in criticalNodes"
              :key="node.nodeId"
              class="k-flex k-items-start k-justify-between k-gap-2 k-rounded-knot-sm k-border k-border-knot-border k-bg-knot-surface k-px-2 k-py-1.5"
            >
              <span class="k-font-medium k-text-knot-text">{{ node.nodeLabel }}</span>
              <button
                type="button"
                class="k-shrink-0 k-text-xs k-text-knot-primary hover:k-underline"
                @click="$emit('focus-node', node.nodeId)"
              >
                {{ t('activation.viewInEditor', 'View in editor') }}
              </button>
            </li>
          </ul>
          <ul v-if="riskItems.length" class="k-space-y-1.5 k-text-sm">
            <li v-for="item in riskItems" :key="item.label" class="k-flex k-items-start k-gap-2 k-text-knot-text">
              <component :is="item.icon" :size="14" class="k-mt-0.5 k-shrink-0" :class="item.color" />
              <span>{{ item.label }}</span>
            </li>
          </ul>
          <p v-if="scheduleActive" class="k-text-xs k-text-knot-warning">
            {{ t('activation.scheduleActive', 'A schedule or cron trigger may run this workflow automatically.') }}
          </p>
        </section>

        <footer class="k-flex k-items-center k-justify-between k-gap-2">
          <button
            type="button"
            class="k-rounded-knot-sm k-px-3 k-py-2 k-text-sm k-text-knot-text-muted hover:k-bg-knot-surface-soft"
            @click="onCancel"
          >
            {{ t('actions.cancel') }}
          </button>
          <button
            type="button"
            :disabled="confirmDisabled"
            class="k-rounded-knot-sm k-px-4 k-py-2 k-text-sm k-font-semibold k-text-white k-shadow-sm k-transition disabled:k-cursor-not-allowed disabled:k-opacity-50"
            :class="confirmClass"
            @click="onConfirm"
          >
            <span v-if="riskLevel === 'critical' && remainingSecs > 0">
              {{ t('actions.confirm') }} ({{ remainingSecs }}s)
            </span>
            <span v-else>{{ confirmLabel }}</span>
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { KNOT_Z_DIALOG } from '../../lib/overlayStacking';
import { useI18n } from 'vue-i18n';
import { Activity, AlertTriangle, OctagonAlert, Shield } from 'lucide-vue-next';
import type { CriticalNodeSummary } from '../../composables/useWorkflowRiskSummary';
import type { RiskLevel, SideEffect } from '../../composables/useNodeRisk';

const props = withDefaults(
  defineProps<{
    modelValue: boolean;
    workflowLabel: string;
    riskLevel: RiskLevel;
    summary?: string;
    sideEffects?: SideEffect[];
    criticalNodes?: CriticalNodeSummary[];
    scheduleActive?: boolean;
    criticalDelayMs?: number;
  }>(),
  {
    summary: '',
    sideEffects: () => [],
    criticalNodes: () => [],
    scheduleActive: false,
    criticalDelayMs: 3000,
  },
);

const emit = defineEmits<{
  (e: 'update:modelValue', value: boolean): void;
  (e: 'confirm'): void;
  (e: 'focus-node', nodeId: string): void;
}>();

const { t } = useI18n();
const overlayStyle = { zIndex: KNOT_Z_DIALOG };
const remainingSecs = ref(0);
let timer: ReturnType<typeof setInterval> | null = null;

watch(
  () => props.modelValue,
  (open) => {
    if (open && props.riskLevel === 'critical') {
      remainingSecs.value = Math.ceil(props.criticalDelayMs / 1000);
      if (timer) clearInterval(timer);
      timer = setInterval(() => {
        remainingSecs.value -= 1;
        if (remainingSecs.value <= 0 && timer) {
          clearInterval(timer);
          timer = null;
        }
      }, 1000);
    } else {
      remainingSecs.value = 0;
      if (timer) {
        clearInterval(timer);
        timer = null;
      }
    }
  },
  { immediate: true },
);

const headerIcon = computed(() => {
  switch (props.riskLevel) {
    case 'critical':
      return OctagonAlert;
    case 'caution':
      return AlertTriangle;
    default:
      return Shield;
  }
});

const headerIconClass = computed(() =>
  props.riskLevel === 'critical'
    ? 'k-text-knot-danger'
    : props.riskLevel === 'caution'
      ? 'k-text-knot-warning'
      : 'k-text-knot-success',
);

const headerTitle = computed(() => {
  switch (props.riskLevel) {
    case 'critical':
      return t('activation.titleCritical', 'This workflow performs critical actions');
    case 'caution':
      return t('activation.titleCaution', 'This workflow writes data');
    default:
      return t('activation.titleSafe', 'Activate workflow');
  }
});

const headerSubtitle = computed(() => {
  switch (props.riskLevel) {
    case 'critical':
      return t(
        'activation.subtitleCritical',
        'Read the summary below carefully. Activating means this workflow will run for real and may charge money or alter your accounting.',
      );
    case 'caution':
      return t(
        'activation.subtitleCaution',
        'Activating will let this workflow create or update data in Dolibarr or send messages.',
      );
    default:
      return t(
        'activation.subtitleSafe',
        'This workflow only reads data. Activate to run it on its trigger.',
      );
  }
});

const riskItems = computed(() => {
  const out: Array<{ label: string; icon: typeof OctagonAlert; color: string }> = [];
  for (const eff of props.sideEffects) {
    out.push({
      label: t('activation.effects.' + eff, '[' + eff + ']'),
      icon: AlertTriangle,
      color:
        eff === 'accounting' || eff === 'external-paid'
          ? 'k-text-knot-danger'
          : 'k-text-knot-warning',
    });
  }
  if (props.riskLevel === 'critical') {
    out.unshift({
      label: t('activation.effects.irreversible', 'Some actions are irreversible.'),
      icon: Activity,
      color: 'k-text-knot-danger',
    });
  }
  return out;
});

const confirmLabel = computed(() =>
  props.riskLevel === 'critical'
    ? t('activation.confirmCritical', 'I understand — activate')
    : t('actions.confirm'),
);

const confirmClass = computed(() => {
  switch (props.riskLevel) {
    case 'critical':
      return 'k-bg-knot-danger hover:k-bg-knot-danger/90';
    case 'caution':
      return 'k-bg-knot-warning hover:k-bg-knot-warning/90';
    default:
      return 'k-bg-knot-success hover:k-bg-knot-success/90';
  }
});

const confirmDisabled = computed(
  () => props.riskLevel === 'critical' && remainingSecs.value > 0,
);

function onCancel(): void {
  emit('update:modelValue', false);
}

function onConfirm(): void {
  if (confirmDisabled.value) return;
  emit('confirm');
  emit('update:modelValue', false);
}
</script>
