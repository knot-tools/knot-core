<!--
  DolibarrPicker — autocomplete dropdown for x-dolibarr-fk fields.
  Calls api/dolibarr_picker.php on input (debounced) and emits the
  selected record id via update:modelValue.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { ref, watch } from 'vue';
import { Search, Loader2, X } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import { knotApi, type DolibarrPickResult } from '@/lib/api';

const { t } = useI18n();

const props = defineProps<{
  slug: string;
  open: boolean;
  initialQuery?: string;
}>();

const emit = defineEmits<{
  (e: 'update:open', v: boolean): void;
  (e: 'pick', record: DolibarrPickResult): void;
}>();

const query = ref('');
const results = ref<DolibarrPickResult[]>([]);
const loading = ref(false);
const errorMsg = ref<string | null>(null);
let debounceTimer: ReturnType<typeof setTimeout> | null = null;

function close() {
  emit('update:open', false);
}

function selectResult(r: DolibarrPickResult) {
  emit('pick', r);
  close();
}

async function search() {
  if (!props.slug) return;
  loading.value = true;
  errorMsg.value = null;
  try {
    results.value = await knotApi.pickDolibarrFk(props.slug, query.value, 20);
  } catch (e) {
    errorMsg.value = (e as Error)?.message ?? t('dolibarrPicker.failed');
    results.value = [];
  } finally {
    loading.value = false;
  }
}

function onInput(value: string) {
  query.value = value;
  if (debounceTimer) clearTimeout(debounceTimer);
  debounceTimer = setTimeout(() => {
    void search();
  }, 200);
}

watch(
  () => props.open,
  (open) => {
    if (open) {
      query.value = props.initialQuery ?? '';
      void search();
    }
  },
);
</script>

<template>
  <div
    v-if="open"
    class="k-fixed k-inset-0 k-z-50 k-flex k-items-start k-justify-center k-bg-black/40 k-backdrop-blur-sm k-pt-24"
    @click.self="close"
  >
    <div class="k-w-[440px] k-max-w-full k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-shadow-knot-lg k-overflow-hidden">
      <header class="k-px-4 k-py-2 k-border-b k-border-knot-border k-flex k-items-center k-gap-2">
        <Search :size="14" class="k-text-knot-text-muted" />
        <input
          :value="query"
          @input="(e) => onInput((e.target as HTMLInputElement).value)"
          type="text"
          :placeholder="t('dolibarrPicker.searchIn', { slug })"
          autofocus
          class="k-flex-1 k-px-2 k-py-1 k-text-sm k-bg-transparent focus:k-outline-none k-text-knot-text"
        />
        <Loader2 v-if="loading" :size="14" class="k-animate-spin k-text-knot-text-muted" />
        <button type="button" class="k-text-knot-text-muted hover:k-text-knot-text" @click="close">
          <X :size="14" />
        </button>
      </header>
      <div v-if="errorMsg" class="k-px-4 k-py-3 k-text-xs k-text-knot-danger">{{ errorMsg }}</div>
      <ul v-if="results.length" class="k-max-h-[320px] k-overflow-y-auto k-divide-y k-divide-knot-border">
        <li v-for="r in results" :key="r.id">
          <button
            type="button"
            class="k-w-full k-text-left k-px-4 k-py-2 hover:k-bg-knot-primary-soft k-transition"
            @click="selectResult(r)"
          >
            <div class="k-flex k-items-center k-justify-between k-gap-2">
              <span class="k-font-mono k-text-knot-text-soft k-text-[11px]">#{{ r.id }}</span>
              <span class="k-flex-1 k-truncate k-text-sm k-text-knot-text">{{ r.label || r.ref }}</span>
              <span v-if="r.ref" class="k-text-[11px] k-text-knot-text-muted k-truncate k-max-w-[120px]">{{ r.ref }}</span>
            </div>
          </button>
        </li>
      </ul>
      <p v-else-if="!loading && !errorMsg" class="k-px-4 k-py-6 k-text-center k-text-xs k-text-knot-text-muted">
        {{ t('dolibarrPicker.noResults') }}
      </p>
    </div>
  </div>
</template>
