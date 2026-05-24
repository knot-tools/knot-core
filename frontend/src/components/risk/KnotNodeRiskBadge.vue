<!--
  KnotNodeRiskBadge — V2.5 UX-3
  Renders the visual risk indicator on a canvas node:
    - safe    : nothing (no decoration)
    - caution : amber border + AlertTriangle icon top-right
    - critical: red border + OctagonAlert icon top-right + "CRITICAL" label

  Pure presentational component — does NOT compute the risk itself.
  Wire it via `useNodeRisk(...)` in the parent canvas node.

  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<template>
  <div
    :class="[
      'knot-risk-badge',
      'pointer-events-none',
      'absolute inset-0',
      'rounded-xl',
      borderClass,
      tintClass,
    ]"
    :data-risk="riskLevel"
  >
    <div
      v-if="iconName || badgeLabel"
      class="absolute -top-2 -right-2 flex items-center gap-1 rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider shadow-sm"
      :class="pillClass"
    >
      <component :is="iconComponent" v-if="iconComponent" :size="12" :stroke-width="2.5" />
      <span v-if="badgeLabel">{{ badgeLabel }}</span>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { AlertTriangle, OctagonAlert } from 'lucide-vue-next';
import type { NodeRiskResult, RiskLevel } from '../../composables/useNodeRisk';

const props = defineProps<{
  riskLevel: RiskLevel;
  iconName?: NodeRiskResult['iconName'];
  badgeLabel?: NodeRiskResult['badgeLabel'];
}>();

const borderClass = computed(() => {
  switch (props.riskLevel) {
    case 'critical':
      return 'border-2 border-red-500/80';
    case 'caution':
      return 'border-2 border-amber-400/80';
    default:
      return '';
  }
});

const tintClass = computed(() => {
  switch (props.riskLevel) {
    case 'critical':
      return 'bg-red-500/[0.04]';
    case 'caution':
      return 'bg-amber-400/[0.04]';
    default:
      return '';
  }
});

const pillClass = computed(() => {
  switch (props.riskLevel) {
    case 'critical':
      return 'bg-red-500 text-white';
    case 'caution':
      return 'bg-amber-400 text-amber-950';
    default:
      return 'bg-slate-200 text-slate-700';
  }
});

const iconComponent = computed(() => {
  switch (props.iconName) {
    case 'octagon-alert':
      return OctagonAlert;
    case 'alert-triangle':
      return AlertTriangle;
    default:
      return null;
  }
});
</script>
