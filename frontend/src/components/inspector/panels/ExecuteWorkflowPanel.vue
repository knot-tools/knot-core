<!-- Copyright (C) 2026 Knot — GPL-3.0-or-later -->
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { GitBranch } from 'lucide-vue-next';
import { knotApi } from '../../../lib/api';

const props = defineProps<{ modelValue: Record<string, unknown> }>();
const emit = defineEmits<{ (e: 'update:modelValue', v: Record<string, unknown>): void }>();

interface SimpleWorkflow { id: number; label: string; status: string }
const workflows = ref<SimpleWorkflow[]>([]);

const targetWorkflowId = computed({
  get: () => Number(props.modelValue?.targetWorkflowId ?? 0),
  set: (v) => emit('update:modelValue', { ...props.modelValue, targetWorkflowId: v }),
});
const payload = computed({
  get: () => props.modelValue?.payload ?? {},
  set: (v) => emit('update:modelValue', { ...props.modelValue, payload: v }),
});

onMounted(async () => {
  try {
    const result = await knotApi.listWorkflows();
    workflows.value = (result.workflows || []).filter((w) => w.status === 'active');
  } catch { /* noop */ }
});
</script>

<template>
  <div class="k-space-y-3">
    <div class="k-flex k-items-center k-gap-2 k-text-knot-primary">
      <GitBranch :size="14" /><span class="k-text-xs k-font-bold k-uppercase k-tracking-wider">Execute Workflow</span>
    </div>
    <div class="k-space-y-1">
      <label class="k-text-[11px] k-text-knot-text-soft k-font-bold">Target workflow</label>
      <select v-model.number="targetWorkflowId" class="k-w-full k-px-2 k-py-1.5 k-text-sm k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text">
        <option :value="0">— select an active workflow —</option>
        <option v-for="wf in workflows" :key="wf.id" :value="wf.id">{{ wf.label }} (#{{ wf.id }})</option>
      </select>
    </div>
    <div class="k-space-y-1">
      <label class="k-text-[11px] k-text-knot-text-soft k-font-bold">Payload (JSON)</label>
      <textarea
        :value="JSON.stringify(payload, null, 2)"
        rows="5"
        @input="(e) => { try { payload = JSON.parse((e.target as HTMLTextAreaElement).value || '{}'); } catch {} }"
        class="k-w-full k-px-2 k-py-1.5 k-text-xs k-font-mono k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text"
      ></textarea>
    </div>
  </div>
</template>
