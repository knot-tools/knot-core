<!--
  SimulationSidePanel — replaces inspector during simulation.
  Vertical timeline of node logs with collapsible JSON I/O.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { CheckCircle2, AlertCircle, Clock, X, Maximize2, KeyRound } from 'lucide-vue-next';
import { formatHumanExecutionLine } from '../lib/humanExecutionLog';
import { extractExtensionIdFromLicenseError } from './risk/ExecutionErrorTranslator';
import { KNOWN_SKU_PRO_PACK } from '../lib/known-skus';

const { t } = useI18n();

interface NodeLog {
  nodeId?: string;
  type?: string;
  status?: string;
  durationMs?: number;
  input?: unknown;
  output?: unknown;
  error?: string | null;
}

const props = withDefaults(
  defineProps<{
    logs: NodeLog[];
    durationMs: number;
    status: 'success' | 'error';
    dryRun: boolean;
    /** Map node id → canvas label for readable simulation titles */
    nodeLabels?: Record<string, string>;
  }>(),
  { nodeLabels: () => ({}) },
);

const emit = defineEmits<{
  (e: 'close'): void;
  (e: 'open-full'): void;
  (e: 'pin', nodeId: string): void;
}>();

const expanded = ref<Record<string, boolean>>({});

function toggle(id: string) {
  expanded.value[id] = !expanded.value[id];
}

function statusIcon(status?: string) {
  if (status === 'success') return CheckCircle2;
  if (status === 'error') return AlertCircle;
  return Clock;
}

function statusClass(status?: string) {
  if (status === 'success') return 'k-text-knot-success';
  if (status === 'error') return 'k-text-knot-danger';
  if (status === 'skipped') return 'k-text-knot-text-soft';
  return 'k-text-knot-text-muted';
}

const filteredLogs = computed(() => props.logs ?? []);

const LICENSE_ERROR_RE =
  /\[license_required\]|license_required|license check failed|extension_(?:expired|missing|unlicensed|tampered)|license_invalid/i;

const licenseBlocked = computed(() =>
  filteredLogs.value.some((log) => LICENSE_ERROR_RE.test(String(log.error ?? ''))),
);

const licenseBlockedHint = computed(() => {
  if (!licenseBlocked.value) return '';
  return t('simulationPanel.licenseBlockedHint');
});

function openProPackActivation(): void {
  const first = filteredLogs.value.find((log) => LICENSE_ERROR_RE.test(String(log.error ?? '')));
  const extId =
    extractExtensionIdFromLicenseError(String(first?.error ?? '')) ?? KNOWN_SKU_PRO_PACK;
  const knotCore = (
    window as unknown as {
      KnotCore?: { openLicenseActivationModal?: (id: string, label?: string) => void };
    }
  ).KnotCore;
  if (typeof knotCore?.openLicenseActivationModal === 'function') {
    knotCore.openLicenseActivationModal(extId, t('editor.paletteProPackLabel', 'Knot Pro Pack'));
    return;
  }
  window.location.href = '?mode=pro-pack';
}

function primaryTitle(log: NodeLog): string {
  const id = String(log.nodeId ?? '');
  const lbl = id ? (props.nodeLabels[id] ?? '').trim() : '';
  if (lbl !== '') return lbl;
  return id !== '' ? id : '?';
}

function secondaryLine(log: NodeLog): string {
  const typeLabel = String(log.type ?? '').trim() || t('simulationPanel.unknownType');
  return t('simulationPanel.nodeTiming', { type: typeLabel, ms: log.durationMs ?? '?' });
}

function humanLine(log: NodeLog): string {
  const rawError = String(log.error ?? '');
  if (LICENSE_ERROR_RE.test(rawError)) {
    return t('simulationPanel.licenseBlockedNode', { label: primaryTitle(log) });
  }
  return formatHumanExecutionLine({
    nodeId: log.nodeId,
    type: log.type,
    status: log.status,
    label: primaryTitle(log),
    error: log.error,
    output: log.output,
  });
}
</script>

<template>
  <div class="k-flex k-flex-col k-h-full k-min-h-0">
    <header class="k-px-5 k-py-4 k-border-b k-border-knot-border k-flex k-items-center k-justify-between">
      <div>
        <div class="k-text-knot-text k-font-semibold k-text-sm k-flex k-items-center k-gap-2">
          {{ dryRun ? t('simulationPanel.simulation') : t('simulationPanel.run') }}
          <span :class="['k-text-[10px] k-px-2 k-py-0.5 k-rounded-knot-pill', status === 'success' ? 'k-bg-knot-success-soft k-text-knot-success' : 'k-bg-knot-danger-soft k-text-knot-danger']">
            {{ status === 'success' ? t('simulationPanel.resultSuccess') : t('simulationPanel.resultError') }}
          </span>
        </div>
        <div class="k-text-[11px] k-text-knot-text-muted k-mt-1">{{ t('simulationPanel.nodeCount', { ms: durationMs, n: filteredLogs.length }) }}</div>
        <p
          v-if="dryRun && !licenseBlocked"
          class="k-mt-2 k-text-[11px] k-text-knot-warning k-bg-knot-warning-soft k-border k-border-knot-warning k-rounded-knot-sm k-px-2 k-py-1.5 k-leading-snug"
          data-knot-test="simulate-dry-run-hint"
        >
          {{ t('editor.simulatePartialDryRunHint') }}
        </p>
        <div
          v-if="licenseBlocked"
          class="k-mt-2 k-text-[11px] k-text-knot-warning k-bg-knot-warning-soft k-border k-border-knot-warning k-rounded-knot-sm k-px-2 k-py-1.5 k-leading-snug k-space-y-1.5"
          data-knot-test="simulate-license-blocked"
        >
          <p>{{ licenseBlockedHint || t('simulationPanel.licenseBlockedHint') }}</p>
          <button
            type="button"
            class="k-inline-flex k-items-center k-gap-1 k-text-[11px] k-font-semibold k-text-knot-primary hover:k-underline"
            data-knot-test="simulate-license-cta"
            @click="openProPackActivation"
          >
            <KeyRound :size="12" />
            {{ t('simulationPanel.licenseBlockedCta') }}
          </button>
        </div>
      </div>
      <div class="k-flex k-items-center k-gap-1">
        <button @click="emit('open-full')" class="k-p-1.5 k-rounded-knot-sm hover:k-bg-knot-surface-soft" :title="t('simulationPanel.fullscreen')"><Maximize2 :size="14" /></button>
        <button
          type="button"
          data-knot-test="knot-simulation-close"
          :title="t('simulationPanel.close')"
          class="k-p-1.5 k-rounded-knot-sm hover:k-bg-knot-surface-soft"
          @click="emit('close')"
        >
          <X :size="14" />
        </button>
      </div>
    </header>

    <div class="k-flex-1 k-overflow-y-auto">
      <ol class="k-divide-y k-divide-knot-border">
        <li v-for="(log, idx) in filteredLogs" :key="(log.nodeId ?? '') + idx" class="k-px-4 k-py-3">
          <button @click="toggle(String(log.nodeId ?? idx))" class="k-w-full k-flex k-items-center k-gap-2 k-text-left">
            <component :is="statusIcon(log.status)" :class="['k-shrink-0', statusClass(log.status)]" :size="14" />
            <div class="k-flex-1 k-min-w-0">
              <div class="k-text-knot-text k-text-sm k-font-semibold k-truncate">{{ humanLine(log) }}</div>
              <div class="k-text-[11px] k-text-knot-text-soft">{{ secondaryLine(log) }}</div>
            </div>
          </button>
          <div v-if="expanded[String(log.nodeId ?? idx)]" class="k-mt-2 k-space-y-2">
            <div>
              <div class="k-text-[11px] k-text-knot-text-soft k-uppercase k-font-bold">{{ t('simulationPanel.input') }}</div>
              <pre class="k-text-[12px] k-font-mono k-bg-knot-surface-soft k-p-2 k-rounded-knot-sm k-overflow-auto k-max-h-32">{{ JSON.stringify(log.input ?? {}, null, 2) }}</pre>
            </div>
            <div>
              <div class="k-text-[11px] k-text-knot-text-soft k-uppercase k-font-bold">{{ t('simulationPanel.output') }}</div>
              <pre class="k-text-[12px] k-font-mono k-bg-knot-surface-soft k-p-2 k-rounded-knot-sm k-overflow-auto k-max-h-32">{{ JSON.stringify(log.output ?? {}, null, 2) }}</pre>
            </div>
            <div v-if="log.error" class="k-text-knot-danger k-text-[12px] k-font-mono">{{ log.error }}</div>
            <button v-if="log.nodeId" @click="emit('pin', String(log.nodeId))" class="k-text-[11px] k-text-knot-primary hover:k-underline">{{ t('simulationPanel.pinOutput') }}</button>
          </div>
        </li>
      </ol>
    </div>
  </div>
</template>
