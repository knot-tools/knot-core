<!--
  KnotEdge — Vue Flow custom edge.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, ref } from 'vue';
import {
  BaseEdge,
  EdgeLabelRenderer,
  getSmoothStepPath,
  useVueFlow,
  type EdgeProps,
} from '@vue-flow/core';
import { X } from 'lucide-vue-next';
import { deriveEdgeType, edgeStrokeColor, handleLabelKey } from '@/lib/edgeSemantics';
import { useI18n } from 'vue-i18n';

interface KnotEdgeData {
  type?: 'success' | 'error' | 'conditional' | 'true' | 'false' | 'iteration' | 'done';
}

const props = defineProps<EdgeProps<KnotEdgeData>>();
const { t } = useI18n();
const { removeEdges } = useVueFlow();
const hovered = ref(false);

const semanticType = computed(() => props.data?.type ?? deriveEdgeType(props.sourceHandleId));

const path = computed(() =>
  getSmoothStepPath({
    sourceX: props.sourceX,
    sourceY: props.sourceY,
    sourcePosition: props.sourcePosition,
    targetX: props.targetX,
    targetY: props.targetY,
    targetPosition: props.targetPosition,
    borderRadius: 16,
  }),
);

const color = computed(() => edgeStrokeColor(semanticType.value));
const flowDotId = computed(() => `knot-edge-dot-${props.id}`);

const labelKey = computed(() => {
  const h = props.sourceHandleId;
  return h ? handleLabelKey(h) : undefined;
});

const labelText = computed(() => {
  if (!labelKey.value || semanticType.value === 'success') return '';
  if (!hovered.value && !props.selected) return '';
  const translated = t(labelKey.value);
  return translated !== labelKey.value ? translated : '';
});

const showDelete = computed(() => props.selected || hovered.value);
const strokeWidth = computed(() => {
  if (props.selected) return 2.5;
  if (hovered.value) return 2.5;
  return 2;
});

function removeEdge() {
  removeEdges([props.id]);
}
</script>

<template>
  <!-- Solid stroke (not SVG gradient url()) so edges paint on first layout —
       gradients in fragmented edge SVGs often stay invisible until a node
       drag forces remeasure. -->
  <path
    :d="path[0]"
    fill="none"
    stroke="transparent"
    stroke-width="18"
    class="knot-edge-hit"
    @mouseenter="hovered = true"
    @mouseleave="hovered = false"
  />
  <BaseEdge
    :id="props.id"
    :path="path[0]"
    :style="{
      stroke: color,
      strokeWidth,
      strokeDasharray: props.animated ? '8 6' : undefined,
      cursor: 'pointer',
    }"
    :marker-end="props.markerEnd"
    class="knot-edge-path"
    @mouseenter="hovered = true"
    @mouseleave="hovered = false"
  />
  <circle
    v-if="props.animated"
    :id="flowDotId"
    r="4"
    :fill="color"
    class="knot-edge-flow-dot"
  >
    <animateMotion dur="1.1s" repeatCount="indefinite" :path="path[0]" />
  </circle>
  <EdgeLabelRenderer v-if="labelText || showDelete">
    <div
      :style="{
        position: 'absolute',
        transform: `translate(-50%, -50%) translate(${path[1]}px, ${path[2]}px)`,
        pointerEvents: 'all',
      }"
      class="knot-edge-label-wrap"
      @mouseenter="hovered = true"
      @mouseleave="hovered = false"
    >
      <span v-if="labelText" class="knot-edge-label">{{ labelText }}</span>
      <button
        v-if="showDelete"
        type="button"
        class="knot-edge-delete"
        :title="t('editor.deleteEdge')"
        @click.stop="removeEdge"
      >
        <X :size="12" />
      </button>
    </div>
  </EdgeLabelRenderer>
</template>

<style>
@keyframes knot-edge-flow {
  to {
    stroke-dashoffset: -28;
  }
}
.vue-flow__edge.animated .knot-edge-path {
  animation: knot-edge-flow 0.9s linear infinite;
}
.knot-edge-hit {
  pointer-events: stroke;
}
.knot-edge-flow-dot {
  filter: drop-shadow(0 0 4px currentColor);
  pointer-events: none;
}
.knot-edge-label-wrap {
  display: flex;
  align-items: center;
  gap: 4px;
}
.knot-edge-label {
  font-size: 10px;
  font-weight: 600;
  padding: 2px 6px;
  border-radius: 4px;
  background: var(--knot-color-surface);
  border: 1px solid var(--knot-color-border);
  color: var(--knot-color-text-soft);
}
.knot-edge-delete {
  display: flex;
  align-items: center;
  justify-content: center;
  width: 20px;
  height: 20px;
  border-radius: 999px;
  border: 1px solid var(--knot-color-border);
  background: var(--knot-color-surface);
  color: var(--knot-color-danger);
  cursor: pointer;
}
.knot-edge-delete:hover {
  background: var(--knot-color-danger-soft);
}
</style>
