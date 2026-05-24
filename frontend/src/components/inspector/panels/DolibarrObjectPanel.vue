<!-- Copyright (C) 2026 Knot — GPL-3.0-or-later -->
<script setup lang="ts">
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Database, Loader2, Lock, AlertTriangle, Plus, Trash2, ShieldCheck, FlaskConical, Filter } from 'lucide-vue-next';
import DynamicForm from '../DynamicForm.vue';
import { DOLIBARR_OBJECT_CONNECTOR_OPERATION_ORDER } from '@/lib/dolibarrConnectorOperations';
import {
  OBJECT_REGISTRY_ALL,
  OBJECT_REGISTRY_ALL_EXCEPT_UNVERIFIED,
  OBJECT_REGISTRY_DISCOVERY,
  OBJECT_REGISTRY_DISCOVERY_UNVERIFIED,
  OBJECT_REGISTRY_CURATED,
  normalizeObjectRegistryMode,
  isDiscoveryUnverifiedExpert,
  type ObjectRegistryMode,
} from '@/lib/objectRegistryMode';
import {
  knotApi,
  type DolibarrAction,
  type DolibarrDescriptorCacheMeta,
  type DolibarrFieldView,
  type DolibarrObjectMeta,
  type DolibarrSchema,
  type DolibarrSchemaProperty,
  type DolibarrVerb,
} from '@/lib/api';

const props = defineProps<{ modelValue: Record<string, unknown>; focusHintsTick?: number }>();
const emit = defineEmits<{ (e: 'update:modelValue', v: Record<string, unknown>): void }>();
const { t } = useI18n();

const OPERATIONS = computed(() =>
  DOLIBARR_OBJECT_CONNECTOR_OPERATION_ORDER.map((value) => ({
    value: value as DolibarrAction,
    label: t(`dolibarrObject.operations.${value}`),
  })),
);

const STATUS_METHODS = ['valid', 'cancel', 'close', 'reopen', 'setdraft'];

const objects = ref<DolibarrObjectMeta[]>([]);
const schema = ref<DolibarrSchema | null>(null);
const verbs = ref<DolibarrVerb[]>([]);
const verbsLoaded = ref(false);
const loadingObjects = ref(false);
const loadingSchema = ref(false);
const loadingVerbs = ref(false);
const objectsError = ref<string | null>(null);
const schemaError = ref<string | null>(null);
const verbsError = ref<string | null>(null);
const tab = ref<'fields' | 'advanced'>('fields');

const smLoading = ref(false);
const smError = ref<string | null>(null);
const smCurrent = ref<string | null>(null);
const smProbable = ref<Array<{ method: string; maturity: string; probability: string; pattern: string }>>([]);

const descriptorCacheMeta = ref<DolibarrDescriptorCacheMeta | undefined>(undefined);
const registryWidenedForObject = ref(false);

const canvasSmHintsAnchorRef = ref<HTMLElement | null>(null);

watch(
  () => props.focusHintsTick,
  async () => {
    const tick = props.focusHintsTick;
    if (tick === undefined || tick < 1) return;
    await nextTick();
    canvasSmHintsAnchorRef.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
  },
);

function patch(p: Record<string, unknown>) {
  emit('update:modelValue', { ...props.modelValue, ...p });
}

const objectRegistryMode = computed<ObjectRegistryMode>({
  get() {
    return normalizeObjectRegistryMode(props.modelValue?.objectRegistryMode);
  },
  set(v: ObjectRegistryMode) {
    patch({ objectRegistryMode: v });
  },
});

const objectType = computed({
  get: () => String(props.modelValue?.objectType ?? ''),
  set: (v) => patch({ objectType: v }),
});

const operation = computed({
  get: () => (String(props.modelValue?.operation ?? props.modelValue?.action ?? 'create')) as DolibarrAction,
  set: (v) => patch({ operation: v }),
});

const idValue = computed({
  get: () => {
    const raw = props.modelValue?.id;
    if (raw === undefined || raw === null) return '';
    return String(raw);
  },
  set: (v) => patch({ id: v }),
});

const note = computed({
  get: () => String(props.modelValue?.note ?? ''),
  set: (v) => patch({ note: v }),
});

const statusMethod = computed({
  get: () => String(props.modelValue?.statusMethod ?? 'valid'),
  set: (v) => patch({ statusMethod: v }),
});

const statusMethodCustom = computed({
  get: () => String(props.modelValue?.statusMethodCustom ?? ''),
  set: (v) => patch({ statusMethodCustom: v }),
});

const fields = computed({
  get: () => (props.modelValue?.fields ?? {}) as Record<string, unknown>,
  set: (v) => patch({ fields: v }),
});

const lines = computed<Array<Record<string, unknown>>>({
  get: () => (Array.isArray(props.modelValue?.lines) ? (props.modelValue!.lines as Array<Record<string, unknown>>) : []),
  set: (v) => patch({ lines: v }),
});

const objectMeta = computed(() => objects.value.find((o) => o.slug === objectType.value) ?? null);

const supportsLines = computed(() => objectMeta.value?.supportsLines ?? false);

const setupIntrospectionUrl = computed(() => {
  const fromWindow = (window as unknown as { KNOT_SETUP_URL?: string }).KNOT_SETUP_URL;
  const raw = fromWindow ?? '/custom/knot/admin/setup.php?admin=1';
  const withoutHash = raw.split('#')[0] ?? raw;
  return `${withoutHash}#knot-introspection`;
});

const filteredObjects = computed<DolibarrObjectMeta[]>(() => {
  const xs = objects.value;
  const m = objectRegistryMode.value;
  if (m === OBJECT_REGISTRY_ALL_EXCEPT_UNVERIFIED || m === OBJECT_REGISTRY_CURATED) {
    return xs.filter((o) => o.fromMap !== false);
  }
  if (m === OBJECT_REGISTRY_DISCOVERY || m === OBJECT_REGISTRY_DISCOVERY_UNVERIFIED) {
    return xs.filter((o) => o.fromMap === false);
  }
  return xs;
});

const showDescriptorCacheBanner = computed(() => {
  const m = descriptorCacheMeta.value;
  if (m === undefined || loadingObjects.value) return false;
  return !m.present || m.storedDescriptorCount < 1;
});

const showExpertRegistryBanner = computed(() => isDiscoveryUnverifiedExpert(objectRegistryMode.value));

/** Standard vs expert discovery modes filter the same slugs (fromMap false); expert changes schema/validation UX only. */
const showDiscoveryRegistryHint = computed(
  () =>
    objectRegistryMode.value === OBJECT_REGISTRY_DISCOVERY ||
    objectRegistryMode.value === OBJECT_REGISTRY_DISCOVERY_UNVERIFIED,
);

const showCustomStatusMethod = computed(
  () => showStatusMethod.value && isDiscoveryUnverifiedExpert(objectRegistryMode.value),
);

function registryOptionLabel(o: DolibarrObjectMeta): string {
  const suffix = o.fromMap === false ? t('dolibarrObject.optionDiscoverySuffix') : t('dolibarrObject.optionMapSuffix');
  return `${o.label}${suffix}`;
}

const lineItemSchema = computed<DolibarrSchema | null>(() => {
  const linesProp = schema.value?.properties?.lines;
  if (!linesProp || !linesProp.items) return null;
  return { type: 'object', ...(linesProp.items as DolibarrSchemaProperty) } as DolibarrSchema;
});

const fieldsSchema = computed<DolibarrSchema | null>(() => {
  if (!schema.value || !schema.value.properties) return schema.value;
  const cleaned: Record<string, DolibarrSchemaProperty> = {};
  for (const [k, v] of Object.entries(schema.value.properties)) {
    if (k === 'lines') continue;
    cleaned[k] = v;
  }
  return { ...schema.value, properties: cleaned };
});

const showFields = computed(() => operation.value === 'create' || operation.value === 'update');
const showLines = computed(() => showFields.value && supportsLines.value);
const showId = computed(() => operation.value !== 'create');
const showNote = computed(() => operation.value === 'add_note');
const showStatusMethod = computed(() => operation.value === 'change_status');

const linesError = computed(() => Boolean(schema.value?.properties?.lines?.['x-knot-error']));

const schemaFieldView = computed<DolibarrFieldView>(() => {
  if (
    isDiscoveryUnverifiedExpert(objectRegistryMode.value) &&
    (operation.value === 'create' || operation.value === 'update')
  ) {
    return 'full';
  }
  return 'standard';
});

let registryModeHint: ObjectRegistryMode = OBJECT_REGISTRY_ALL_EXCEPT_UNVERIFIED;

async function loadObjects() {
  loadingObjects.value = true;
  objectsError.value = null;
  try {
    const data = await knotApi.getDolibarrObjects();
    objects.value = data.objects;
    descriptorCacheMeta.value = data.descriptorCache;
    ensureRegistryCoversCurrentObject(registryModeHint);
  } catch (e) {
    objectsError.value = (e as Error)?.message ?? 'Failed to load objects';
  } finally {
    loadingObjects.value = false;
  }
}

function ensureRegistryCoversCurrentObject(resolvedMode: ObjectRegistryMode) {
  const slug = objectType.value;
  if (!slug || !objects.value.length) return;
  const meta = objects.value.find((o) => o.slug === slug);
  if (!meta || meta.fromMap !== false) return;
  if (resolvedMode === OBJECT_REGISTRY_ALL_EXCEPT_UNVERIFIED || resolvedMode === OBJECT_REGISTRY_CURATED) {
    registryWidenedForObject.value = true;
    patch({ objectRegistryMode: OBJECT_REGISTRY_DISCOVERY });
  }
}

async function loadSchema() {
  schemaError.value = null;
  if (!objectType.value) {
    schema.value = null;
    return;
  }
  loadingSchema.value = true;
  try {
    schema.value = await knotApi.getDolibarrSchema(objectType.value, operation.value, {
      fieldView: schemaFieldView.value,
    });
  } catch (e) {
    schemaError.value = (e as Error)?.message ?? 'Failed to load schema';
    schema.value = { type: 'object', 'x-knot-error': true } as DolibarrSchema;
  } finally {
    loadingSchema.value = false;
  }
}

async function loadVerbs() {
  verbsError.value = null;
  verbs.value = [];
  verbsLoaded.value = false;
  if (!objectType.value) return;
  loadingVerbs.value = true;
  try {
    verbs.value = await knotApi.getDolibarrVerbs(objectType.value, true);
    verbsLoaded.value = true;
  } catch (e) {
    verbsError.value = (e as Error)?.message ?? 'Failed to discover verbs';
  } finally {
    loadingVerbs.value = false;
  }
}

async function loadStateMachineHints(): Promise<void> {
  smError.value = null;
  smCurrent.value = null;
  smProbable.value = [];
  if (operation.value !== 'change_status') {
    return;
  }
  const slug = objectType.value;
  const id = parseInt(String(idValue.value).trim(), 10);
  if (!slug || !Number.isFinite(id) || id <= 0) {
    return;
  }
  smLoading.value = true;
  try {
    const data = await knotApi.getStateMachineProbableTransitions(slug, id);
    smCurrent.value = data.currentLogicalState ?? null;
    smProbable.value = Array.isArray(data.probableTransitions) ? data.probableTransitions : [];
  } catch (e) {
    smError.value = (e as Error)?.message ?? 'State machine hints failed';
  } finally {
    smLoading.value = false;
  }
}

const statusOptions = computed(() => {
  if (verbsLoaded.value && verbs.value.length > 0) {
    return verbs.value.map((v) => ({
      value: v.name,
      label: v.name,
      maturity: v.maturity,
      simulateError: v.simulateError,
    }));
  }
  return STATUS_METHODS.map((m) => ({
    value: m,
    label: m,
    maturity: 'verified' as const,
    simulateError: null as string | null,
  }));
});

const selectedVerb = computed(() =>
  statusOptions.value.find((v) => v.value === statusMethod.value) ?? null,
);

onMounted(() => {
  const v = props.modelValue ?? {};
  const upd: Record<string, unknown> = { ...v };
  let changed = false;
  if ('action' in v && !('operation' in v)) {
    upd.operation = v.action;
    delete upd.action;
    changed = true;
  }
  if ('data' in v && !('fields' in v)) {
    upd.fields = v.data;
    delete upd.data;
    changed = true;
  }
  const rawMode = v.objectRegistryMode;
  if (rawMode === undefined || rawMode === null || String(rawMode).trim() === '') {
    upd.objectRegistryMode = OBJECT_REGISTRY_ALL_EXCEPT_UNVERIFIED;
    changed = true;
  }
  if (rawMode === OBJECT_REGISTRY_CURATED) {
    upd.objectRegistryMode = OBJECT_REGISTRY_ALL_EXCEPT_UNVERIFIED;
    changed = true;
  }
  registryModeHint = normalizeObjectRegistryMode(
    changed ? upd.objectRegistryMode : v.objectRegistryMode,
  );
  if (changed) emit('update:modelValue', upd);
  loadObjects().then(() => loadSchema());
});

watch([objectType, operation, schemaFieldView], () => {
  void loadSchema();
});

watch([objectType, operation], ([slug, op]) => {
  if (op === 'change_status' && slug) {
    void loadVerbs();
  }
  void loadStateMachineHints();
});

watch(idValue, () => {
  void loadStateMachineHints();
});

watch(objectRegistryMode, () => {
  const slug = objectType.value;
  if (slug && !filteredObjects.value.some((o) => o.slug === slug)) {
    patch({ objectType: '' });
  }
});

function addLine() {
  lines.value = [...lines.value, {}];
}

function removeLine(idx: number) {
  const arr = [...lines.value];
  arr.splice(idx, 1);
  lines.value = arr;
}

function updateLine(idx: number, v: Record<string, unknown>) {
  const arr = [...lines.value];
  arr[idx] = v;
  lines.value = arr;
}

const advancedJson = computed({
  get: () => JSON.stringify({ fields: fields.value, lines: lines.value }, null, 2),
  set: (raw: string) => {
    try {
      const parsed = JSON.parse(raw || '{}');
      const update: Record<string, unknown> = {};
      if (parsed && typeof parsed === 'object') {
        if ('fields' in parsed) update.fields = parsed.fields;
        if ('lines' in parsed) update.lines = parsed.lines;
      }
      patch(update);
    } catch {
      // Invalid JSON — ignore until the user fixes it.
    }
  },
});
</script>

<template>
  <div class="k-space-y-3">
    <div class="k-flex k-items-center k-gap-2 k-text-knot-primary">
      <Database :size="14" />
      <span class="k-text-xs k-font-bold k-uppercase k-tracking-wider">{{ t('dolibarrObject.title') }}</span>
    </div>

    <p v-if="objectsError" class="k-text-[11px] k-text-knot-danger">
      {{ objectsError }}
    </p>

    <div
      v-if="registryWidenedForObject"
      class="k-rounded-knot-sm k-border k-border-knot-warning k-bg-knot-warning-soft k-text-knot-text k-px-3 k-py-2 k-space-y-1 k-text-[11px]"
    >
      <div class="k-font-semibold k-flex k-items-center k-gap-1 k-text-knot-warning">
        <AlertTriangle :size="12" />
        {{ t('dolibarrObject.registryWidenedTitle') }}
      </div>
      <p class="k-text-knot-text-muted">
        {{ t('dolibarrObject.registryWidenedBody') }}
      </p>
    </div>

    <div
      v-if="showDescriptorCacheBanner"
      class="k-rounded-knot-sm k-border k-border-knot-warning k-bg-knot-warning-soft k-text-knot-text k-px-3 k-py-2 k-space-y-1 k-text-[11px]"
    >
      <div class="k-font-semibold k-text-knot-warning">
        {{ t('dolibarrObject.descriptorCacheTitle') }}
      </div>
      <p class="k-text-knot-text-muted">
        {{ t('dolibarrObject.descriptorCacheBody') }}
      </p>
      <a :href="setupIntrospectionUrl" class="k-inline-block k-font-semibold k-text-knot-primary hover:k-underline">
        {{ t('dolibarrObject.descriptorCacheLink') }}
      </a>
    </div>

    <div
      v-if="showExpertRegistryBanner"
      class="k-rounded-knot-sm k-border k-border-knot-warning k-bg-knot-warning-soft k-text-knot-text k-px-3 k-py-2 k-space-y-1 k-text-[11px]"
    >
      <div class="k-font-semibold k-flex k-items-center k-gap-1 k-text-knot-warning">
        <AlertTriangle :size="12" />
        {{ t('dolibarrObject.expertModeTitle') }}
      </div>
      <p class="k-text-knot-text-muted">
        {{ t('dolibarrObject.expertModeBody') }}
      </p>
    </div>

    <div class="k-grid k-grid-cols-1 sm:k-grid-cols-3 k-gap-2">
      <div class="k-space-y-1">
        <label class="k-flex k-items-center k-gap-1 k-text-[11px] k-text-knot-text-soft k-font-bold">
          <Filter :size="12" class="k-text-knot-text-muted" />
          {{ t('dolibarrObject.registryLabel') }}
        </label>
        <select
          v-model="objectRegistryMode"
          class="k-w-full k-px-2 k-py-1.5 k-text-sm k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text"
        >
          <option :value="OBJECT_REGISTRY_ALL_EXCEPT_UNVERIFIED">{{ t('dolibarrObject.registryAllExceptUnverified') }}</option>
          <option :value="OBJECT_REGISTRY_ALL">{{ t('dolibarrObject.registryAll') }}</option>
          <option :value="OBJECT_REGISTRY_DISCOVERY">{{ t('dolibarrObject.registryDiscovery') }}</option>
          <option :value="OBJECT_REGISTRY_DISCOVERY_UNVERIFIED">{{ t('dolibarrObject.registryDiscoveryUnverified') }}</option>
        </select>
        <p v-if="showDiscoveryRegistryHint" class="k-text-[10px] k-leading-snug k-text-knot-text-muted">
          {{ t('dolibarrObject.registryDiscoverySameObjectListHint') }}
        </p>
      </div>
      <div class="k-space-y-1">
        <label class="k-text-[11px] k-text-knot-text-soft k-font-bold">{{ t('dolibarrObject.objectLabel') }}</label>
        <select
          v-model="objectType"
          data-knot-test="dolibarr-object-type-select"
          :disabled="loadingObjects"
          class="k-w-full k-px-2 k-py-1.5 k-text-sm k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text"
        >
          <option value="">{{ t('dolibarrObject.objectPlaceholder') }}</option>
          <option v-for="o in filteredObjects" :key="o.slug" :value="o.slug">
            {{ registryOptionLabel(o) }}
          </option>
        </select>
      </div>
      <div class="k-space-y-1">
        <label class="k-text-[11px] k-text-knot-text-soft k-font-bold">{{ t('dolibarrObject.operationLabel') }}</label>
        <select
          v-model="operation"
          data-knot-test="dolibarr-operation-select"
          class="k-w-full k-px-2 k-py-1.5 k-text-sm k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text"
        >
          <option v-for="op in OPERATIONS" :key="op.value" :value="op.value">
            {{ op.label }}
          </option>
        </select>
      </div>
    </div>

    <div v-if="objectType && objectMeta" class="k-flex k-flex-wrap k-items-center k-gap-2">
      <span
        v-if="objectMeta.fromMap !== false"
        class="k-rounded-knot-pill k-bg-knot-primary-soft k-text-knot-primary k-px-2 k-py-0.5 k-text-[10px] k-font-bold k-uppercase k-tracking-wide"
      >
        MAP
      </span>
      <span
        v-else
        class="k-rounded-knot-pill k-bg-knot-warning-soft k-text-knot-warning k-px-2 k-py-0.5 k-text-[10px] k-font-bold k-uppercase k-tracking-wide"
      >
        Discovery
      </span>
    </div>

    <div
      v-if="schema?.['x-dolibarr-permission']"
      class="k-flex k-items-center k-gap-2 k-px-2 k-py-1.5 k-rounded-knot-sm k-bg-knot-warning/10 k-border k-border-knot-warning/30 k-text-[11px] k-text-knot-warning"
    >
      <Lock :size="12" />
      <span>{{ t('dolibarrObject.permissionRequired') }} <code>{{ schema['x-dolibarr-permission'] }}</code></span>
    </div>

    <div
      v-if="schema?.['x-knot-error']"
      class="k-flex k-items-center k-gap-2 k-px-2 k-py-1.5 k-rounded-knot-sm k-bg-knot-warning/10 k-border k-border-knot-warning/30 k-text-[11px] k-text-knot-warning"
    >
      <AlertTriangle :size="12" />
      <span>{{ t('dolibarrObject.schemaUnavailable') }}</span>
    </div>

    <div v-if="schemaError && !schema?.['x-knot-error']" class="k-text-[11px] k-text-knot-danger">
      {{ schemaError }}
    </div>

    <div v-if="showId" class="k-space-y-1">
      <label class="k-text-[11px] k-text-knot-text-soft k-font-bold">{{ t('dolibarrObject.idLabel') }}</label>
      <input
        v-model="idValue"
        :placeholder="t('dolibarrObject.idPlaceholder')"
        class="k-w-full k-px-2 k-py-1.5 k-text-sm k-font-mono k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text"
      />
    </div>

    <div v-if="showStatusMethod" ref="canvasSmHintsAnchorRef" class="k-space-y-1">
      <label class="k-text-[11px] k-text-knot-text-soft k-font-bold k-flex k-items-center k-gap-2">
        <span>{{ t('dolibarrObject.statusMethodLabel') }}</span>
        <Loader2 v-if="loadingVerbs" :size="11" class="k-animate-spin k-text-knot-text-muted" />
      </label>
      <select
        v-model="statusMethod"
        class="k-w-full k-px-2 k-py-1.5 k-text-sm k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text"
      >
        <option v-for="opt in statusOptions" :key="opt.value" :value="opt.value">
          {{ opt.label }}{{ opt.maturity === 'experimental' ? t('dolibarrObject.experimentalSuffix') : '' }}
        </option>
      </select>
      <div v-if="showCustomStatusMethod" class="k-space-y-1">
        <label class="k-text-[11px] k-text-knot-text-soft">{{ t('dolibarrObject.statusMethodCustomLabel') }}</label>
        <input
          v-model="statusMethodCustom"
          type="text"
          class="k-w-full k-px-2 k-py-1.5 k-text-sm k-font-mono k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text"
          :placeholder="t('dolibarrObject.statusMethodCustomPlaceholder')"
        />
        <p class="k-text-[10px] k-text-knot-text-muted">
          {{ t('dolibarrObject.statusMethodCustomHint') }}
        </p>
      </div>
      <div
        v-if="objectType && idValue && String(idValue).trim() !== '' && Number(idValue) > 0"
        class="k-rounded-knot-sm k-border k-border-knot-border k-bg-knot-surface-soft k-p-2 k-space-y-1"
      >
        <div class="k-text-[11px] k-font-bold k-text-knot-text">{{ t('dolibarrObject.smHintsTitle') }}</div>
        <div v-if="smLoading" class="k-text-[11px] k-text-knot-text-muted">{{ t('dolibarrObject.smHintsLoading') }}</div>
        <div v-else-if="smError" class="k-text-[11px] k-text-knot-danger">{{ smError }}</div>
        <template v-else>
          <div class="k-text-[11px]">
            <span class="k-text-knot-text-soft">{{ t('dolibarrObject.smLogicalStateLabel') }}:</span>
            <code class="k-ml-1">{{ smCurrent ?? '—' }}</code>
          </div>
          <ul v-if="smProbable.length" class="k-mt-1 k-max-h-28 k-overflow-auto k-space-y-0.5 k-text-[10px] k-font-mono">
            <li v-for="p in smProbable.slice(0, 12)" :key="p.method">
              <span class="k-font-semibold">{{ p.method }}</span>
              <span class="k-text-knot-text-soft"> · {{ p.probability }} · {{ p.maturity }}</span>
            </li>
          </ul>
          <p v-else class="k-text-[10px] k-text-knot-text-muted">{{ t('dolibarrObject.smProbableEmpty') }}</p>
        </template>
      </div>
      <div v-if="selectedVerb" class="k-flex k-items-center k-gap-2 k-text-[11px]">
        <span
          v-if="selectedVerb.maturity === 'verified'"
          class="k-flex k-items-center k-gap-1 k-px-1.5 k-py-0.5 k-rounded-knot-pill k-bg-knot-success-soft k-text-knot-success k-font-semibold"
          :title="t('dolibarrObject.verbVerifiedTitle')"
        >
          <ShieldCheck :size="10" /> {{ t('dolibarrObject.verbVerified') }}
        </span>
        <span
          v-else
          class="k-flex k-items-center k-gap-1 k-px-1.5 k-py-0.5 k-rounded-knot-pill k-bg-knot-warning-soft k-text-knot-warning k-font-semibold"
          :title="t('dolibarrObject.verbExperimentalTitle')"
        >
          <FlaskConical :size="10" /> {{ t('dolibarrObject.verbExperimental') }}
        </span>
        <span v-if="selectedVerb.simulateError" class="k-text-knot-text-soft k-truncate">
          {{ selectedVerb.simulateError }}
        </span>
      </div>
      <p v-if="verbsError" class="k-text-[11px] k-text-knot-danger">{{ verbsError }}</p>
    </div>

    <div v-if="showNote" class="k-space-y-1">
      <label class="k-text-[11px] k-text-knot-text-soft k-font-bold">{{ t('dolibarrObject.noteLabel') }}</label>
      <textarea
        v-model="note"
        rows="3"
        class="k-w-full k-px-2 k-py-1.5 k-text-sm k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text"
      ></textarea>
    </div>

    <div v-if="showFields" class="k-space-y-2">
      <div class="k-flex k-items-center k-gap-1 k-border-b k-border-knot-border">
        <button
          type="button"
          class="k-px-3 k-py-1.5 k-text-[11px] k-font-bold k-uppercase k-tracking-wider k-border-b-2 k-transition"
          :class="tab === 'fields' ? 'k-border-knot-primary k-text-knot-primary' : 'k-border-transparent k-text-knot-text-muted hover:k-text-knot-text'"
          @click="tab = 'fields'"
        >
          {{ t('dolibarrObject.tabFields') }}
        </button>
        <button
          type="button"
          class="k-px-3 k-py-1.5 k-text-[11px] k-font-bold k-uppercase k-tracking-wider k-border-b-2 k-transition"
          :class="tab === 'advanced' ? 'k-border-knot-primary k-text-knot-primary' : 'k-border-transparent k-text-knot-text-muted hover:k-text-knot-text'"
          @click="tab = 'advanced'"
        >
          {{ t('dolibarrObject.tabAdvanced') }}
        </button>
      </div>

      <div v-if="loadingSchema" class="k-flex k-items-center k-gap-2 k-text-[11px] k-text-knot-text-muted k-py-3">
        <Loader2 :size="12" class="k-animate-spin" />
        <span>{{ t('dolibarrObject.loadingSchema') }}</span>
      </div>

      <template v-else>
        <div v-show="tab === 'fields'" class="k-space-y-3">
          <DynamicForm
            :schema="fieldsSchema"
            :model-value="fields"
            @update:model-value="(v) => fields = v"
          />

          <div v-if="showLines" class="k-space-y-2 k-pt-2 k-border-t k-border-knot-border">
            <div class="k-flex k-items-center k-justify-between">
              <span class="k-text-[11px] k-font-bold k-uppercase k-tracking-wider k-text-knot-text-soft">
                {{ t('dolibarrObject.linesHeading', { count: lines.length }) }}
              </span>
              <button
                type="button"
                class="k-flex k-items-center k-gap-1 k-px-2 k-py-1 k-text-[11px] k-rounded-knot-sm k-bg-knot-primary-soft k-text-knot-primary hover:k-bg-knot-primary/20 k-transition"
                @click="addLine"
              >
                <Plus :size="11" />
                <span>{{ t('dolibarrObject.lineAdd') }}</span>
              </button>
            </div>

            <div
              v-if="linesError"
              class="k-flex k-items-center k-gap-2 k-px-2 k-py-1.5 k-rounded-knot-sm k-bg-knot-warning/10 k-border k-border-knot-warning/30 k-text-[11px] k-text-knot-warning"
            >
              <AlertTriangle :size="12" />
              <span>{{ t('dolibarrObject.linesError') }}</span>
            </div>

            <p v-if="!lines.length" class="k-text-[11px] k-italic k-text-knot-text-muted">
              {{ t('dolibarrObject.linesEmpty') }}
            </p>

            <div
              v-for="(line, idx) in lines"
              :key="idx"
              class="k-space-y-2 k-p-2 k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border"
            >
              <div class="k-flex k-items-center k-justify-between">
                <span class="k-text-[11px] k-font-bold k-text-knot-text-soft">{{ t('dolibarrObject.lineNumber', { n: idx + 1 }) }}</span>
                <button
                  type="button"
                  class="k-text-knot-text-muted hover:k-text-knot-danger k-transition"
                  :title="t('dolibarrObject.lineRemove')"
                  @click="removeLine(idx)"
                >
                  <Trash2 :size="12" />
                </button>
              </div>
              <DynamicForm
                v-if="lineItemSchema"
                :schema="lineItemSchema"
                :model-value="line"
                @update:model-value="(v) => updateLine(idx, v)"
              />
              <textarea
                v-else
                :value="JSON.stringify(line, null, 2)"
                rows="4"
                @input="(e) => {
                  try { updateLine(idx, JSON.parse((e.target as HTMLTextAreaElement).value || '{}')); } catch {}
                }"
                class="k-w-full k-px-2 k-py-1.5 k-text-xs k-font-mono k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border k-text-knot-text"
              ></textarea>
            </div>
          </div>
        </div>

        <div v-show="tab === 'advanced'" class="k-space-y-1">
          <label class="k-text-[11px] k-text-knot-text-soft k-font-bold">{{ t('dolibarrObject.advancedJsonLabel') }}</label>
          <textarea
            :value="advancedJson"
            rows="10"
            @input="(e) => advancedJson = (e.target as HTMLTextAreaElement).value"
            class="k-w-full k-px-2 k-py-1.5 k-text-xs k-font-mono k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text"
          ></textarea>
        </div>
      </template>
    </div>
  </div>
</template>
