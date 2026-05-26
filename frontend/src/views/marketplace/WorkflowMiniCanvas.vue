<!--
  Lazy-mounted mini workflow canvas for cards and discovery rows.
  Renders a small but realistic node graph (trigger → action → action),
  not a flat row of identical chips — feedback May 2026: previous version
  read as "skeleton loader stuck on forever".
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Workflow as WorkflowIcon } from 'lucide-vue-next';

import { useLazyMount } from '../../composables/useLazyMount';

type NodeKind = 'trigger' | 'action' | 'decision' | 'output';
interface MiniNode {
  id: string;
  kind: NodeKind;
  x: number;
  y: number;
  label?: string;
}
interface MiniEdge {
  from: string;
  to: string;
}

const props = withDefaults(
  defineProps<{
    nodes?: number;
    edges?: number;
    height?: number;
    accent?: 'primary' | 'pro' | 'migration' | 'enterprise';
  }>(),
  { nodes: 5, edges: 4, height: 132, accent: 'primary' },
);

const { t } = useI18n();
const root = ref<HTMLElement | null>(null);
const visible = useLazyMount(root);

const palette = computed(() => {
  switch (props.accent) {
    case 'pro':
      return {
        trigger: '#7c3aed',
        action: '#a855f7',
        decision: '#f472b6',
        output: '#6366f1',
        edge: '#a855f7',
      };
    case 'migration':
      return {
        trigger: '#047857',
        action: '#10b981',
        decision: '#34d399',
        output: '#0f766e',
        edge: '#10b981',
      };
    case 'enterprise':
      return {
        trigger: '#a16207',
        action: '#d4a017',
        decision: '#eab308',
        output: '#92400e',
        edge: '#d4a017',
      };
    default:
      return {
        trigger: '#6366f1',
        action: '#818cf8',
        decision: '#06b6d4',
        output: '#3b82f6',
        edge: '#6366f1',
      };
  }
});

const graph = computed<{ nodes: MiniNode[]; edges: MiniEdge[] }>(() => {
  const target = Math.min(7, Math.max(3, props.nodes || 4));
  const columns = Math.min(4, Math.max(2, Math.ceil(target / 2)));
  const colStep = 240 / (columns + 1);
  const nodes: MiniNode[] = [];
  for (let i = 0; i < target; i++) {
    const col = Math.min(columns - 1, Math.floor(i / Math.ceil(target / columns)));
    const row = i % Math.ceil(target / columns);
    const x = colStep * (col + 1);
    const baseY = 50;
    const y = baseY + (row - 0.5) * 30;
    let kind: NodeKind = 'action';
    if (i === 0) kind = 'trigger';
    else if (i === target - 1) kind = 'output';
    else if (i === Math.floor(target / 2)) kind = 'decision';
    nodes.push({ id: `n${i}`, kind, x, y });
  }
  const edges: MiniEdge[] = [];
  for (let i = 0; i < nodes.length - 1; i++) {
    edges.push({ from: nodes[i]!.id, to: nodes[i + 1]!.id });
  }
  // Add a few cross-links to suggest branching when edges > nodes - 1
  const extra = Math.max(0, (props.edges || 0) - (nodes.length - 1));
  for (let k = 0; k < extra && k < 2; k++) {
    const from = nodes[k]?.id;
    const to = nodes[Math.min(nodes.length - 1, k + 2)]?.id;
    if (from && to && from !== to) {
      edges.push({ from, to });
    }
  }
  return { nodes, edges };
});

function nodeById(id: string): MiniNode | undefined {
  return graph.value.nodes.find((n) => n.id === id);
}

function edgePath(edge: MiniEdge): string {
  const from = nodeById(edge.from);
  const to = nodeById(edge.to);
  if (!from || !to) return '';
  const mx = (from.x + to.x) / 2;
  return `M ${from.x + 14} ${from.y} C ${mx} ${from.y}, ${mx} ${to.y}, ${to.x - 14} ${to.y}`;
}

function nodeFill(kind: NodeKind): string {
  switch (kind) {
    case 'trigger':
      return palette.value.trigger;
    case 'decision':
      return palette.value.decision;
    case 'output':
      return palette.value.output;
    default:
      return palette.value.action;
  }
}
</script>

<template>
  <div
    ref="root"
    class="km-mini-canvas k-relative k-overflow-hidden k-rounded-knot-md k-border k-border-knot-border"
    :style="{ minHeight: `${height}px` }"
    role="img"
    :aria-label="t('marketplace.miniPreviewLegend', { nodes: props.nodes, edges: props.edges })"
  >
    <div
      v-if="!visible"
      class="k-absolute k-inset-0 k-animate-pulse"
      style="background: var(--knot-skeleton-base)"
      aria-hidden="true"
    />
    <div v-else class="k-flex k-h-full k-flex-col k-p-3">
      <div class="k-flex k-items-center k-gap-1.5 k-text-[11px] k-font-semibold k-text-knot-text-muted">
        <WorkflowIcon :size="12" aria-hidden="true" />
        {{ t('marketplace.miniPreviewLegend', { nodes: props.nodes, edges: props.edges }) }}
      </div>
      <svg
        class="k-mt-2 k-w-full k-flex-1"
        viewBox="0 0 240 100"
        preserveAspectRatio="xMidYMid meet"
        aria-hidden="true"
      >
        <defs>
          <linearGradient id="km-mini-edge-gradient" x1="0" y1="0" x2="1" y2="0">
            <stop offset="0%" :stop-color="palette.edge" stop-opacity="0.85" />
            <stop offset="100%" :stop-color="palette.edge" stop-opacity="0.35" />
          </linearGradient>
        </defs>
        <path
          v-for="(edge, idx) in graph.edges"
          :key="`e-${idx}`"
          :d="edgePath(edge)"
          stroke="url(#km-mini-edge-gradient)"
          stroke-width="1.6"
          fill="none"
        />
        <g
          v-for="node in graph.nodes"
          :key="node.id"
        >
          <rect
            :x="node.x - 14"
            :y="node.y - 9"
            width="28"
            height="18"
            rx="6"
            :fill="nodeFill(node.kind)"
            :opacity="node.kind === 'decision' ? 0.85 : 1"
          />
          <circle
            v-if="node.kind === 'trigger'"
            :cx="node.x"
            :cy="node.y"
            r="3"
            fill="#fff"
          />
          <path
            v-else-if="node.kind === 'decision'"
            :d="`M ${node.x - 3} ${node.y} L ${node.x} ${node.y - 3} L ${node.x + 3} ${node.y} L ${node.x} ${node.y + 3} Z`"
            fill="#fff"
          />
          <path
            v-else-if="node.kind === 'output'"
            :d="`M ${node.x - 3} ${node.y - 3} L ${node.x + 3} ${node.y} L ${node.x - 3} ${node.y + 3} Z`"
            fill="#fff"
          />
          <rect
            v-else
            :x="node.x - 2"
            :y="node.y - 2"
            width="4"
            height="4"
            rx="1"
            fill="#fff"
          />
        </g>
      </svg>
    </div>
  </div>
</template>

<style scoped>
.km-mini-canvas {
  background:
    radial-gradient(circle at 20% 20%, rgba(99, 102, 241, 0.08), transparent 55%),
    radial-gradient(circle at 80% 70%, rgba(168, 85, 247, 0.06), transparent 60%),
    var(--knot-color-surface, #fff);
}
</style>
