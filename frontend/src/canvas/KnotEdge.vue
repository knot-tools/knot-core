<!--
  KnotEdge — Vue Flow custom edge.
  Copyright (C) 2026 Knot — GPL-3.0-or-later

  Coloured per `data.type` (success / error / conditional). Supports animation.
-->
<script setup lang="ts">
import { computed } from 'vue';
import { BaseEdge, getSmoothStepPath, type EdgeProps } from '@vue-flow/core';

interface KnotEdgeData {
  type?: 'success' | 'error' | 'conditional';
}

const props = defineProps<EdgeProps<KnotEdgeData>>();

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

const color = computed(() => {
  switch (props.data?.type) {
    case 'error':
      return '#ef4444';
    case 'conditional':
      return '#f59e0b';
    case 'success':
    default:
      return '#6366f1';
  }
});
</script>

<template>
  <BaseEdge
    :id="props.id"
    :path="path[0]"
    :style="{
      stroke: color,
      strokeWidth: 2,
      strokeDasharray: props.animated ? '6 6' : undefined,
    }"
    :marker-end="props.markerEnd"
  />
</template>

<style>
@keyframes knot-edge-flow {
  to {
    stroke-dashoffset: -24;
  }
}
.vue-flow__edge.animated .vue-flow__edge-path {
  animation: knot-edge-flow 1s linear infinite;
}
</style>
