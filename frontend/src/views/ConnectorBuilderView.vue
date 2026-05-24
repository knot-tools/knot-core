<!--
  MVP integration helper — not an official connector authoring surface.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { AlertTriangle, ClipboardCopy, Loader2 } from 'lucide-vue-next';
import { knotApi, type DolibarrAction, type DolibarrObjectMeta, type DolibarrSchema } from '@/lib/api';
import { DOLIBARR_OBJECT_CONNECTOR_OPERATION_ORDER } from '@/lib/dolibarrConnectorOperations';

const { t } = useI18n();

const objects = ref<DolibarrObjectMeta[]>([]);
const loadingObjects = ref(false);
const objectsError = ref<string | null>(null);

const selectedSlug = ref('');
const selectedAction = ref<DolibarrAction>('create');
const schemaFull = ref<DolibarrSchema | null>(null);
const loadingSchema = ref(false);
const schemaError = ref<string | null>(null);
const copyHint = ref<string | null>(null);

const operationOptions = computed(() =>
  DOLIBARR_OBJECT_CONNECTOR_OPERATION_ORDER.map((op) => ({
    value: op as DolibarrAction,
    label: t(`dolibarrObject.operations.${op}`),
  })),
);

const snippetText = computed(() => {
  const slug = selectedSlug.value || 'thirdparty';
  return JSON.stringify(
    {
      objectType: slug,
      operation: selectedAction.value,
      objectRegistryMode: 'discovery_unverified',
      id: '{{ $json.id }}',
      fields: {},
      lines: [],
    },
    null,
    2,
  );
});

onMounted(async () => {
  loadingObjects.value = true;
  objectsError.value = null;
  try {
    const data = await knotApi.getDolibarrObjects();
    objects.value = data.objects;
  } catch (e) {
    objectsError.value = (e as Error)?.message ?? 'Failed to load objects';
  } finally {
    loadingObjects.value = false;
  }
});

async function loadFullSchema() {
  schemaError.value = null;
  schemaFull.value = null;
  if (!selectedSlug.value) return;
  loadingSchema.value = true;
  try {
    if (selectedAction.value === 'create' || selectedAction.value === 'update') {
      schemaFull.value = await knotApi.getDolibarrSchema(selectedSlug.value, selectedAction.value, {
        fieldView: 'full',
      });
    } else {
      schemaFull.value = await knotApi.getDolibarrSchema(selectedSlug.value, selectedAction.value);
    }
  } catch (e) {
    schemaError.value = (e as Error)?.message ?? 'Failed to load schema';
  } finally {
    loadingSchema.value = false;
  }
}

async function copyText(text: string, msgKey: string) {
  try {
    await navigator.clipboard.writeText(text);
    copyHint.value = t(msgKey);
    window.setTimeout(() => {
      copyHint.value = null;
    }, 2000);
  } catch {
    copyHint.value = t('connectorBuilder.copyFailed');
  }
}
</script>

<template>
  <div class="k-space-y-4 k-max-w-3xl">
    <div
      class="k-rounded-knot-md k-border k-border-knot-warning k-bg-knot-warning-soft k-px-4 k-py-3 k-text-sm k-text-knot-text"
      role="alert"
    >
      <div class="k-flex k-items-start k-gap-2 k-font-semibold k-text-knot-warning">
        <AlertTriangle :size="18" class="k-shrink-0 k-mt-0.5" />
        <span>{{ t('connectorBuilder.warningTitle') }}</span>
      </div>
      <p class="k-mt-2 k-text-knot-text-muted k-leading-relaxed">
        {{ t('connectorBuilder.warningBody') }}
      </p>
    </div>

    <p v-if="objectsError" class="k-text-sm k-text-knot-danger">{{ objectsError }}</p>

    <div class="k-space-y-3 k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface k-p-4">
      <h2 class="k-text-sm k-font-bold k-text-knot-text">{{ t('connectorBuilder.helpTitle') }}</h2>
      <p class="k-text-xs k-text-knot-text-muted">{{ t('connectorBuilder.helpLead') }}</p>

      <div class="k-grid k-grid-cols-1 sm:k-grid-cols-2 k-gap-3">
        <div class="k-space-y-1">
          <label class="k-text-[11px] k-font-bold k-text-knot-text-soft">{{ t('connectorBuilder.objectLabel') }}</label>
          <select
            v-model="selectedSlug"
            :disabled="loadingObjects || !objects.length"
            class="k-w-full k-px-2 k-py-1.5 k-text-sm k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border"
          >
            <option value="">{{ t('connectorBuilder.objectPlaceholder') }}</option>
            <option v-for="o in objects" :key="o.slug" :value="o.slug">{{ o.label }} ({{ o.slug }})</option>
          </select>
        </div>
        <div class="k-space-y-1">
          <label class="k-text-[11px] k-font-bold k-text-knot-text-soft">{{ t('connectorBuilder.operationLabel') }}</label>
          <select
            v-model="selectedAction"
            class="k-w-full k-px-2 k-py-1.5 k-text-sm k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border"
          >
            <option v-for="op in operationOptions" :key="op.value" :value="op.value">{{ op.label }}</option>
          </select>
        </div>
      </div>

      <div class="k-flex k-flex-wrap k-items-center k-gap-2">
        <button
          type="button"
          class="k-inline-flex k-items-center k-gap-1.5 k-px-3 k-py-1.5 k-text-xs k-font-semibold k-rounded-knot-sm k-bg-knot-primary k-text-white hover:k-opacity-90 k-transition"
          :disabled="!selectedSlug || loadingSchema"
          @click="loadFullSchema"
        >
          <Loader2 v-if="loadingSchema" :size="14" class="k-animate-spin" />
          {{ t('connectorBuilder.loadSchema') }}
        </button>
        <button
          type="button"
          class="k-inline-flex k-items-center k-gap-1.5 k-px-3 k-py-1.5 k-text-xs k-font-semibold k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text"
          @click="copyText(snippetText, 'connectorBuilder.copiedSnippet')"
        >
          <ClipboardCopy :size="14" />
          {{ t('connectorBuilder.copySnippet') }}
        </button>
        <button
          type="button"
          class="k-inline-flex k-items-center k-gap-1.5 k-px-3 k-py-1.5 k-text-xs k-font-semibold k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text"
          :disabled="!schemaFull"
          @click="schemaFull && copyText(JSON.stringify(schemaFull, null, 2), 'connectorBuilder.copiedSchema')"
        >
          <ClipboardCopy :size="14" />
          {{ t('connectorBuilder.copySchemaJson') }}
        </button>
      </div>
      <p v-if="copyHint" class="k-text-[11px] k-text-knot-success">{{ copyHint }}</p>
      <p v-if="schemaError" class="k-text-[11px] k-text-knot-danger">{{ schemaError }}</p>

      <div class="k-space-y-1">
        <span class="k-text-[11px] k-font-bold k-text-knot-text-soft">{{ t('connectorBuilder.sampleNodeTitle') }}</span>
        <pre
          class="k-text-[11px] k-font-mono k-p-3 k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-overflow-x-auto k-max-h-40"
        >{{ snippetText }}</pre>
      </div>

      <div v-if="schemaFull" class="k-space-y-1">
        <span class="k-text-[11px] k-font-bold k-text-knot-text-soft">{{ t('connectorBuilder.schemaPreviewTitle') }}</span>
        <pre
          class="k-text-[11px] k-font-mono k-p-3 k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-overflow-x-auto k-max-h-80"
        >{{ JSON.stringify(schemaFull, null, 2) }}</pre>
      </div>
    </div>
  </div>
</template>
