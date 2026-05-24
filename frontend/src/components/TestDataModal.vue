<!--
  TestDataModal — preflight modal for Simulate/Run.
  Tabs: Last execution / Manual JSON paste / Auto stub (disabled until shipped).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { X, FlaskConical, Play, Loader2 } from 'lucide-vue-next';
import { knotApi } from '../lib/api';

const props = defineProps<{
  open: boolean;
  workflowId: number | null;
  mode?: 'simulate' | 'run' | 'replay';
}>();
const emit = defineEmits<{
  (e: 'close'): void;
  (e: 'submit', data: Record<string, unknown>): void;
}>();

const { t } = useI18n();

const tab = ref<'last' | 'manual' | 'stub'>('manual');
const lastExec = ref<{ id: number; triggerData?: Record<string, unknown> } | null>(null);
const lastExecLoading = ref(false);
const manualJson = ref('{\n  "example": true\n}');
const error = ref<string | null>(null);

watch(
  () => props.open,
  async (isOpen) => {
    if (!isOpen || !props.workflowId) return;
    error.value = null;
    lastExecLoading.value = true;
    try {
      const result = await knotApi.listExecutions({ workflowId: props.workflowId, limit: 1 });
      const items = (result as unknown as { executions?: Array<{ id: number; triggerData?: Record<string, unknown> }> }).executions ?? [];
      if (items.length > 0) {
        lastExec.value = items[0];
        tab.value = 'last';
      } else {
        lastExec.value = null;
        tab.value = 'manual';
      }
    } catch {
      lastExec.value = null;
    } finally {
      lastExecLoading.value = false;
    }
  },
);

const submitLabel = computed(() => {
  if (props.mode === 'replay') return t('testDataModal.submitReplay');
  if (props.mode === 'run') return t('testDataModal.submitRun');
  return t('testDataModal.submitSimulate');
});

function submit() {
  error.value = null;
  let payload: Record<string, unknown> = {};
  if (tab.value === 'last' && lastExec.value?.triggerData) {
    payload = { ...lastExec.value.triggerData };
  } else if (tab.value === 'manual') {
    try {
      const parsed = JSON.parse(manualJson.value || '{}');
      if (parsed && typeof parsed === 'object') payload = parsed as Record<string, unknown>;
    } catch {
      error.value = t('testDataModal.invalidJson');
      return;
    }
  }
  emit('submit', payload);
}

function tabLabel(key: 'last' | 'manual' | 'stub'): string {
  if (key === 'last') return t('testDataModal.tabLast');
  if (key === 'manual') return t('testDataModal.tabManual');
  return t('testDataModal.tabStub');
}
</script>

<template>
  <Teleport to="body">
    <div v-if="open" class="k-fixed k-inset-0 k-z-[10000] k-bg-black/50 k-backdrop-blur-sm k-flex k-items-center k-justify-center k-px-4" @click.self="emit('close')">
      <div class="k-w-full k-max-w-xl k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-lg k-shadow-knot-lg k-flex k-flex-col">
        <header class="k-flex k-items-center k-justify-between k-px-5 k-py-3 k-border-b k-border-knot-border">
          <div class="k-flex k-items-center k-gap-2 k-text-knot-text k-font-semibold">
            <FlaskConical :size="16" />
            {{ t('testDataModal.title') }}
          </div>
          <button @click="emit('close')" class="k-p-1 k-rounded-knot-sm hover:k-bg-knot-surface-soft" :aria-label="t('actions.close')">
            <X :size="16" />
          </button>
        </header>

        <nav class="k-flex k-border-b k-border-knot-border">
          <button
            v-for="tb in (['last', 'manual', 'stub'] as const)"
            :key="tb"
            @click="tab = tb"
            :disabled="tb === 'stub'"
            :title="tb === 'stub' ? t('testDataModal.stubHint') : undefined"
            :class="[
              'k-flex-1 k-px-4 k-py-2 k-text-sm k-font-semibold k-transition',
              tab === tb ? 'k-text-knot-primary k-border-b-2 k-border-knot-primary' : 'k-text-knot-text-muted hover:k-text-knot-text',
              tb === 'stub' ? 'k-opacity-40 k-cursor-not-allowed' : '',
            ]"
          >
            {{ tabLabel(tb) }}
          </button>
        </nav>

        <div class="k-px-5 k-py-4 k-min-h-[200px]">
          <div v-if="tab === 'last'">
            <div v-if="lastExecLoading" class="k-text-knot-text-muted k-flex k-items-center k-gap-2">
              <Loader2 :size="14" class="k-animate-spin" /> {{ t('testDataModal.loadingLast') }}
            </div>
            <div v-else-if="!lastExec" class="k-text-knot-text-muted k-text-sm">{{ t('testDataModal.noPreviousExecution') }}</div>
            <div v-else>
              <p class="k-text-sm k-text-knot-text-muted k-mb-2">{{ t('testDataModal.reuseHint', { id: lastExec.id }) }}</p>
              <pre class="k-text-[12px] k-font-mono k-bg-knot-surface-soft k-p-3 k-rounded-knot-sm k-overflow-auto k-max-h-48">{{ JSON.stringify(lastExec.triggerData ?? {}, null, 2) }}</pre>
            </div>
          </div>
          <div v-else-if="tab === 'manual'">
            <label class="k-block k-text-[11px] k-text-knot-text-muted k-mb-1">{{ t('testDataModal.triggerJsonLabel') }}</label>
            <textarea
              v-model="manualJson"
              rows="8"
              class="k-w-full k-text-[12px] k-font-mono k-bg-knot-surface-soft k-border k-border-knot-border k-rounded-knot-sm k-p-3 focus:k-outline-none focus:k-border-knot-primary"
            />
          </div>
          <div v-else>
            <p class="k-text-knot-text-muted k-text-sm">{{ t('testDataModal.stubBody') }}</p>
          </div>
          <div v-if="error" class="k-mt-2 k-text-knot-danger k-text-sm">{{ error }}</div>
        </div>

        <footer class="k-flex k-justify-end k-gap-2 k-px-5 k-py-3 k-border-t k-border-knot-border">
          <button @click="emit('close')" class="k-px-3 k-py-1.5 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border k-text-sm">{{ t('actions.cancel') }}</button>
          <button @click="submit" class="k-inline-flex k-items-center k-gap-2 k-px-3.5 k-py-1.5 k-rounded-knot-sm k-bg-knot-hero k-text-white k-text-sm k-font-semibold">
            <Play :size="14" />
            {{ submitLabel }}
          </button>
        </footer>
      </div>
    </div>
  </Teleport>
</template>
