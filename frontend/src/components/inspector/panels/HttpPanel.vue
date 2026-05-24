<!-- Copyright (C) 2026 Knot — GPL-3.0-or-later -->
<script setup lang="ts">
import { computed } from 'vue';
import { Globe } from 'lucide-vue-next';

const props = defineProps<{ modelValue: Record<string, unknown> }>();
const emit = defineEmits<{ (e: 'update:modelValue', v: Record<string, unknown>): void }>();

const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'HEAD', 'OPTIONS'];

function field<T>(name: string, fallback: T) {
  return computed({
    get: () => (props.modelValue?.[name] ?? fallback) as T,
    set: (v: T) => emit('update:modelValue', { ...props.modelValue, [name]: v }),
  });
}
const method = field('method', 'GET');
const url = field('url', '');
const headers = field<Record<string, string>>('headers', {});
const body = field('body', {});
const timeoutMs = field<number>('timeoutMs', 30000);
</script>

<template>
  <div class="k-space-y-3">
    <div class="k-flex k-items-center k-gap-2 k-text-knot-primary">
      <Globe :size="14" /><span class="k-text-xs k-font-bold k-uppercase k-tracking-wider">HTTP Request</span>
    </div>
    <div class="k-grid k-grid-cols-[100px_1fr] k-gap-2">
      <select v-model="method" class="k-px-2 k-py-1.5 k-text-sm k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text">
        <option v-for="m in METHODS" :key="m" :value="m">{{ m }}</option>
      </select>
      <input v-model="url" placeholder="https://api.example.com/v1/path" class="k-px-2 k-py-1.5 k-text-sm k-font-mono k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text" />
    </div>
    <div class="k-space-y-1">
      <label class="k-text-[11px] k-text-knot-text-soft k-font-bold">Headers</label>
      <textarea
        :value="JSON.stringify(headers, null, 2)"
        rows="3"
        @input="(e) => { try { headers = JSON.parse((e.target as HTMLTextAreaElement).value || '{}'); } catch {} }"
        class="k-w-full k-px-2 k-py-1.5 k-text-xs k-font-mono k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text"
      ></textarea>
    </div>
    <div class="k-space-y-1">
      <label class="k-text-[11px] k-text-knot-text-soft k-font-bold">Body (JSON)</label>
      <textarea
        :value="JSON.stringify(body, null, 2)"
        rows="5"
        @input="(e) => { try { body = JSON.parse((e.target as HTMLTextAreaElement).value || '{}'); } catch {} }"
        class="k-w-full k-px-2 k-py-1.5 k-text-xs k-font-mono k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text"
      ></textarea>
    </div>
    <div class="k-space-y-1">
      <label class="k-text-[11px] k-text-knot-text-soft k-font-bold">Timeout (ms)</label>
      <input type="number" v-model.number="timeoutMs" class="k-w-full k-px-2 k-py-1.5 k-text-sm k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text" />
    </div>
  </div>
</template>
