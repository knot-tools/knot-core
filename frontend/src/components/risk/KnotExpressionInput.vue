<!--
  KnotExpressionInput — V2.5 UX-3
  Input field for `{{...}}` Knot expressions with source-typed pills.

  Color code:
    - vert      : trigger ($trigger.*)
    - bleu      : nœud précédent ($nodes.*.json.*)
    - orange    : credential ($credentials.*) — value never previewed
    - rouge     : code custom ($code.*) or unrecognised expression

  Pure presentational — connects to the editor's expression tokeniser.
  For V2.5.0b a richer richtext-based version (with autocomplete) will
  replace this baseline.

  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<template>
  <div class="knot-expression-input space-y-1">
    <input
      v-model="local"
      type="text"
      class="block w-full rounded-md border border-slate-300 bg-white px-2 py-1.5 font-mono text-sm focus:border-emerald-500 focus:outline-none"
      :placeholder="placeholder"
      @input="onInput"
    />
    <div v-if="parts.length" class="flex flex-wrap gap-1">
      <span
        v-for="(part, idx) in parts"
        :key="idx"
        :class="['rounded-full px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wider', pillClass(part.kind)]"
      >
        {{ part.label }}
      </span>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed, ref, watch } from 'vue';

type Kind = 'trigger' | 'node' | 'credential' | 'code' | 'unknown';

const props = defineProps<{
  modelValue: string;
  placeholder?: string;
}>();

const emit = defineEmits<{ (e: 'update:modelValue', value: string): void }>();

const local = ref(props.modelValue);
watch(() => props.modelValue, (v) => (local.value = v));

function onInput(): void {
  emit('update:modelValue', local.value);
}

const parts = computed(() => {
  const out: Array<{ kind: Kind; label: string }> = [];
  const re = /\{\{\s*([^}]+?)\s*\}\}/g;
  let m: RegExpExecArray | null;
  while ((m = re.exec(local.value)) !== null) {
    const expr = m[1];
    const kind = classify(expr);
    out.push({ kind, label: expr.length > 32 ? expr.slice(0, 29) + '…' : expr });
  }
  return out;
});

function classify(expr: string): Kind {
  if (/^\$trigger\b/.test(expr)) return 'trigger';
  if (/^\$nodes?\b/.test(expr)) return 'node';
  if (/^\$credentials?\b/.test(expr)) return 'credential';
  if (/^\$code\b/.test(expr)) return 'code';
  return 'unknown';
}

function pillClass(kind: Kind): string {
  switch (kind) {
    case 'trigger':
      return 'bg-emerald-100 text-emerald-800';
    case 'node':
      return 'bg-sky-100 text-sky-800';
    case 'credential':
      return 'bg-amber-100 text-amber-800';
    case 'code':
      return 'bg-red-100 text-red-800';
    default:
      return 'bg-slate-100 text-slate-700';
  }
}
</script>
