<!--
  FullTraceModal — fullscreen flame-graph timeline + node detail.
  Reusable for live simulations and historical executions.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { X } from 'lucide-vue-next';

interface NodeLog {
  nodeId?: string;
  type?: string;
  status?: string;
  durationMs?: number;
  input?: unknown;
  output?: unknown;
  error?: string | null;
}

const props = withDefaults(
  defineProps<{
    open: boolean;
    logs: NodeLog[];
    durationMs: number;
    title?: string;
    /** Map node id → canvas label for readable trace titles */
    nodeLabels?: Record<string, string>;
  }>(),
  { nodeLabels: () => ({}) },
);

const emit = defineEmits<{ (e: 'close'): void }>();

const selectedIdx = ref(0);

watch(() => props.open, (o) => {
  if (o) selectedIdx.value = 0;
});

const totalMs = computed(() => Math.max(1, props.durationMs || props.logs.reduce((a, l) => a + (l.durationMs ?? 0), 0)));
const cumulative = computed(() => {
  let cursor = 0;
  return props.logs.map((l) => {
    const start = cursor;
    cursor += l.durationMs ?? 0;
    return { start, width: ((l.durationMs ?? 0) / totalMs.value) * 100, offset: (start / totalMs.value) * 100 };
  });
});

function colorOf(status?: string) {
  if (status === 'success') return '#10b981';
  if (status === 'error') return '#ef4444';
  if (status === 'skipped') return '#94a3b8';
  return '#64748b';
}

function primaryTitle(log: NodeLog): string {
  const id = String(log.nodeId ?? '');
  const lbl = id ? (props.nodeLabels[id] ?? '').trim() : '';
  if (lbl !== '') return lbl;
  return id !== '' ? id : '?';
}

function secondaryLine(log: NodeLog): string {
  const typeLabel = String(log.type ?? '').trim() || 'unknown';
  return `${typeLabel} · ${log.durationMs ?? '?'} ms`;
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="k-fixed k-inset-0 k-z-[10001] k-bg-black/70 k-backdrop-blur-md k-flex k-items-stretch" @click.self="emit('close')">
      <div class="k-w-full k-h-full k-bg-knot-bg k-flex k-flex-col">
        <header class="k-flex k-items-center k-justify-between k-px-6 k-py-4 k-border-b k-border-knot-border k-bg-knot-surface">
          <div>
            <div class="k-text-knot-text k-font-semibold">{{ title || 'Full trace' }}</div>
            <div class="k-text-[11px] k-text-knot-text-muted k-mt-0.5">{{ totalMs }} ms · {{ logs.length }} nodes</div>
          </div>
          <button @click="emit('close')" class="k-p-2 k-rounded-knot-sm hover:k-bg-knot-surface-soft"><X :size="16" /></button>
        </header>

        <div class="k-px-6 k-py-4 k-border-b k-border-knot-border k-bg-knot-surface">
          <div class="k-relative k-h-8 k-bg-knot-surface-soft k-rounded-knot-sm k-overflow-hidden">
            <button
              v-for="(log, idx) in logs"
              :key="(log.nodeId ?? '') + idx"
              @click="selectedIdx = idx"
              :style="{ left: cumulative[idx].offset + '%', width: Math.max(0.5, cumulative[idx].width) + '%', background: colorOf(log.status) }"
              :class="['k-absolute k-top-0 k-bottom-0 k-border-l k-border-r k-border-white/30', selectedIdx === idx ? 'k-ring-2 k-ring-white' : '']"
              :title="`${primaryTitle(log)} · ${secondaryLine(log)}`"
            />
          </div>
        </div>

        <div class="k-flex-1 k-flex k-min-h-0">
          <aside class="k-w-72 k-border-r k-border-knot-border k-bg-knot-surface k-overflow-y-auto">
            <ul class="k-divide-y k-divide-knot-border">
              <li
                v-for="(log, idx) in logs"
                :key="(log.nodeId ?? '') + idx"
                @click="selectedIdx = idx"
                :class="['k-px-4 k-py-3 k-cursor-pointer', selectedIdx === idx ? 'k-bg-knot-primary-soft' : 'hover:k-bg-knot-surface-soft']"
              >
                <div class="k-text-sm k-font-semibold k-text-knot-text">{{ primaryTitle(log) }}</div>
                <div class="k-text-[11px] k-text-knot-text-muted">{{ secondaryLine(log) }}</div>
              </li>
            </ul>
          </aside>
          <main class="k-flex-1 k-overflow-y-auto k-p-6">
            <div v-if="logs[selectedIdx]" class="k-space-y-4">
              <div>
                <div class="k-text-[11px] k-text-knot-text-soft k-uppercase k-font-bold">Input</div>
                <pre class="k-text-[12px] k-font-mono k-bg-knot-surface k-border k-border-knot-border k-p-3 k-rounded-knot-sm k-overflow-auto">{{ JSON.stringify(logs[selectedIdx].input ?? {}, null, 2) }}</pre>
              </div>
              <div>
                <div class="k-text-[11px] k-text-knot-text-soft k-uppercase k-font-bold">Output</div>
                <pre class="k-text-[12px] k-font-mono k-bg-knot-surface k-border k-border-knot-border k-p-3 k-rounded-knot-sm k-overflow-auto">{{ JSON.stringify(logs[selectedIdx].output ?? {}, null, 2) }}</pre>
              </div>
              <div v-if="logs[selectedIdx].error" class="k-text-knot-danger k-text-sm k-font-mono">{{ logs[selectedIdx].error }}</div>
            </div>
          </main>
        </div>
      </div>
    </div>
  </Teleport>
</template>
