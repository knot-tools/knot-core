<!--
  KnotNode — universal Knot Vue Flow node.
  Copyright (C) 2026 Knot — GPL-3.0-or-later

  Receives `data: { type, label, status?, subtitle?, meta? }`.
  Renders an icon-led card in the Knot design system, with handles
  whose colors depend on the category (trigger / logic / action).
-->
<script setup lang="ts">
import { computed, inject } from 'vue';
import { Handle, Position, type NodeProps } from '@vue-flow/core';
import { CheckCircle2, AlertTriangle, Loader2 } from 'lucide-vue-next';
import { resolveNodeMeta } from './nodeRegistry';
import { connectorMessageKey, resolveConnectorLabel } from '@/lib/connectorLabels';
import { useCanvasChangeStatusRisk } from '../composables/useCanvasChangeStatusRisk';
import { useNodeRisk, type ConnectorRiskMetadata } from '../composables/useNodeRisk';
import KnotNodeSmHintBadge from './KnotNodeSmHintBadge.vue';
import KnotNodeRiskBadge from '../components/risk/KnotNodeRiskBadge.vue';
import { KNOT_CONNECTORS_KEY } from '../lib/knotConnectorContext';
import type { ConnectorDescriptor } from '../lib/api';

interface KnotNodeData {
  type: string;
  label?: string;
  subtitle?: string;
  status?: 'idle' | 'running' | 'success' | 'error';
  config?: Record<string, unknown>;
  pinnedOutput?: Record<string, unknown> | null;
}

const props = defineProps<NodeProps<KnotNodeData>>();

const nodeTypeRef = computed(() => props.data?.type ?? '');
const configRef = computed(() => (props.data?.config ?? {}) as Record<string, unknown>);
const selectedRef = computed(() => props.selected);

const { risk, onNodeMouseEnter, onNodeMouseLeave } = useCanvasChangeStatusRisk({
  nodeType: nodeTypeRef,
  config: configRef,
  selected: selectedRef,
});

const connectorsRef = inject(KNOT_CONNECTORS_KEY, undefined);

const catalogRisk = useNodeRisk(() => {
  const list = connectorsRef?.value ?? [];
  const descriptor = list.find((c: ConnectorDescriptor) => c.metadata.id === nodeTypeRef.value);
  const m = descriptor?.metadata;
  const metadata: ConnectorRiskMetadata | null = m
    ? {
        id: String(m.id ?? ''),
        riskLevel: m.riskLevel as ConnectorRiskMetadata['riskLevel'],
        reversible: m.reversible as boolean | undefined,
        sideEffects: m.sideEffects as ConnectorRiskMetadata['sideEffects'],
        riskByConfig: m.riskByConfig as ConnectorRiskMetadata['riskByConfig'],
        riskFieldKey: m.riskFieldKey as string | undefined,
      }
    : null;
  return { config: configRef.value, metadata };
});

const meta = computed(() => resolveNodeMeta(props.data?.type));
const Icon = computed(() => meta.value.icon);

const catalogLabel = computed(() => {
  const list = connectorsRef?.value ?? [];
  const id = nodeTypeRef.value;
  const descriptor = list.find((c: ConnectorDescriptor) => c.metadata.id === id);
  const lk = descriptor?.metadata?.labelKey as string | undefined;
  if (lk) {
    return resolveConnectorLabel(lk, meta.value.label);
  }
  return resolveConnectorLabel(connectorMessageKey(id, 'label'), meta.value.label);
});

const catalogDescription = computed(() => {
  const list = connectorsRef?.value ?? [];
  const id = nodeTypeRef.value;
  const descriptor = list.find((c: ConnectorDescriptor) => c.metadata.id === id);
  const dk = descriptor?.metadata?.descriptionKey as string | undefined;
  if (dk) {
    return resolveConnectorLabel(dk, meta.value.description);
  }
  return resolveConnectorLabel(connectorMessageKey(id, 'description'), meta.value.description);
});

const showLeftHandle = computed(() => meta.value.category !== 'trigger');
const showRightHandle = computed(() => true);
const showErrorHandle = computed(() => meta.value.category !== 'trigger');
const isPinned = computed(() => !!props.data?.pinnedOutput);

const handleColor = computed(() => meta.value.color);

const status = computed(() => props.data?.status ?? 'idle');

const statusBadgeClass = computed(() => {
  switch (status.value) {
    case 'success':
      return 'k-bg-knot-success-soft k-text-knot-success';
    case 'error':
      return 'k-bg-knot-danger-soft k-text-knot-danger';
    case 'running':
      return 'k-bg-knot-primary-soft k-text-knot-primary';
    default:
      return 'k-hidden';
  }
});
</script>

<template>
  <div
    class="knot-node"
    :class="[
      'k-group k-relative k-flex k-items-stretch k-rounded-knot-md k-border k-bg-knot-surface k-shadow-knot-sm k-transition-all k-duration-knot k-ease-knot k-min-w-[220px] k-max-w-[260px]',
      props.selected
        ? 'k-border-knot-primary k-shadow-knot-md k-ring-2 k-ring-knot-primary/30'
        : isPinned
          ? 'k-border-violet-400 k-shadow-knot-md k-ring-2 k-ring-violet-400/25'
        : 'k-border-knot-border hover:k-border-knot-primary/60 hover:k-shadow-knot-md',
    ]"
    @mouseenter="onNodeMouseEnter"
    @mouseleave="onNodeMouseLeave"
  >
    <KnotNodeRiskBadge
      v-if="catalogRisk.riskLevel !== 'safe'"
      :risk-level="catalogRisk.riskLevel"
      :icon-name="catalogRisk.iconName"
      :badge-label="catalogRisk.badgeLabel"
    />
    <Handle
      v-if="showLeftHandle"
      type="target"
      :position="Position.Left"
      :style="{
        background: handleColor,
        width: '12px',
        height: '12px',
        border: '2px solid var(--knot-color-surface)',
        boxShadow: '0 0 0 1px ' + handleColor,
      }"
    />

    <div
      class="k-flex k-items-center k-justify-center k-w-12 k-rounded-l-knot-md k-shrink-0"
      :style="{
        background: 'linear-gradient(135deg, ' + handleColor + ' 0%, ' + handleColor + 'cc 100%)',
        color: '#fff',
      }"
    >
      <component :is="Icon" :size="20" :stroke-width="2" />
    </div>

    <div class="k-flex-1 k-min-w-0 k-px-3 k-py-2.5">
      <div class="k-flex k-items-center k-justify-between k-gap-2">
        <div class="k-text-[13px] k-font-semibold k-text-knot-text k-truncate">
          {{ props.data?.label ?? catalogLabel }}
        </div>
        <span
          v-if="isPinned"
          class="k-inline-flex k-items-center k-gap-1 k-px-1.5 k-py-0.5 k-rounded-knot-pill k-text-[10px] k-font-semibold k-bg-violet-500/15 k-text-violet-300"
        >
          pinned
        </span>
        <span
          v-if="status !== 'idle'"
          :class="['k-inline-flex k-items-center k-gap-1 k-px-1.5 k-py-0.5 k-rounded-knot-pill k-text-[10px] k-font-semibold', statusBadgeClass]"
        >
          <CheckCircle2 v-if="status === 'success'" :size="10" />
          <AlertTriangle v-else-if="status === 'error'" :size="10" />
          <Loader2 v-else-if="status === 'running'" :size="10" class="k-animate-spin" />
          {{ status }}
        </span>
      </div>
      <div class="k-text-[11px] k-text-knot-text-soft k-truncate k-mt-0.5">
        {{ props.data?.subtitle ?? catalogDescription }}
      </div>
    </div>

    <KnotNodeSmHintBadge :risk="risk" />

    <Handle
      v-if="showRightHandle"
      id="main"
      type="source"
      :position="Position.Right"
      :style="{
        background: handleColor,
        width: '12px',
        height: '12px',
        border: '2px solid var(--knot-color-surface)',
        boxShadow: '0 0 0 1px ' + handleColor,
      }"
    />

    <Handle
      v-if="showErrorHandle"
      id="error"
      type="source"
      :position="Position.Bottom"
      :style="{
        background: 'var(--knot-color-danger)',
        width: '12px',
        height: '12px',
        border: '2px solid var(--knot-color-surface)',
        boxShadow: '0 0 0 1px var(--knot-color-danger)',
      }"
    />
  </div>
</template>

<style>
.knot-node .vue-flow__handle {
  transition: transform 200ms cubic-bezier(0.22, 1, 0.36, 1);
}
.knot-node:hover .vue-flow__handle {
  transform: scale(1.15);
}
</style>
