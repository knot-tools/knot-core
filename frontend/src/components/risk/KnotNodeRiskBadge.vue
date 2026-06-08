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
      'k-pointer-events-none',
      'k-absolute k-inset-0',
      'k-rounded-knot-md',
      borderClass,
      tintClass,
    ]"
    :data-risk="riskLevel"
  >
    <div
      v-if="iconName || badgeLabel"
      class="k-absolute k--top-3 k-right-1 k-flex k-items-center k-gap-1 k-rounded-knot-pill k-px-2 k-py-0.5 k-text-[10px] k-font-semibold k-uppercase k-tracking-wider k-shadow-knot-sm k-z-10"
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
      return 'k-border-2 k-border-red-500/80';
    case 'caution':
      return 'k-border-2 k-border-amber-400/80';
    default:
      return '';
  }
});

const tintClass = computed(() => {
  switch (props.riskLevel) {
    case 'critical':
      return 'k-bg-red-500/[0.04]';
    case 'caution':
      return 'k-bg-amber-400/[0.04]';
    default:
      return '';
  }
});

const pillClass = computed(() => {
  switch (props.riskLevel) {
    case 'critical':
      return 'k-bg-red-500 k-text-white';
    case 'caution':
      return 'k-bg-amber-400 k-text-amber-950';
    default:
      return 'k-bg-slate-200 k-text-slate-700';
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
