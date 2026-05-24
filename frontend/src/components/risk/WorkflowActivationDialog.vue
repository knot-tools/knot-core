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
      class="k-fixed k-inset-0 k-flex k-items-center k-justify-center k-bg-slate-950/60 k-backdrop-blur-sm"
      :style="overlayStyle"
      role="dialog"
      aria-modal="true"
    >
    <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl dark:bg-slate-900 dark:text-slate-100">
      <header class="mb-4 flex items-start gap-3">
        <component
          :is="headerIcon"
          :size="28"
          :class="headerIconClass"
        />
        <div>
          <h2 class="text-lg font-semibold">{{ headerTitle }}</h2>
          <p class="mt-1 text-sm text-slate-500 dark:text-slate-400">
            {{ headerSubtitle }}
          </p>
        </div>
      </header>

      <section class="mb-5 space-y-3">
        <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-sm dark:border-slate-700 dark:bg-slate-800/50">
          <p class="font-medium">{{ workflowLabel }}</p>
          <p class="mt-1 text-xs text-slate-500 dark:text-slate-400">
            {{ summary }}
          </p>
        </div>
        <ul v-if="criticalNodes.length" class="space-y-2 text-sm">
          <li
            v-for="node in criticalNodes"
            :key="node.nodeId"
            class="flex items-start justify-between gap-2 rounded-md border border-slate-200 px-2 py-1.5 dark:border-slate-700"
          >
            <span class="font-medium">{{ node.nodeLabel }}</span>
            <button
              type="button"
              class="shrink-0 text-xs text-indigo-600 hover:underline dark:text-indigo-400"
              @click="$emit('focus-node', node.nodeId)"
            >
              {{ t('activation.viewInEditor', 'View in editor') }}
            </button>
          </li>
        </ul>
        <ul v-if="riskItems.length" class="space-y-1.5 text-sm">
          <li v-for="item in riskItems" :key="item.label" class="flex items-start gap-2">
            <component :is="item.icon" :size="14" class="mt-0.5 shrink-0" :class="item.color" />
            <span>{{ item.label }}</span>
          </li>
        </ul>
        <p v-if="scheduleActive" class="text-xs text-amber-600 dark:text-amber-400">
          {{ t('activation.scheduleActive', 'A schedule or cron trigger may run this workflow automatically.') }}
        </p>
      </section>

      <footer class="flex items-center justify-between gap-2">
        <button
          type="button"
          class="rounded-md px-3 py-2 text-sm text-slate-600 hover:bg-slate-100 dark:text-slate-400 dark:hover:bg-slate-800"
          @click="onCancel"
        >
          {{ t('actions.cancel') }}
        </button>
        <button
          type="button"
          :disabled="confirmDisabled"
          class="rounded-md px-4 py-2 text-sm font-semibold text-white shadow-sm transition disabled:cursor-not-allowed disabled:opacity-50"
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
    ? 'text-red-500'
    : props.riskLevel === 'caution'
      ? 'text-amber-500'
      : 'text-emerald-500',
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
          ? 'text-red-500'
          : 'text-amber-500',
    });
  }
  if (props.riskLevel === 'critical') {
    out.unshift({
      label: t('activation.effects.irreversible', 'Some actions are irreversible.'),
      icon: Activity,
      color: 'text-red-500',
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
      return 'bg-red-600 hover:bg-red-700';
    case 'caution':
      return 'bg-amber-600 hover:bg-amber-700';
    default:
      return 'bg-emerald-600 hover:bg-emerald-700';
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
