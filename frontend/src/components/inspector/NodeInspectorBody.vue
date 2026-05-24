<!--
  NodeInspectorBody — Form / Advanced JSON / Test tabs with panel resolver.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, ref, shallowRef, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Wrench, Code, Beaker, ShieldCheck, MessageSquare } from 'lucide-vue-next';
import DynamicForm from './DynamicForm.vue';
import DolibarrObjectPanel from './panels/DolibarrObjectPanel.vue';
import DolibarrEventPanel from './panels/DolibarrEventPanel.vue';
import LoopPanel from './panels/LoopPanel.vue';
import ExecuteWorkflowPanel from './panels/ExecuteWorkflowPanel.vue';
import HttpPanel from './panels/HttpPanel.vue';
import StripeShopifyPanel from './panels/StripeShopifyPanel.vue';
import AiPromptPanel from './panels/AiPromptPanel.vue';
import WebhookTriggerPanel from './panels/WebhookTriggerPanel.vue';

const props = defineProps<{
  nodeType: string;
  config: Record<string, unknown>;
  schema: Record<string, unknown> | null;
  workflowId: number | null;
  /** False until connector catalog fetch settled (avoids false “no schema” while descriptors load). */
  connectorCatalogReady?: boolean;
  /** Increments when canvas requests scrolling to Dolibarr change_status hints. */
  dolibarrSmFocusTick?: number;
  /**
   * Free-text comment stored at the node level (workflow JSON
   * `notes` field; see `docs/workflow-format.md`). Persisted as a
   * sibling of `config` to keep it untyped against connector
   * schemas. Defaults to empty string when the host did not wire
   * the prop.
   */
  notes?: string;
}>();

const emit = defineEmits<{
  (e: 'update:config', value: Record<string, unknown>): void;
  (e: 'update:notes', value: string): void;
  (e: 'test-node'): void;
}>();

const { t } = useI18n();

const TAB_ORDER = ['form', 'advanced', 'reliability', 'comment', 'test'] as const;

function inspectorTabLabel(key: (typeof TAB_ORDER)[number]): string {
  const map = {
    form: 'inspector.nodeBody.tabForm',
    advanced: 'inspector.nodeBody.tabAdvanced',
    reliability: 'inspector.nodeBody.tabReliability',
    comment: 'inspector.nodeBody.tabComment',
    test: 'inspector.nodeBody.tabTest',
  } as const;
  return t(map[key]);
}

const tab = ref<'form' | 'advanced' | 'reliability' | 'comment' | 'test'>('form');

watch(
  () => props.dolibarrSmFocusTick,
  (tick) => {
    if (tick !== undefined && tick > 0) {
      tab.value = 'form';
    }
  },
  { flush: 'sync' },
);

const dolibarrSmTickBind = computed((): Record<string, number> =>
  props.nodeType === 'dolibarr.object' ? { focusHintsTick: props.dolibarrSmFocusTick ?? 0 } : {},
);

const retry = computed(() => {
  const r = (props.config?.retry ?? {}) as Record<string, unknown>;
  return {
    maxAttempts: Number(r.maxAttempts ?? 1),
    backoffMs: Number(r.backoffMs ?? 1000),
    exponential: Boolean(r.exponential ?? false),
  };
});

const reliabilityHint = computed(() =>
  retry.value.exponential
    ? t('inspector.nodeBody.retryHintExponential', {
      max: retry.value.maxAttempts,
      ms: retry.value.backoffMs,
    })
    : t('inspector.nodeBody.retryHintFixed', {
      max: retry.value.maxAttempts,
      ms: retry.value.backoffMs,
    }),
);

function setRetry(patch: Partial<{ maxAttempts: number; backoffMs: number; exponential: boolean }>) {
  const next = { ...props.config, retry: { ...retry.value, ...patch } };
  setConfig(next);
}
const testInput = ref('{}');
const testOutput = shallowRef<unknown>(null);
const testError = ref<string | null>(null);

function setConfig(v: Record<string, unknown>) {
  emit('update:config', v);
}

const panelComponent = computed(() => {
  const typeId = props.nodeType;
  if (typeId === 'dolibarr.object') return DolibarrObjectPanel;
  if (typeId === 'trigger.dolibarr.event' || typeId === 'dolibarr.event') return DolibarrEventPanel;
  if (typeId === 'logic.loop' || typeId === 'loop') return LoopPanel;
  if (typeId === 'logic.execute_workflow' || typeId === 'workflow.execute') return ExecuteWorkflowPanel;
  if (typeId === 'action.http' || typeId === 'http') return HttpPanel;
  if (typeId.startsWith('action.stripe') || typeId.startsWith('action.shopify')) return StripeShopifyPanel;
  if (typeId.startsWith('ai.') || typeId === 'action.ai') return AiPromptPanel;
  if (typeId === 'trigger.webhook') return WebhookTriggerPanel;
  return null;
});

const catalogReady = computed(() => props.connectorCatalogReady !== false);

const showDynamicSchemaLoading = computed(() => !panelComponent.value && !catalogReady.value);

const advancedJson = computed(() => JSON.stringify(props.config, null, 2));

function applyAdvanced(value: string) {
  try {
    const parsed = JSON.parse(value || '{}');
    setConfig(parsed);
    testError.value = null;
  } catch (err) {
    testError.value = err instanceof Error ? err.message : t('inspector.nodeBody.invalidJson');
  }
}

function runTest() {
  testError.value = null;
  try {
    JSON.parse(testInput.value || '{}');
    testOutput.value = { simulated: true, note: t('inspector.nodeBody.testSimulatedNote') };
  } catch (err) {
    testError.value = err instanceof Error ? err.message : t('inspector.nodeBody.invalidTestJson');
  }
}
</script>

<template>
  <div class="k-space-y-3 k-min-w-0">
    <div
      class="k-grid k-grid-cols-3 k-gap-1 k-bg-knot-surface-soft k-rounded-knot-sm k-p-0.5 k-border k-border-knot-border"
      data-knot-test="knot-inspector-tabs"
    >
      <button
        v-for="tabKind in TAB_ORDER"
        :key="tabKind"
        type="button"
        @click="tab = tabKind"
        :class="[
          'k-min-w-0 k-px-1.5 k-py-1 k-text-[10px] k-font-bold k-uppercase k-tracking-wide k-rounded-knot-sm k-flex k-items-center k-justify-center k-gap-0.5 k-transition-colors',
          tab === tabKind ? 'k-bg-knot-surface k-text-knot-primary k-shadow-knot-sm' : 'k-text-knot-text-muted hover:k-text-knot-text',
        ]"
      >
        <Wrench v-if="tabKind === 'form'" :size="11" />
        <Code v-else-if="tabKind === 'advanced'" :size="11" />
        <ShieldCheck v-else-if="tabKind === 'reliability'" :size="11" />
        <MessageSquare v-else-if="tabKind === 'comment'" :size="11" />
        <Beaker v-else :size="11" />
        <span class="k-truncate">{{ inspectorTabLabel(tabKind) }}</span>
      </button>
    </div>

    <div v-show="tab === 'form'">
      <component
        v-if="panelComponent"
        :is="panelComponent"
        :model-value="config"
        @update:model-value="setConfig"
        :node-type="nodeType"
        :workflow-id="workflowId"
        v-bind="dolibarrSmTickBind"
      />
      <DynamicForm
        v-else-if="!showDynamicSchemaLoading"
        :schema="(schema as any)"
        :model-value="config"
        @update:model-value="setConfig"
      />
      <p v-else class="k-text-knot-text-muted k-text-xs k-italic">
        {{ t('inspector.nodeBody.loadingSchema') }}
      </p>
    </div>

    <div v-show="tab === 'advanced'" class="k-space-y-1">
      <textarea
        :value="advancedJson"
        @input="(e) => applyAdvanced((e.target as HTMLTextAreaElement).value)"
        rows="14"
        class="k-w-full k-px-2 k-py-1.5 k-text-xs k-font-mono k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text"
      ></textarea>
      <p v-if="testError" class="k-text-[10px] k-text-knot-danger">{{ testError }}</p>
    </div>

    <div v-show="tab === 'reliability'" class="k-space-y-3">
      <div>
        <label class="k-text-[11px] k-text-knot-text-soft k-font-bold">{{ t('inspector.nodeBody.maxAttempts') }}</label>
        <input
          type="number"
          min="1"
          max="10"
          :value="retry.maxAttempts"
          @input="(e) => setRetry({ maxAttempts: Math.max(1, Number((e.target as HTMLInputElement).value)) })"
          class="k-w-full k-px-2 k-py-1.5 k-text-xs k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border"
        />
      </div>
      <div>
        <label class="k-text-[11px] k-text-knot-text-soft k-font-bold">{{ t('inspector.nodeBody.backoffMs') }}</label>
        <input
          type="number"
          min="0"
          step="100"
          :value="retry.backoffMs"
          @input="(e) => setRetry({ backoffMs: Math.max(0, Number((e.target as HTMLInputElement).value)) })"
          class="k-w-full k-px-2 k-py-1.5 k-text-xs k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border"
        />
      </div>
      <label class="k-flex k-items-center k-gap-2 k-text-[12px] k-text-knot-text">
        <input type="checkbox" :checked="retry.exponential" @change="(e) => setRetry({ exponential: (e.target as HTMLInputElement).checked })" />
        {{ t('inspector.nodeBody.exponentialBackoff') }}
      </label>
      <p class="k-text-[11px] k-text-knot-text-muted">
        {{ reliabilityHint }}
      </p>
    </div>

    <div v-show="tab === 'comment'" class="k-space-y-2">
      <label class="k-text-[11px] k-text-knot-text-soft k-font-bold">{{ t('inspector.nodeBody.noteLabel') }}</label>
      <textarea
        :value="notes ?? ''"
        @input="(e) => emit('update:notes', (e.target as HTMLTextAreaElement).value)"
        rows="8"
        :placeholder="t('inspector.nodeBody.notePlaceholder')"
        class="k-w-full k-px-2 k-py-1.5 k-text-xs k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text"
      ></textarea>
      <p class="k-text-[11px] k-text-knot-text-muted">
        {{ t('inspector.nodeBody.noteHelp') }}
      </p>
    </div>

    <div v-show="tab === 'test'" class="k-space-y-2">
      <label class="k-text-[11px] k-text-knot-text-soft k-font-bold">{{ t('inspector.nodeBody.testInputLabel') }}</label>
      <textarea
        v-model="testInput"
        rows="5"
        class="k-w-full k-px-2 k-py-1.5 k-text-xs k-font-mono k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text"
      ></textarea>
      <button
        @click="runTest"
        class="k-px-2.5 k-py-1.5 k-text-xs k-font-semibold k-bg-knot-primary k-text-white k-rounded-knot-sm hover:k-bg-knot-primary-strong"
      >
        {{ t('inspector.nodeBody.testNodeButton') }}
      </button>
      <p v-if="testError" class="k-text-[11px] k-text-knot-danger">{{ testError }}</p>
      <pre v-if="testOutput" class="k-text-[10px] k-bg-knot-surface-soft k-border k-border-knot-border k-rounded-knot-sm k-p-2 k-overflow-x-auto">{{ JSON.stringify(testOutput, null, 2) }}</pre>
    </div>
  </div>
</template>
