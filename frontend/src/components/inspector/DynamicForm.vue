<!--
  DynamicForm — JSON-Schema-driven inspector form.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Search, Variable } from 'lucide-vue-next';
import type { DolibarrPickResult, DolibarrSchemaProperty } from '@/lib/api';
import { resolveConnectorLabel } from '@/lib/connectorLabels';
import { KnotExpressionInput } from '@/components/risk';
import DolibarrPicker from './DolibarrPicker.vue';

interface SchemaProperty extends DolibarrSchemaProperty {
  credentialType?: string;
  expression?: boolean;
  placeholder?: string;
  minItems?: number;
  titleKey?: string;
  descriptionKey?: string;
  enumLabelKeys?: string[];
  /** Force JSON textarea for arrays whose items are objects (default: repeater UI). */
  'x-knot-array-editor'?: 'json' | 'repeater';
}

interface Schema {
  type?: string;
  properties?: Record<string, SchemaProperty>;
  required?: string[];
}

const props = defineProps<{
  schema: Schema | null | undefined;
  modelValue: Record<string, unknown>;
}>();

const emit = defineEmits<{
  (e: 'update:modelValue', value: Record<string, unknown>): void;
}>();

const { t } = useI18n();

const expressionOverrides = ref<Record<string, boolean>>({});

/** Shown as literal placeholder (must not use Vue `{{` inside static attributes). */
const EXPR_PLACEHOLDER_HINT = '{{$nodes.trigger.json.id}}';

const hasSchemaButNoFields = computed(() => {
  const s = props.schema;
  if (!s || typeof s !== 'object') return false;
  const propsMap = s.properties;
  return typeof propsMap !== 'object' || propsMap === null || Object.keys(propsMap).length === 0;
});

const fields = computed(() => {
  const propsMap = props.schema?.properties ?? {};
  const required = props.schema?.required ?? [];
  return Object.entries(propsMap)
    .filter(([, def]) => def !== null && def !== undefined && typeof def === 'object')
    .map(([name, def]) => ({
      name,
      def: def as SchemaProperty,
      required: required.includes(name),
      position: typeof def['x-position'] === 'number' ? def['x-position'] : Number.MAX_SAFE_INTEGER,
    }))
    .sort((a, b) => a.position - b.position);
});

function setField(name: string, value: unknown) {
  emit('update:modelValue', { ...props.modelValue, [name]: value });
}

function getField(name: string, def: SchemaProperty): unknown {
  const v = props.modelValue?.[name];
  if (v !== undefined) return v;
  return def.default ?? '';
}

function inferType(def: SchemaProperty): string {
  if (def.enum && def.enum.length > 0) return 'enum';
  if (def.type === 'array') return 'array';
  if (def.type === 'boolean') return 'boolean';
  if (def.type === 'object') return 'json';
  if (def.credentialType) return 'credential';
  if (def.format === 'date-time') return 'datetime';
  if (def.format === 'date') return 'date';
  if (def.format === 'email') return 'email';
  if (def.format === 'tel') return 'tel';
  if (def.format === 'uri' || def.format === 'url') return 'url';
  if (def.type === 'number' || def.type === 'integer') return 'number';
  if (def.multiline || def.format === 'textarea' || def.format === 'html') return 'textarea';
  return 'string';
}

function canExpress(def: SchemaProperty): boolean {
  const t = inferType(def);
  return !['json', 'array', 'credential'].includes(t);
}

function isExpressionMode(name: string, def: SchemaProperty): boolean {
  if (Object.prototype.hasOwnProperty.call(expressionOverrides.value, name)) {
    return expressionOverrides.value[name];
  }
  const v = props.modelValue?.[name];
  if (typeof v === 'string' && v.trim().startsWith('{{')) {
    return true;
  }
  void def;
  return false;
}

function toggleExpression(name: string, def: SchemaProperty) {
  const next = !isExpressionMode(name, def);
  expressionOverrides.value = { ...expressionOverrides.value, [name]: next };

  const current = props.modelValue?.[name];
  if (next) {
    const s = current === undefined || current === null || current === '' ? '' : String(current);
    if (!s.trim().startsWith('{{')) {
      setField(name, s === '' ? '{{ }}' : `{{ ${s} }}`);
    }
  } else {
    if (typeof current === 'string') {
      const m = current.match(/^\s*\{\{(.*)\}\}\s*$/s);
      if (m) {
        const inner = m[1].trim();
        setField(name, inner);
      }
    }
  }
}

// V2.4 — open the rich autocomplete picker. The component handles search +
// rate-limited backend calls; we just track which field is currently bound.
const pickerOpen = ref(false);
const pickerSlug = ref('');
const pickerFieldName = ref('');

function openFkPicker(field: { name: string; def: SchemaProperty }) {
  const fk = field.def['x-dolibarr-fk'];
  if (!fk) return;
  pickerSlug.value = fk.targetSlug;
  pickerFieldName.value = field.name;
  pickerOpen.value = true;
}

function onPickerResult(record: DolibarrPickResult) {
  if (!pickerFieldName.value) return;
  setField(pickerFieldName.value, record.id);
}

function itemSchemaOfArray(def: SchemaProperty): SchemaProperty | null {
  if (def.type !== 'array' || !def.items || typeof def.items !== 'object') return null;
  return def.items as SchemaProperty;
}

function isRepeaterArrayField(def: SchemaProperty): boolean {
  if (def['x-knot-array-editor'] === 'json') return false;
  const items = itemSchemaOfArray(def);
  if (!items || items.type !== 'object' || !items.properties) return false;
  return Object.keys(items.properties).length > 0;
}

function repeaterSubfields(def: SchemaProperty): Array<{ key: string; def: SchemaProperty }> {
  const items = itemSchemaOfArray(def);
  const propsMap = (items?.properties ?? {}) as Record<string, SchemaProperty>;
  return Object.entries(propsMap)
    .filter(([, sub]) => sub !== null && sub !== undefined && typeof sub === 'object')
    .map(([key, sub]) => ({
      key,
      def: sub as SchemaProperty,
      position: typeof sub['x-position'] === 'number' ? sub['x-position'] : Number.MAX_SAFE_INTEGER,
    }))
    .sort((a, b) => a.position - b.position)
    .map(({ key, def: sub }) => ({ key, def: sub }));
}

function getArrayRows(name: string): Record<string, unknown>[] {
  const raw = props.modelValue?.[name];
  if (!Array.isArray(raw)) return [];
  return raw.filter((x) => x !== null && typeof x === 'object' && !Array.isArray(x)) as Record<string, unknown>[];
}

function defaultRepeaterRow(arrayDef: SchemaProperty): Record<string, unknown> {
  const items = itemSchemaOfArray(arrayDef);
  const propsMap = (items?.properties ?? {}) as Record<string, SchemaProperty>;
  const row: Record<string, unknown> = {};
  for (const [k, sub] of Object.entries(propsMap)) {
    if (sub.default !== undefined) row[k] = sub.default as unknown;
    else if (sub.type === 'boolean') row[k] = false;
    else if (sub.type === 'number' || sub.type === 'integer') row[k] = 0;
    else row[k] = '';
  }
  return row;
}

function addRepeaterRow(name: string, arrayDef: SchemaProperty) {
  const next = [...getArrayRows(name), defaultRepeaterRow(arrayDef)];
  setField(name, next);
}

function removeRepeaterRow(name: string, arrayDef: SchemaProperty, index: number) {
  const minItems = typeof arrayDef.minItems === 'number' ? arrayDef.minItems : 0;
  const rows = getArrayRows(name);
  if (rows.length <= minItems) return;
  setField(
    name,
    rows.filter((_, i) => i !== index),
  );
}

function setRepeaterCell(name: string, rowIndex: number, cellKey: string, value: unknown) {
  const rows = getArrayRows(name);
  const next = rows.map((r, i) => (i === rowIndex ? { ...r, [cellKey]: value } : r));
  setField(name, next);
}

function getRepeaterCell(row: Record<string, unknown>, cellKey: string, subDef: SchemaProperty): unknown {
  const v = row[cellKey];
  if (v !== undefined) return v;
  return subDef.default ?? '';
}

function schemaFieldTitle(def: SchemaProperty, nameFallback: string): string {
  const k = def.titleKey;
  if (k) {
    return resolveConnectorLabel(k, def.title !== undefined ? String(def.title) : nameFallback);
  }
  return String(def.title ?? nameFallback);
}

function schemaFieldDescription(def: SchemaProperty): string | undefined {
  const k = def.descriptionKey;
  if (k) {
    const out = resolveConnectorLabel(k, def.description !== undefined ? String(def.description) : '');
    return out !== '' ? out : undefined;
  }
  return def.description !== undefined ? String(def.description) : undefined;
}

function enumOptionLabel(def: SchemaProperty, index: number, raw: unknown): string {
  const keys = def.enumLabelKeys;
  if (keys?.[index]) {
    const fallback =
      def.enumLabels !== undefined && def.enumLabels[index] !== undefined
        ? String(def.enumLabels[index])
        : String(raw);
    return resolveConnectorLabel(keys[index], fallback);
  }
  return String(def.enumLabels?.[index] ?? raw);
}
</script>

<template>
  <div class="k-space-y-3 k-text-sm">
    <p v-if="!fields.length && !props.schema" class="k-text-knot-text-muted k-text-xs k-italic">
      No schema defined for this node — use the Advanced JSON tab.
    </p>
    <p v-else-if="hasSchemaButNoFields" class="k-text-knot-text-muted k-text-xs k-italic">
      No configurable fields for this node.
    </p>
    <div v-for="field in fields" :key="field.name" class="k-space-y-1">
      <div
        class="k-flex k-flex-wrap k-items-center k-gap-x-2 k-gap-y-1 k-text-[11px] k-font-bold k-uppercase k-tracking-wider k-text-knot-text-soft"
      >
        <span class="k-min-w-0 k-break-words">
          {{ schemaFieldTitle(field.def, field.name) }}
          <span v-if="field.required" class="k-text-knot-danger">*</span>
        </span>
        <button
          v-if="canExpress(field.def)"
          type="button"
          class="k-shrink-0 k-text-knot-text-muted hover:k-text-knot-primary k-transition"
          :class="{ 'k-text-knot-primary': isExpressionMode(field.name, field.def) }"
          :title="isExpressionMode(field.name, field.def) ? t('dynamicForm.literalModeTitle') : t('dynamicForm.expressionModeTitle')"
          @click="toggleExpression(field.name, field.def)"
        >
          <Variable :size="11" />
        </button>
      </div>
      <p v-if="schemaFieldDescription(field.def)" class="k-text-[11px] k-text-knot-text-muted k-leading-snug">
        {{ schemaFieldDescription(field.def) }}
      </p>

      <template v-if="isExpressionMode(field.name, field.def)">
        <KnotExpressionInput
          :model-value="String(getField(field.name, field.def) ?? '')"
          :placeholder="EXPR_PLACEHOLDER_HINT"
          @update:model-value="(v) => setField(field.name, v)"
        />
      </template>

      <template v-else-if="inferType(field.def) === 'enum'">
        <select
          :value="getField(field.name, field.def)"
          @change="(e) => setField(field.name, (e.target as HTMLSelectElement).value)"
          class="k-w-full k-px-2.5 k-py-1.5 k-text-sm k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border focus:k-border-knot-primary focus:k-ring-2 focus:k-ring-knot-primary/20 k-text-knot-text"
        >
          <option v-for="(opt, i) in field.def.enum" :key="String(opt)" :value="opt">
            {{ enumOptionLabel(field.def, i, opt) }}
          </option>
        </select>
      </template>

      <template v-else-if="inferType(field.def) === 'boolean'">
        <label class="k-flex k-items-center k-gap-2 k-cursor-pointer">
          <input
            type="checkbox"
            :checked="Boolean(getField(field.name, field.def))"
            @change="(e) => setField(field.name, (e.target as HTMLInputElement).checked)"
          />
          <span class="k-text-xs k-text-knot-text-muted">{{ field.def?.placeholder || 'Enable' }}</span>
        </label>
      </template>

      <template v-else-if="inferType(field.def) === 'number'">
        <div class="k-flex k-items-center k-gap-1">
          <input
            type="number"
            :value="getField(field.name, field.def)"
            :min="field.def.minimum"
            :max="field.def.maximum"
            @input="(e) => setField(field.name, Number((e.target as HTMLInputElement).value))"
            class="k-flex-1 k-px-2.5 k-py-1.5 k-text-sm k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border focus:k-border-knot-primary k-text-knot-text"
          />
          <button
            v-if="field.def['x-dolibarr-fk']"
            type="button"
            title="Rechercher dans Dolibarr"
            class="k-px-2 k-py-1.5 k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border hover:k-border-knot-primary k-text-knot-text-muted hover:k-text-knot-primary k-transition"
            @click="openFkPicker(field)"
          >
            <Search :size="12" />
          </button>
        </div>
        <p v-if="field.def['x-dolibarr-fk']" class="k-text-[10px] k-text-knot-text-muted">
          → {{ field.def['x-dolibarr-fk'].targetClass }}
        </p>
      </template>

      <template v-else-if="inferType(field.def) === 'datetime'">
        <input
          type="datetime-local"
          :value="String(getField(field.name, field.def) ?? '')"
          @input="(e) => setField(field.name, (e.target as HTMLInputElement).value)"
          class="k-w-full k-px-2.5 k-py-1.5 k-text-sm k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border focus:k-border-knot-primary k-text-knot-text"
        />
      </template>

      <template v-else-if="inferType(field.def) === 'date'">
        <input
          type="date"
          :value="String(getField(field.name, field.def) ?? '')"
          @input="(e) => setField(field.name, (e.target as HTMLInputElement).value)"
          class="k-w-full k-px-2.5 k-py-1.5 k-text-sm k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border focus:k-border-knot-primary k-text-knot-text"
        />
      </template>

      <template v-else-if="inferType(field.def) === 'email'">
        <input
          type="email"
          :value="String(getField(field.name, field.def) ?? '')"
          :placeholder="field.def?.placeholder ?? ''"
          :maxlength="field.def.maxLength"
          @input="(e) => setField(field.name, (e.target as HTMLInputElement).value)"
          class="k-w-full k-px-2.5 k-py-1.5 k-text-sm k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border focus:k-border-knot-primary k-text-knot-text"
        />
      </template>

      <template v-else-if="inferType(field.def) === 'tel'">
        <input
          type="tel"
          :value="String(getField(field.name, field.def) ?? '')"
          :placeholder="field.def?.placeholder ?? ''"
          :maxlength="field.def.maxLength"
          @input="(e) => setField(field.name, (e.target as HTMLInputElement).value)"
          class="k-w-full k-px-2.5 k-py-1.5 k-text-sm k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border focus:k-border-knot-primary k-text-knot-text"
        />
      </template>

      <template v-else-if="inferType(field.def) === 'url'">
        <input
          type="url"
          :value="String(getField(field.name, field.def) ?? '')"
          :placeholder="field.def?.placeholder ?? ''"
          :maxlength="field.def.maxLength"
          @input="(e) => setField(field.name, (e.target as HTMLInputElement).value)"
          class="k-w-full k-px-2.5 k-py-1.5 k-text-sm k-font-mono k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border focus:k-border-knot-primary k-text-knot-text"
        />
      </template>

      <template v-else-if="inferType(field.def) === 'textarea'">
        <textarea
          :value="String(getField(field.name, field.def) ?? '')"
          :placeholder="field.def?.placeholder ?? ''"
          :maxlength="field.def.maxLength"
          rows="4"
          @input="(e) => setField(field.name, (e.target as HTMLTextAreaElement).value)"
          class="k-w-full k-px-2.5 k-py-1.5 k-text-xs k-font-mono k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border focus:k-border-knot-primary k-text-knot-text"
        ></textarea>
        <p v-if="field.def.format === 'html'" class="k-text-[10px] k-text-knot-text-muted">
          {{ t('dynamicForm.htmlAccepted') }}
        </p>
      </template>

      <template v-else-if="isRepeaterArrayField(field.def)">
        <div class="k-space-y-2">
          <div
            v-for="(row, ri) in getArrayRows(field.name)"
            :key="ri"
            class="k-rounded-knot-sm k-border k-border-knot-border k-bg-knot-surface-soft k-p-2 k-space-y-2"
          >
            <div class="k-flex k-justify-end">
              <button
                type="button"
                class="k-text-[11px] k-font-bold k-text-knot-danger hover:k-underline"
                @click="removeRepeaterRow(field.name, field.def, ri)"
              >
                Remove row
              </button>
            </div>
            <div v-for="sub in repeaterSubfields(field.def)" :key="sub.key" class="k-space-y-1">
              <label class="k-block k-text-[10px] k-font-bold k-uppercase k-tracking-wider k-text-knot-text-soft">
                {{ schemaFieldTitle(sub.def, sub.key) }}
              </label>
              <template v-if="inferType(sub.def) === 'enum'">
                <select
                  :value="getRepeaterCell(row, sub.key, sub.def)"
                  @change="
                    (e) =>
                      setRepeaterCell(field.name, ri, sub.key, (e.target as HTMLSelectElement).value)
                  "
                  class="k-w-full k-px-2 k-py-1 k-text-xs k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border k-text-knot-text"
                >
                  <option v-for="(opt, i) in sub.def.enum" :key="String(opt)" :value="opt">
                    {{ enumOptionLabel(sub.def, i, opt) }}
                  </option>
                </select>
              </template>
              <template v-else-if="inferType(sub.def) === 'boolean'">
                <label class="k-flex k-items-center k-gap-2">
                  <input
                    type="checkbox"
                    :checked="Boolean(getRepeaterCell(row, sub.key, sub.def))"
                    @change="
                      (e) =>
                        setRepeaterCell(field.name, ri, sub.key, (e.target as HTMLInputElement).checked)
                    "
                  />
                </label>
              </template>
              <template v-else-if="inferType(sub.def) === 'number'">
                <input
                  type="number"
                  :value="Number(getRepeaterCell(row, sub.key, sub.def))"
                  @input="
                    (e) =>
                      setRepeaterCell(field.name, ri, sub.key, Number((e.target as HTMLInputElement).value))
                  "
                  class="k-w-full k-px-2 k-py-1 k-text-xs k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border"
                />
              </template>
              <template v-else-if="inferType(sub.def) === 'textarea'">
                <textarea
                  :value="String(getRepeaterCell(row, sub.key, sub.def) ?? '')"
                  rows="2"
                  @input="
                    (e) =>
                      setRepeaterCell(field.name, ri, sub.key, (e.target as HTMLTextAreaElement).value)
                  "
                  class="k-w-full k-px-2 k-py-1 k-text-xs k-font-mono k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border"
                />
              </template>
              <template v-else>
                <input
                  type="text"
                  :value="String(getRepeaterCell(row, sub.key, sub.def) ?? '')"
                  :placeholder="sub.def?.placeholder ?? ''"
                  @input="
                    (e) =>
                      setRepeaterCell(field.name, ri, sub.key, (e.target as HTMLInputElement).value)
                  "
                  class="k-w-full k-px-2 k-py-1 k-text-xs k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border"
                />
              </template>
            </div>
          </div>
          <button
            type="button"
            class="k-w-full k-py-1.5 k-text-[11px] k-font-bold k-rounded-knot-sm k-border k-border-knot-border k-bg-knot-surface hover:k-border-knot-primary k-text-knot-text-muted"
            @click="addRepeaterRow(field.name, field.def)"
          >
            Add row
          </button>
        </div>
      </template>

      <template v-else-if="inferType(field.def) === 'json' || inferType(field.def) === 'array'">
        <textarea
          :value="JSON.stringify(getField(field.name, field.def) ?? (field.def.type === 'array' ? [] : {}), null, 2)"
          rows="4"
          @input="(e) => {
            try {
              setField(field.name, JSON.parse((e.target as HTMLTextAreaElement).value || (field.def.type === 'array' ? '[]' : '{}')));
            } catch {}
          }"
          class="k-w-full k-px-2.5 k-py-1.5 k-text-xs k-font-mono k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border focus:k-border-knot-primary k-text-knot-text"
        ></textarea>
      </template>

      <template v-else-if="inferType(field.def) === 'credential'">
        <input
          :value="String(getField(field.name, field.def) ?? '')"
          :placeholder="`credential:${field.def.credentialType}:label`"
          @input="(e) => setField(field.name, (e.target as HTMLInputElement).value)"
          class="k-w-full k-px-2.5 k-py-1.5 k-text-sm k-font-mono k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border focus:k-border-knot-primary k-text-knot-text"
        />
        <p class="k-text-[10px] k-text-knot-text-muted">
          Credential reference (type: <code>{{ field.def.credentialType }}</code>)
        </p>
      </template>

      <template v-else>
        <input
          :type="field.def.secret ? 'password' : 'text'"
          :value="String(getField(field.name, field.def) ?? '')"
          :placeholder="field.def?.placeholder ?? ''"
          :maxlength="field.def.maxLength"
          @input="(e) => setField(field.name, (e.target as HTMLInputElement).value)"
          class="k-w-full k-px-2.5 k-py-1.5 k-text-sm k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border focus:k-border-knot-primary k-text-knot-text"
        />
      </template>
    </div>

    <DolibarrPicker
      :open="pickerOpen"
      :slug="pickerSlug"
      @update:open="(v) => (pickerOpen = v)"
      @pick="onPickerResult"
    />
  </div>
</template>
