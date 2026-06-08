<!--
  KnotNode — universal Knot Vue Flow node.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, inject } from 'vue';
import { Handle, Position, useVueFlow, type NodeProps } from '@vue-flow/core';
import { CheckCircle2, AlertTriangle, Loader2, Plus } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import { iconGradientStops, resolveNodeMeta } from './nodeRegistry';
import { connectorMessageKey, resolveConnectorLabel } from '@/lib/connectorLabels';
import { useCanvasChangeStatusRisk } from '../composables/useCanvasChangeStatusRisk';
import { useNodeRisk, type ConnectorRiskMetadata } from '../composables/useNodeRisk';
import KnotNodeSmHintBadge from './KnotNodeSmHintBadge.vue';
import KnotNodeRiskBadge from '../components/risk/KnotNodeRiskBadge.vue';
import { KNOT_CONNECTORS_KEY } from '../lib/knotConnectorContext';
import { KNOT_QUICK_ADD_KEY } from '../lib/knotQuickAddContext';
import type { ConnectorDescriptor } from '../lib/api';
import {
  defaultOutputsForCategory,
  handleColor,
  handleLabelKey,
  layoutForOutput,
} from '@/lib/edgeSemantics';

interface KnotNodeData {
  type: string;
  label?: string;
  subtitle?: string;
  status?: 'idle' | 'running' | 'success' | 'error';
  config?: Record<string, unknown>;
  pinnedOutput?: Record<string, unknown> | null;
  branchDimmed?: boolean;
  dimmedHandles?: string[];
}

const props = defineProps<NodeProps<KnotNodeData>>();
const { t } = useI18n();
const { edges } = useVueFlow();

const nodeTypeRef = computed(() => props.data?.type ?? '');
const configRef = computed(() => (props.data?.config ?? {}) as Record<string, unknown>);
const selectedRef = computed(() => props.selected);

const { risk, onNodeMouseEnter, onNodeMouseLeave } = useCanvasChangeStatusRisk({
  nodeType: nodeTypeRef,
  config: configRef,
  selected: selectedRef,
});

const connectorsRef = inject(KNOT_CONNECTORS_KEY, undefined);
const quickAddRef = inject(KNOT_QUICK_ADD_KEY, undefined);

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
const iconGradient = computed(() => iconGradientStops(meta.value.color));

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
const isPinned = computed(() => !!props.data?.pinnedOutput);
const status = computed(() => props.data?.status ?? 'idle');
const branchDimmed = computed(() => !!props.data?.branchDimmed);
const dimmedHandles = computed(() => new Set(props.data?.dimmedHandles ?? []));

const sourceOutputs = computed(() => {
  const list = connectorsRef?.value ?? [];
  const descriptor = list.find((c: ConnectorDescriptor) => c.metadata.id === nodeTypeRef.value);
  const outs = descriptor?.outputs;
  if (Array.isArray(outs) && outs.length > 0) {
    return outs.map((o) => ({
      id: String((o as { id?: string }).id ?? 'main'),
      label: String((o as { label?: string }).label ?? 'Main'),
    }));
  }
  return defaultOutputsForCategory(meta.value.category);
});

const outputLayouts = computed(() =>
  sourceOutputs.value.map((output) => ({
    ...output,
    ...layoutForOutput(output.id, meta.value.category, meta.value.color),
  })),
);

const connectedSourceHandles = computed(() => {
  const set = new Set<string>();
  for (const e of edges.value) {
    if (e.source === props.id && e.sourceHandle) {
      set.add(String(e.sourceHandle));
    }
  }
  return set;
});

function handlePosition(pos: string): Position {
  switch (pos) {
    case 'right-top':
    case 'right-bottom':
    case 'right':
      return Position.Right;
    case 'bottom':
      return Position.Bottom;
    case 'left':
      return Position.Left;
    default:
      return Position.Right;
  }
}

function handleStyle(color: string, position: string): Record<string, string> {
  const base: Record<string, string> = {
    background: color,
    width: '12px',
    height: '12px',
    border: '2px solid var(--knot-color-surface)',
    boxShadow: `0 0 0 1px ${color}`,
  };
  switch (position) {
    case 'right-top':
      base.top = '28%';
      base.right = '-6px';
      break;
    case 'right-bottom':
      base.top = '72%';
      base.right = '-6px';
      break;
    case 'right':
      base.right = '-6px';
      break;
    case 'bottom':
      base.left = '50%';
      base.bottom = '-6px';
      base.transform = 'translateX(-50%)';
      break;
    default:
      break;
  }
  return base;
}

function handleClasses(outputId: string): Record<string, boolean> {
  const connected = isHandleConnected(outputId);
  return {
    'knot-handle--idle': !connected && !props.selected,
    'knot-handle--visible': connected || !!props.selected,
    'knot-handle--dimmed': dimmedHandles.value.has(outputId),
  };
}

function isHandleConnected(id: string): boolean {
  return connectedSourceHandles.value.has(id);
}

function onQuickAdd(handleId: string, event: MouseEvent) {
  event.stopPropagation();
  quickAddRef?.startQuickAdd(props.id, handleId);
}

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

const executionHaloClass = computed(() => {
  switch (status.value) {
    case 'running':
      return 'knot-node--running';
    case 'success':
      return 'knot-node--success';
    case 'error':
      return 'knot-node--error';
    default:
      return '';
  }
});
</script>

<template>
  <div
    class="knot-node"
    :class="[
      'k-group k-relative k-flex k-items-stretch k-rounded-knot-md k-border k-bg-knot-surface k-shadow-knot-sm k-transition-all k-duration-knot k-ease-knot k-min-w-[220px] k-max-w-[260px] knot-node--enter',
      executionHaloClass,
      branchDimmed ? 'knot-node--dimmed' : '',
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
      id="main"
      type="target"
      :position="Position.Left"
      :style="{
        background: handleColor('main', meta.color),
        width: '12px',
        height: '12px',
        border: '2px solid var(--knot-color-surface)',
        boxShadow: '0 0 0 1px ' + meta.color,
        left: '-6px',
        top: '50%',
        transform: 'translateY(-50%)',
      }"
    />

    <div
      class="k-flex k-items-center k-justify-center k-w-12 k-rounded-l-knot-md k-shrink-0 knot-node__icon"
      :style="{
        background: `linear-gradient(145deg, ${iconGradient.start} 0%, ${iconGradient.mid} 55%, ${iconGradient.end} 100%)`,
        color: '#fff',
      }"
    >
      <component :is="Icon" :size="20" :stroke-width="2" />
    </div>

    <div class="k-flex-1 k-min-w-0 k-px-3 k-py-2.5">
      <div class="k-flex k-items-center k-justify-between k-gap-2">
        <div class="k-text-[13px] k-font-semibold k-text-knot-text k-truncate k-tracking-tight">
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

    <div
      v-for="output in outputLayouts"
      :key="output.id"
      class="knot-node__handle-anchor"
    >
      <Handle
        :id="output.id"
        type="source"
        :position="handlePosition(output.position)"
        :style="handleStyle(output.color, output.position)"
        :class="handleClasses(output.id)"
      />
      <span
        v-if="handleLabelKey(output.id)"
        class="knot-node__handle-label"
        :class="`knot-node__handle-label--${output.position}`"
      >
        {{ t(handleLabelKey(output.id)!) }}
      </span>
      <button
        v-if="quickAddRef"
        type="button"
        class="knot-node__quick-add"
        :class="`knot-node__quick-add--${output.position}`"
        :title="t('editor.quickAdd')"
        @click="onQuickAdd(output.id, $event)"
      >
        <Plus :size="10" />
      </button>
    </div>
  </div>
</template>

<style>
.knot-node {
  overflow: visible;
  box-shadow:
    0 1px 2px rgb(15 23 42 / 6%),
    0 4px 12px rgb(15 23 42 / 4%);
}
.knot-node__handle-anchor {
  position: absolute;
  inset: 0;
  pointer-events: none;
}
.knot-node__handle-anchor .vue-flow__handle {
  pointer-events: all;
}
.knot-node--enter {
  animation: knot-node-enter 220ms cubic-bezier(0.22, 1, 0.36, 1) both;
}
@keyframes knot-node-enter {
  from {
    opacity: 0;
    transform: scale(0.94);
  }
  to {
    opacity: 1;
    transform: scale(1);
  }
}
.knot-node--running {
  box-shadow: 0 0 0 2px rgb(99 102 241 / 25%), 0 0 18px rgb(99 102 241 / 20%);
  animation: knot-node-pulse 1.4s ease-in-out infinite;
}
.knot-node--success {
  box-shadow: 0 0 0 2px rgb(34 197 94 / 25%), 0 0 12px rgb(34 197 94 / 15%);
}
.knot-node--error {
  box-shadow: 0 0 0 2px rgb(239 68 68 / 30%), 0 0 12px rgb(239 68 68 / 18%);
}
.knot-node--dimmed {
  opacity: 0.45;
  filter: grayscale(0.35);
}
@keyframes knot-node-pulse {
  0%,
  100% {
    box-shadow: 0 0 0 2px rgb(99 102 241 / 20%), 0 0 12px rgb(99 102 241 / 12%);
  }
  50% {
    box-shadow: 0 0 0 3px rgb(99 102 241 / 35%), 0 0 22px rgb(99 102 241 / 28%);
  }
}
.knot-node .vue-flow__handle {
  transition: opacity 200ms ease, box-shadow 200ms ease;
}
.knot-node:hover .vue-flow__handle,
.knot-node .vue-flow__handle.knot-handle--visible {
  box-shadow: 0 0 0 2px var(--knot-color-primary-soft);
}
.knot-node .vue-flow__handle.knot-handle--idle {
  opacity: 0.35;
}
.knot-node:hover .vue-flow__handle.knot-handle--idle,
.knot-node .vue-flow__handle.knot-handle--visible {
  opacity: 1;
}
.knot-node .vue-flow__handle.knot-handle--dimmed {
  opacity: 0.25 !important;
  filter: grayscale(0.6);
}
.knot-node__handle-label {
  position: absolute;
  font-size: 9px;
  font-weight: 600;
  color: var(--knot-color-text-soft);
  white-space: nowrap;
  pointer-events: none;
  opacity: 0;
  z-index: 3;
  transition: opacity 150ms ease;
}
.knot-node__handle-label--right,
.knot-node__handle-label--right-top,
.knot-node__handle-label--right-bottom {
  left: calc(100% + 8px);
  right: auto;
  transform: translateY(-50%);
}
.knot-node__handle-label--right {
  top: 50%;
}
.knot-node__handle-label--right-top {
  top: 28%;
}
.knot-node__handle-label--right-bottom {
  top: 72%;
}
.knot-node__handle-label--bottom {
  left: 50%;
  top: calc(100% + 8px);
  bottom: auto;
  transform: translateX(-50%);
}
.knot-node:hover .knot-node__handle-label {
  opacity: 1;
}
.knot-node__quick-add {
  position: absolute;
  display: flex;
  align-items: center;
  justify-content: center;
  width: 16px;
  height: 16px;
  border-radius: 999px;
  border: 1px solid var(--knot-color-border);
  background: var(--knot-color-surface);
  color: var(--knot-color-primary);
  opacity: 0;
  pointer-events: none;
  transition: opacity 150ms ease;
  cursor: pointer;
  z-index: 2;
}
.knot-node__quick-add--right,
.knot-node__quick-add--right-top,
.knot-node__quick-add--right-bottom {
  left: calc(100% + 28px);
  right: auto;
  transform: translateY(-50%);
}
.knot-node__quick-add--right {
  top: 50%;
}
.knot-node__quick-add--right-top {
  top: 28%;
}
.knot-node__quick-add--right-bottom {
  top: 72%;
}
.knot-node__quick-add--bottom {
  left: 50%;
  bottom: -22px;
  transform: translateX(-50%);
}
.knot-node:hover .knot-node__quick-add {
  opacity: 1;
  pointer-events: all;
}
</style>
