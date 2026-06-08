<!--
  Credentials view — list and inspect stored credentials.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  ShieldCheck,
  Trash2,
  RefreshCw,
  KeyRound,
  Lock,
  AlertTriangle,
  Info,
  Plus,
  Pencil,
  X,
  Save,
  PlugZap,
} from 'lucide-vue-next';
import { knotApi, type ConnectorDescriptor, type CredentialSchema, type CredentialSummary, type OAuthProvider } from '../lib/api';
import { getConnectorsCatalogCached } from '../lib/connectorDescriptorsCache';
import { resolveConnectorLabel } from '../lib/connectorLabels';
import { useConfirm } from '../composables/useConfirm';

const confirmDialog = useConfirm();

defineProps<{
  workflowId: number | null;
  executionId: number | null;
}>();

const { t } = useI18n();

const credentials = ref<CredentialSummary[]>([]);
const counts = ref<Record<string, number>>({});
const loading = ref(true);
const error = ref<string | null>(null);
const success = ref<string | null>(null);
const search = ref('');
const selectedConnector = ref<string>('all');
const connectors = ref<ConnectorDescriptor[]>([]);
const oauthProviders = ref<OAuthProvider[]>([]);
const oauthLoading = ref(false);
const modalOpen = ref(false);
const editing = ref<CredentialSummary | null>(null);
const testing = ref(false);
const saving = ref(false);
const form = ref({
  label: '',
  connectorType: '',
  type: '',
  expiresAt: '',
  secrets: {} as Record<string, string>,
});

async function load() {
  loading.value = true;
  error.value = null;
  try {
    const [result, catalog, oauth] = await Promise.all([
      knotApi.listCredentials(),
      getConnectorsCatalogCached(),
      knotApi.listOAuthProviders().catch(() => ({ providers: [] as OAuthProvider[] })),
    ]);
    credentials.value = result.credentials;
    counts.value = result.counts;
    connectors.value = catalog.connectors;
    oauthProviders.value = oauth.providers;
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('credentialsPage.loadError');
  } finally {
    loading.value = false;
  }
}

const connectorTypes = computed(() => {
  const types = Object.entries(counts.value).map(([id, total]) => ({ id, total }));
  return [{ id: 'all', total: credentials.value.length }, ...types];
});

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase();
  return credentials.value.filter((c) => {
    if (selectedConnector.value !== 'all' && c.connectorType !== selectedConnector.value) return false;
    if (!q) return true;
    return (
      c.label.toLowerCase().includes(q) ||
      c.ref.toLowerCase().includes(q) ||
      c.connectorType.toLowerCase().includes(q)
    );
  });
});

const credentialConnectors = computed(() =>
  connectors.value.filter((connector) => connector.credentialType && connector.credentialSchema),
);

function credentialConnectorLabel(m: ConnectorDescriptor['metadata']): string {
  const lk = m.labelKey;
  if (lk) {
    return resolveConnectorLabel(lk, String(m.label ?? m.id));
  }
  return String(m.label ?? m.id);
}

const selectedConnectorDescriptor = computed(() =>
  credentialConnectors.value.find((connector) => connector.metadata.id === form.value.connectorType) ?? null,
);

const activeSchema = computed<CredentialSchema | null>(() => selectedConnectorDescriptor.value?.credentialSchema ?? null);

const activeFields = computed(() => {
  const schema = activeSchema.value;
  if (!schema) {
    return [];
  }

  const properties = schema.properties ?? {};
  if (Object.keys(properties).length > 0) {
    return Object.entries(properties).map(([name, property]) => ({
      name,
      title: property.title || name,
      secret: property.secret === true,
      type: property.type || 'string',
      required: schema.required?.includes(name) ?? false,
      description: property.description || '',
    }));
  }

  const legacyFields = (schema as CredentialSchema & { fields?: Array<{
    name: string;
    label?: string;
    labelKey?: string;
    title?: string;
    type?: string;
    secret?: boolean;
    required?: boolean;
    description?: string;
    descriptionKey?: string;
    uiHidden?: boolean;
  }> }).fields ?? [];

  return legacyFields
    .filter((field) => field.uiHidden !== true)
    .map((field) => ({
      name: field.name,
      title: field.labelKey
        ? t(field.labelKey)
        : field.label || field.title || field.name,
      secret: field.secret === true,
      type: field.type || 'string',
      required: field.required === true,
      description: field.descriptionKey
        ? t(field.descriptionKey)
        : field.description || '',
    }));
});

const showWhatsappCloudHelp = computed(
  () => form.value.connectorType === 'action.whatsapp_cloud',
);

function resetForm() {
  form.value = {
    label: '',
    connectorType: credentialConnectors.value[0]?.metadata.id ?? '',
    type: credentialConnectors.value[0]?.credentialType ?? 'generic',
    expiresAt: '',
    secrets: {},
  };
}

function openCreate() {
  editing.value = null;
  resetForm();
  modalOpen.value = true;
}

function openEdit(credential: CredentialSummary) {
  editing.value = credential;
  form.value = {
    label: credential.label,
    connectorType: credential.connectorType,
    type: credential.type,
    expiresAt: credential.expiresAt ?? '',
    secrets: {},
  };
  modalOpen.value = true;
}

function closeModal() {
  modalOpen.value = false;
  editing.value = null;
  testing.value = false;
  saving.value = false;
}

function onConnectorChange() {
  const descriptor = selectedConnectorDescriptor.value;
  form.value.type = descriptor?.credentialType ?? 'generic';
  form.value.secrets = {};
}

async function testCurrentCredential() {
  testing.value = true;
  error.value = null;
  try {
    await knotApi.testCredential({
      label: form.value.label || t('credentialsPage.testCredentialLabel'),
      connectorType: form.value.connectorType,
      type: form.value.type,
      secrets: form.value.secrets,
      expiresAt: form.value.expiresAt || null,
    });
    success.value = t('credentialsPage.testPassed');
    setTimeout(() => (success.value = null), 3000);
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('credentialsPage.testFailed');
  } finally {
    testing.value = false;
  }
}

async function saveCredential() {
  saving.value = true;
  error.value = null;
  try {
    const payload = {
      label: form.value.label,
      connectorType: form.value.connectorType,
      type: form.value.type,
      secrets: form.value.secrets,
      expiresAt: form.value.expiresAt || null,
    };
    if (editing.value) {
      await knotApi.updateCredential(editing.value.id, payload);
      success.value = t('credentialsPage.credentialUpdated', { label: form.value.label });
    } else {
      await knotApi.createCredential(payload);
      success.value = t('credentialsPage.credentialCreated', { label: form.value.label });
    }
    setTimeout(() => (success.value = null), 3000);
    closeModal();
    await load();
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('credentialsPage.saveFailed');
  } finally {
    saving.value = false;
  }
}

async function remove(credential: CredentialSummary) {
  const ok = await confirmDialog.confirm({
    title: t('credentialsPage.deleteConfirmTitle'),
    message: t('credentialsPage.deleteConfirmMessage', { label: credential.label }),
    danger: true,
  });
  if (!ok) return;
  try {
    await knotApi.deleteCredential(credential.id);
    success.value = t('credentialsPage.credentialDeleted', { label: credential.label });
    setTimeout(() => (success.value = null), 3000);
    await load();
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('credentialsPage.deleteFailed');
  }
}

function formatDate(value: string | null): string {
  if (!value) return '—';
  try {
    return new Date(value.replace(' ', 'T')).toLocaleString();
  } catch {
    return value;
  }
}

async function startOAuth(provider: OAuthProvider) {
  const clientId = window.prompt(t('credentialsPage.oauthClientIdPrompt', { provider: provider.label }))?.trim();
  if (!clientId) return;
  const redirectUri = `${window.location.origin}${window.location.pathname.replace(/preview\.php.*$/, '')}api/oauth.php?action=callback`;
  oauthLoading.value = true;
  error.value = null;
  try {
    const result = await knotApi.startOAuth({
      provider: provider.id,
      clientId,
      redirectUri,
      scopes: provider.defaultScopes,
    });
    window.open(result.authorizationUrl, '_blank', 'noopener');
    success.value = `Authorisation ${provider.label} ouverte dans un nouvel onglet.`;
    setTimeout(() => (success.value = null), 4000);
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('credentialsPage.oauthStartFailed');
  } finally {
    oauthLoading.value = false;
  }
}

function isExpired(value: string | null): boolean {
  if (!value) return false;
  try {
    return new Date(value.replace(' ', 'T')).getTime() < Date.now();
  } catch {
    return false;
  }
}

onMounted(load);
</script>

<template>
  <div class="knot-view-shell k-p-6 k-w-full k-min-w-0 k-space-y-5">
    <header class="k-flex k-items-center k-justify-between k-gap-4 k-flex-wrap">
      <div class="k-flex k-items-center k-gap-3">
        <div class="k-h-10 k-w-10 k-rounded-knot-sm k-bg-knot-success-soft k-text-knot-success k-flex k-items-center k-justify-center">
          <ShieldCheck :size="20" />
        </div>
        <div>
          <h1 class="k-text-2xl k-font-bold k-text-knot-text">{{ t('credentialsPage.title') }}</h1>
          <p class="k-text-sm k-text-knot-text-muted">
            {{ t('credentialsPage.subtitle') }}
          </p>
        </div>
      </div>
      <div class="k-flex k-items-center k-gap-2">
        <button
          @click="openCreate"
          class="k-px-3 k-py-2 k-rounded-knot-sm k-bg-knot-primary k-text-white k-text-sm k-flex k-items-center k-gap-1.5 hover:k-bg-knot-primary-strong"
        >
          <Plus :size="14" /> {{ t('credentialsPage.newCredential') }}
        </button>
        <button
          @click="load"
          class="k-px-3 k-py-2 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border k-text-sm k-flex k-items-center k-gap-1.5 k-text-knot-text hover:k-border-knot-primary"
        >
          <RefreshCw :size="14" /> {{ t('actions.refresh') }}
        </button>
      </div>
    </header>

    <div class="k-bg-knot-primary-soft k-text-knot-primary k-px-4 k-py-3 k-rounded-knot-sm k-text-sm k-flex k-items-start k-gap-2">
      <Info :size="16" class="k-shrink-0 k-mt-0.5" />
      <div>
        {{ t('credentialsPage.storageBanner') }}
      </div>
    </div>

    <div v-if="error" class="k-bg-knot-danger-soft k-text-knot-danger k-px-4 k-py-3 k-rounded-knot-sm k-text-sm">
      {{ error }}
    </div>
    <div v-if="success" class="k-bg-knot-success-soft k-text-knot-success k-px-4 k-py-3 k-rounded-knot-sm k-text-sm">
      {{ success }}
    </div>

    <div class="k-flex k-items-center k-gap-3 k-flex-wrap">
      <input
        v-model="search"
        type="text"
        :placeholder="t('credentialsPage.searchPlaceholder')"
        class="k-flex-1 k-min-w-[260px] k-px-3 k-py-2 k-text-sm k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border focus:k-outline-none focus:k-border-knot-primary k-text-knot-text"
      />
      <div class="k-flex k-flex-wrap k-gap-1.5">
        <button
          v-for="cat in connectorTypes"
          :key="cat.id"
          @click="selectedConnector = cat.id"
          :class="[
            'k-px-2.5 k-py-1 k-rounded-knot-pill k-text-[11px] k-font-semibold',
            selectedConnector === cat.id
              ? 'k-bg-knot-primary k-text-white'
              : 'k-bg-knot-surface-soft k-text-knot-text-muted hover:k-text-knot-primary',
          ]"
        >
          {{ cat.id === 'all' ? t('credentialsPage.filterAll') : cat.id }} ({{ cat.total }})
        </button>
      </div>
    </div>

    <div class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-shadow-knot-sm k-overflow-x-auto">
      <div v-if="loading" class="k-py-12 k-text-center k-text-knot-text-muted k-text-sm">
        {{ t('credentialsPage.loading') }}
      </div>
      <div v-else-if="!filtered.length" class="k-py-12 k-text-center k-text-knot-text-soft k-text-sm k-space-y-2">
        <Lock :size="32" class="k-mx-auto k-mb-2 k-opacity-60" />
        <div class="k-font-semibold k-text-knot-text">{{ t('empty.credentials.title') }}</div>
        <div>{{ t('empty.credentials.body') }}</div>
      </div>
      <table v-else class="k-w-full k-text-sm">
        <thead class="k-bg-knot-surface-soft k-text-knot-text-soft k-text-[11px] k-uppercase k-tracking-wider">
          <tr>
            <th class="k-text-left k-px-4 k-py-3 k-font-semibold">{{ t('credentialsPage.colLabel') }}</th>
            <th class="k-text-left k-px-4 k-py-3 k-font-semibold">{{ t('credentialsPage.colReference') }}</th>
            <th class="k-text-left k-px-4 k-py-3 k-font-semibold">{{ t('credentialsPage.colConnector') }}</th>
            <th class="k-text-left k-px-4 k-py-3 k-font-semibold">{{ t('credentialsPage.colType') }}</th>
            <th class="k-text-left k-px-4 k-py-3 k-font-semibold">{{ t('credentialsPage.colExpires') }}</th>
            <th class="k-text-left k-px-4 k-py-3 k-font-semibold">{{ t('credentialsPage.colUpdated') }}</th>
            <th class="k-text-right k-px-4 k-py-3 k-font-semibold">{{ t('credentialsPage.colActions') }}</th>
          </tr>
        </thead>
        <tbody>
          <tr
            v-for="credential in filtered"
            :key="credential.id"
            class="k-border-t k-border-knot-border hover:k-bg-knot-surface-soft"
          >
            <td class="k-px-4 k-py-3 k-text-knot-text k-font-semibold k-flex k-items-center k-gap-2">
              <KeyRound :size="14" class="k-text-knot-warning" />
              {{ credential.label }}
            </td>
            <td class="k-px-4 k-py-3 k-font-mono k-text-knot-text-muted">{{ credential.ref }}</td>
            <td class="k-px-4 k-py-3 k-text-knot-text">
              <span class="k-bg-knot-primary-soft k-text-knot-primary k-px-2 k-py-0.5 k-rounded-knot-pill k-text-[11px] k-font-semibold">
                {{ credential.connectorType }}
              </span>
            </td>
            <td class="k-px-4 k-py-3 k-text-knot-text-muted">{{ credential.type }}</td>
            <td class="k-px-4 k-py-3">
              <span v-if="!credential.expiresAt" class="k-text-knot-text-soft">{{ t('credentialsPage.neverExpires') }}</span>
              <span
                v-else
                :class="isExpired(credential.expiresAt) ? 'k-text-knot-danger k-font-semibold k-flex k-items-center k-gap-1' : 'k-text-knot-text-muted'"
              >
                <AlertTriangle v-if="isExpired(credential.expiresAt)" :size="12" />
                {{ formatDate(credential.expiresAt) }}
              </span>
            </td>
            <td class="k-px-4 k-py-3 k-text-knot-text-muted">{{ formatDate(credential.updatedAt) }}</td>
            <td class="k-px-4 k-py-3 k-text-right">
              <button
                @click="openEdit(credential)"
                class="k-px-2 k-py-1 k-rounded-knot-sm k-text-knot-primary hover:k-bg-knot-primary-soft k-text-xs k-inline-flex k-items-center k-gap-1 k-mr-1"
                :title="t('credentialsPage.editTitle', { label: credential.label })"
              >
                <Pencil :size="12" /> {{ t('credentialsPage.editAction') }}
              </button>
              <button
                @click="remove(credential)"
                class="k-px-2 k-py-1 k-rounded-knot-sm k-text-knot-danger hover:k-bg-knot-danger-soft k-text-xs k-inline-flex k-items-center k-gap-1"
                :title="t('credentialsPage.deleteTitle', { label: credential.label })"
              >
                <Trash2 :size="12" /> {{ t('credentialsPage.deleteAction') }}
              </button>
            </td>
          </tr>
        </tbody>
      </table>
    </div>

    <Teleport to="body">
      <div
        v-if="modalOpen"
        class="k-fixed k-inset-0 k-z-[9999] k-bg-black/60 k-backdrop-blur-sm k-flex k-items-center k-justify-center k-p-4"
      >
        <div class="k-w-full k-max-w-2xl k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-lg k-shadow-knot-lg k-overflow-hidden">
          <div class="k-flex k-items-center k-justify-between k-px-5 k-py-4 k-border-b k-border-knot-border">
            <div class="k-flex k-items-center k-gap-3">
              <div class="k-h-9 k-w-9 k-rounded-knot-sm k-bg-knot-primary-soft k-text-knot-primary k-flex k-items-center k-justify-center">
                <PlugZap :size="18" />
              </div>
              <div>
                <h2 class="k-text-lg k-font-bold k-text-knot-text">
                  {{ editing ? t('credentialsPage.editCredential') : t('credentialsPage.newCredential') }}
                </h2>
                <p class="k-text-xs k-text-knot-text-muted">
                  {{ t('credentialsPage.modalSubtitle') }}
                </p>
              </div>
            </div>
            <button @click="closeModal" class="k-text-knot-text-muted hover:k-text-knot-text">
              <X :size="18" />
            </button>
          </div>

          <div class="k-p-5 k-space-y-4">
            <label class="k-block k-space-y-1.5">
              <span class="k-text-xs k-font-semibold k-text-knot-text-muted">{{ t('credentialsPage.colLabel') }}</span>
              <input
                v-model="form.label"
                class="k-w-full k-px-3 k-py-2 k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text focus:k-outline-none focus:k-border-knot-primary"
                :placeholder="t('credentialsPage.labelPlaceholder')"
              />
            </label>

            <div class="k-grid k-grid-cols-1 md:k-grid-cols-2 k-gap-3">
              <label class="k-block k-space-y-1.5">
                <span class="k-text-xs k-font-semibold k-text-knot-text-muted">{{ t('credentialsPage.colConnector') }}</span>
                <select
                  v-model="form.connectorType"
                  @change="onConnectorChange"
                  class="k-w-full k-px-3 k-py-2 k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text focus:k-outline-none focus:k-border-knot-primary"
                >
                  <option v-for="connector in credentialConnectors" :key="connector.metadata.id" :value="connector.metadata.id">
                    {{ credentialConnectorLabel(connector.metadata) }}
                  </option>
                </select>
              </label>
              <label class="k-block k-space-y-1.5">
                <span class="k-text-xs k-font-semibold k-text-knot-text-muted">{{ t('credentialsPage.colCredentialType') }}</span>
                <input
                  v-model="form.type"
                  readonly
                  class="k-w-full k-px-3 k-py-2 k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text-muted"
                />
              </label>
            </div>

            <label class="k-block k-space-y-1.5">
              <span class="k-text-xs k-font-semibold k-text-knot-text-muted">{{ t('credentialsPage.expirationOptional') }}</span>
              <input
                v-model="form.expiresAt"
                type="datetime-local"
                class="k-w-full k-px-3 k-py-2 k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text focus:k-outline-none focus:k-border-knot-primary"
              />
            </label>

            <div v-if="oauthProviders.length" class="k-space-y-2 k-p-4 k-rounded-knot-md k-bg-knot-primary-soft/30 k-border k-border-knot-primary/30">
              <div class="k-flex k-items-center k-justify-between">
                <h3 class="k-text-sm k-font-semibold k-text-knot-text">{{ t('credentialsPage.oauthTitle') }}</h3>
                <span class="k-text-[11px] k-text-knot-text-muted">{{ t('credentialsPage.oauthHint') }}</span>
              </div>
              <div class="k-flex k-flex-wrap k-gap-1.5">
                <button
                  v-for="provider in oauthProviders"
                  :key="provider.id"
                  type="button"
                  @click="startOAuth(provider)"
                  :disabled="oauthLoading"
                  class="k-px-2.5 k-py-1.5 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border k-text-xs k-font-semibold k-text-knot-text hover:k-border-knot-primary hover:k-text-knot-primary disabled:k-opacity-50 k-inline-flex k-items-center k-gap-1.5"
                  :title="provider.docsUrl ?? provider.label"
                >
                  <PlugZap :size="11" /> {{ provider.label }}
                </button>
              </div>
            </div>

            <div
              v-if="showWhatsappCloudHelp"
              class="k-bg-knot-primary-soft k-text-knot-primary k-px-4 k-py-3 k-rounded-knot-sm k-text-sm k-flex k-items-start k-gap-2"
            >
              <Info :size="16" class="k-shrink-0 k-mt-0.5" />
              <div>{{ t('credentialsPage.whatsappCloudHelp') }}</div>
            </div>

            <div class="k-space-y-3 k-p-4 k-rounded-knot-md k-bg-knot-surface-soft k-border k-border-knot-border">
              <div class="k-flex k-items-center k-justify-between">
                <h3 class="k-text-sm k-font-semibold k-text-knot-text">{{ t('credentialsPage.secretFieldsTitle') }}</h3>
                <span class="k-text-[11px] k-text-knot-text-muted">{{ t('credentialsPage.secretFieldsHint') }}</span>
              </div>
              <div v-if="!activeFields.length" class="k-text-sm k-text-knot-text-muted">
                {{ t('credentialsPage.noCredentialFields') }}
              </div>
              <label v-for="field in activeFields" :key="field.name" class="k-block k-space-y-1.5">
                <span class="k-text-xs k-font-semibold k-text-knot-text-muted">
                  {{ field.title }} <span v-if="field.required" class="k-text-knot-danger">*</span>
                </span>
                <p v-if="field.description" class="k-text-[11px] k-text-knot-text-muted k-leading-snug">
                  {{ field.description }}
                </p>
                <input
                  v-model="form.secrets[field.name]"
                  :type="field.secret ? 'password' : 'text'"
                  :placeholder="field.title"
                  class="k-w-full k-px-3 k-py-2 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border k-text-knot-text focus:k-outline-none focus:k-border-knot-primary"
                />
              </label>
            </div>
          </div>

          <div class="k-px-5 k-py-4 k-bg-knot-surface-soft k-border-t k-border-knot-border k-flex k-items-center k-justify-end k-gap-2">
            <button
              @click="testCurrentCredential"
              :disabled="testing || saving"
              class="k-px-3 k-py-2 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border k-text-knot-text k-text-sm hover:k-border-knot-primary disabled:k-opacity-50"
            >
              {{ testing ? t('credentialsPage.testing') : t('credentialsPage.testAction') }}
            </button>
            <button
              @click="saveCredential"
              :disabled="saving || testing"
              class="k-px-3 k-py-2 k-rounded-knot-sm k-bg-knot-primary k-text-white k-text-sm k-inline-flex k-items-center k-gap-1.5 hover:k-bg-knot-primary-strong disabled:k-opacity-50"
            >
              <Save :size="14" /> {{ saving ? t('credentialsPage.saving') : t('credentialsPage.saveCredential') }}
            </button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>
