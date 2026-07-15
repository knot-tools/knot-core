<!-- Copyright (C) 2026 Knot — GPL-3.0-or-later -->
<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Bell } from 'lucide-vue-next';
import { DOLIBARR_WORKFLOW_TRIGGER_EVENTS } from '@/lib/dolibarrWorkflowTriggers';

const { t } = useI18n();

const props = defineProps<{ modelValue: Record<string, unknown> }>();
const emit = defineEmits<{ (e: 'update:modelValue', v: Record<string, unknown>): void }>();

const EVENTS = DOLIBARR_WORKFLOW_TRIGGER_EVENTS;

const events = computed({
  get: () => (Array.isArray(props.modelValue?.events) ? props.modelValue.events as string[] : []),
  set: (v) => emit('update:modelValue', { ...props.modelValue, events: v }),
});

function toggle(ev: string) {
  const cur = events.value.slice();
  const i = cur.indexOf(ev);
  if (i >= 0) cur.splice(i, 1); else cur.push(ev);
  events.value = cur;
}
</script>

<template>
  <div class="k-space-y-3">
    <div class="k-flex k-items-center k-gap-2 k-text-knot-primary">
      <Bell :size="14" /><span class="k-text-xs k-font-bold k-uppercase k-tracking-wider">{{ t('dolibarrEventPanel.title') }}</span>
    </div>
    <p class="k-text-[11px] k-text-knot-text-muted k-m-0">{{ t('dolibarrEventPanel.selectLabel') }}</p>
    <div class="k-grid k-grid-cols-2 k-gap-1.5 k-max-h-60 k-overflow-y-auto k-pr-1">
      <label v-for="ev in EVENTS" :key="ev" class="k-flex k-items-center k-gap-1.5 k-px-2 k-py-1 k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border hover:k-border-knot-primary k-cursor-pointer">
        <input type="checkbox" :checked="events.includes(ev)" @change="toggle(ev)" />
        <span class="k-text-[11px] k-font-mono k-text-knot-text">{{ ev }}</span>
      </label>
    </div>
    <p class="k-text-[10px] k-text-knot-text-muted k-m-0">
      {{ t('dolibarrEventPanel.selectedCount', { count: events.length }) }}
    </p>
    <p class="k-text-[10px] k-text-knot-text-muted" v-html="t('dolibarrEventPanel.hint')"></p>
  </div>
</template>
