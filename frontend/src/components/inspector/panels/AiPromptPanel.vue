<!-- Copyright (C) 2026 Knot — GPL-3.0-or-later -->
<script setup lang="ts">
import { computed } from 'vue';
import { Sparkles } from 'lucide-vue-next';

const props = defineProps<{ modelValue: Record<string, unknown> }>();
const emit = defineEmits<{ (e: 'update:modelValue', v: Record<string, unknown>): void }>();

const prompt = computed({
  get: () => String(props.modelValue?.prompt ?? ''),
  set: (v) => emit('update:modelValue', { ...props.modelValue, prompt: v }),
});
const model = computed({
  get: () => String(props.modelValue?.model ?? 'gpt-4o-mini'),
  set: (v) => emit('update:modelValue', { ...props.modelValue, model: v }),
});
const temperature = computed({
  get: () => Number(props.modelValue?.temperature ?? 0.7),
  set: (v) => emit('update:modelValue', { ...props.modelValue, temperature: v }),
});
const credentialRef = computed({
  get: () => String(props.modelValue?.credentialRef ?? ''),
  set: (v) => emit('update:modelValue', { ...props.modelValue, credentialRef: v }),
});

const tokenEstimate = computed(() => Math.ceil((prompt.value || '').length / 4));
const detectedVars = computed(() => {
  const matches = (prompt.value || '').match(/{{[^}]+}}/g) || [];
  return [...new Set(matches)];
});

/** Literal hint; cannot use {{ }} in a static placeholder attribute (Vue template). */
const promptPlaceholderExample =
  'You are a Knot assistant. Summarize {{$json.message}}…';
</script>

<template>
  <div class="k-space-y-3">
    <div class="k-flex k-items-center k-gap-2 k-text-knot-primary">
      <Sparkles :size="14" /><span class="k-text-xs k-font-bold k-uppercase k-tracking-wider">AI Prompt</span>
    </div>
    <div class="k-grid k-grid-cols-[1fr_100px] k-gap-2">
      <div class="k-space-y-1">
        <label class="k-text-[11px] k-text-knot-text-soft k-font-bold">Model</label>
        <input v-model="model" placeholder="gpt-4o-mini" class="k-w-full k-px-2 k-py-1.5 k-text-sm k-font-mono k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text" />
      </div>
      <div class="k-space-y-1">
        <label class="k-text-[11px] k-text-knot-text-soft k-font-bold">Temp</label>
        <input type="number" step="0.1" min="0" max="2" v-model.number="temperature" class="k-w-full k-px-2 k-py-1.5 k-text-sm k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text" />
      </div>
    </div>
    <div class="k-space-y-1">
      <label class="k-text-[11px] k-text-knot-text-soft k-font-bold">Credential ref</label>
      <input v-model="credentialRef" placeholder="credential:openai:default" class="k-w-full k-px-2 k-py-1.5 k-text-sm k-font-mono k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text" />
    </div>
    <div class="k-space-y-1">
      <label class="k-text-[11px] k-text-knot-text-soft k-font-bold k-flex k-justify-between">
        <span>Prompt</span>
        <span class="k-text-knot-text-muted k-font-normal">~{{ tokenEstimate }} tokens</span>
      </label>
      <textarea
        v-model="prompt"
        rows="6"
        :placeholder="promptPlaceholderExample"
        class="k-w-full k-px-2 k-py-1.5 k-text-xs k-font-mono k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text"
      ></textarea>
    </div>
    <div v-if="detectedVars.length" class="k-flex k-flex-wrap k-gap-1">
      <span class="k-text-[10px] k-text-knot-text-muted">Variables:</span>
      <code v-for="v in detectedVars" :key="v" class="k-text-[10px] k-bg-knot-primary-soft k-text-knot-primary k-px-1.5 k-py-0.5 k-rounded-knot-sm">{{ v }}</code>
    </div>
  </div>
</template>
