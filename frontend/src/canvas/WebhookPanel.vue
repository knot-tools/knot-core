<!--
  Webhook URL panel — affiche l'URL publique + secret HMAC d'un webhook trigger.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { Copy, RefreshCw, ShieldCheck, Loader2, ExternalLink, Terminal, AlertTriangle } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';
import { knotApi, type WebhookConfig } from '../lib/api';

const { t } = useI18n();

const props = defineProps<{
  workflowId: number;
}>();

const webhook = ref<WebhookConfig | null>(null);
const url = ref<string | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);
const showSecret = ref(false);
const flash = ref<string | null>(null);

async function load() {
  if (!props.workflowId) return;
  loading.value = true;
  error.value = null;
  try {
    const result = await knotApi.getWorkflowWebhook(props.workflowId);
    webhook.value = result.webhook;
    url.value = result.url;
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('webhookPanel.loadFailed');
  } finally {
    loading.value = false;
  }
}

async function provision() {
  loading.value = true;
  error.value = null;
  try {
    const result = await knotApi.provisionWorkflowWebhook(props.workflowId, { method: 'POST', isActive: true });
    webhook.value = result.webhook;
    url.value = result.url;
    show(t('webhookPanel.provisioned'));
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('webhookPanel.provisionFailed');
  } finally {
    loading.value = false;
  }
}

async function rotate() {
  loading.value = true;
  error.value = null;
  try {
    const result = await knotApi.rotateWorkflowWebhook(props.workflowId);
    webhook.value = result.webhook;
    url.value = result.url;
    show(t('webhookPanel.secretRotated'));
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('webhookPanel.rotateFailed');
  } finally {
    loading.value = false;
  }
}

async function copy(text: string, label: string) {
  await navigator.clipboard?.writeText(text);
  show(t('webhookPanel.copied', { label }));
}

function show(message: string) {
  flash.value = message;
  setTimeout(() => {
    if (flash.value === message) flash.value = null;
  }, 2200);
}

const masked = computed(() => {
  const s = webhook.value?.secretHmac ?? '';
  if (!s) return '';
  if (showSecret.value) return s;
  return s.slice(0, 4) + '••••••••' + s.slice(-4);
});

// V2.5.0b-ux-ops (plan chantier 7.C) — ready-to-copy curl
// example so a webhook integrator can test the endpoint in
// 10 seconds without leaving the editor.
const curlExample = computed<string>(() => {
  if (!url.value || !webhook.value) return '';
  const method = webhook.value.method || 'POST';
  const lines = [
    `curl -X ${method} '${url.value}' \\`,
    `  -H 'Content-Type: application/json' \\`,
  ];
  if (webhook.value.hasSecret) {
    lines.push(`  -H 'X-Knot-Signature: <hmac-sha256-of-body-with-secret>' \\`);
  }
  if (method !== 'GET') {
    lines.push(`  -d '{"hello":"knot","sample":42}'`);
  } else {
    // Strip the trailing backslash + newline for GET.
    lines[lines.length - 1] = lines[lines.length - 1].replace(/ \\$/, '');
  }
  return lines.join('\n');
});

watch(() => props.workflowId, () => {
  webhook.value = null;
  url.value = null;
  load();
});
onMounted(load);
</script>

<template>
  <div class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-sm k-p-3 k-space-y-3 k-text-xs">
    <header class="k-flex k-items-center k-justify-between">
      <div class="k-flex k-items-center k-gap-2">
        <ShieldCheck :size="14" class="k-text-knot-primary" />
        <span class="k-font-bold k-text-knot-text k-uppercase k-tracking-wider">{{ t('webhookPanel.title') }}</span>
        <span v-if="webhook" :class="[
          'k-px-1.5 k-py-0.5 k-rounded-knot-pill k-text-[10px] k-font-bold k-uppercase',
          webhook.isActive ? 'k-bg-knot-success-soft k-text-knot-success' : 'k-bg-knot-warning-soft k-text-knot-warning',
        ]">
          {{ webhook.isActive ? t('webhookPanel.active') : t('webhookPanel.inactive') }}
        </span>
      </div>
      <button v-if="webhook" @click="rotate" :disabled="loading" class="k-inline-flex k-items-center k-gap-1 k-text-knot-text-soft hover:k-text-knot-primary disabled:k-opacity-50">
        <RefreshCw :size="11" :class="loading ? 'k-animate-spin' : ''" /> {{ t('webhookPanel.rotateSecret') }}
      </button>
    </header>

    <div v-if="error" class="k-text-knot-danger k-bg-knot-danger-soft k-px-2 k-py-1 k-rounded-knot-sm">{{ error }}</div>
    <div v-if="flash" class="k-text-knot-success k-bg-knot-success-soft k-px-2 k-py-1 k-rounded-knot-sm">{{ flash }}</div>

    <template v-if="!webhook">
      <p class="k-text-knot-text-muted">{{ t('webhookPanel.none') }}</p>
      <button
        @click="provision"
        :disabled="loading"
        class="k-inline-flex k-items-center k-gap-2 k-px-2.5 k-py-1.5 k-rounded-knot-sm k-bg-knot-primary k-text-white k-text-xs k-font-semibold disabled:k-opacity-60"
      >
        <Loader2 v-if="loading" :size="11" class="k-animate-spin" />
        {{ t('webhookPanel.provision') }}
      </button>
    </template>

    <template v-else>
      <div>
        <div class="k-text-knot-text-soft k-font-semibold k-mb-1">{{ t('webhookPanel.publicUrl') }}</div>
        <div class="k-flex k-items-center k-gap-1 k-bg-knot-bg k-border k-border-knot-border k-rounded-knot-sm k-px-2 k-py-1">
          <code class="k-flex-1 k-truncate k-text-[11px] k-font-mono k-text-knot-text">{{ url }}</code>
          <button v-if="url" @click="copy(url, t('webhookPanel.copyLabelUrl'))" class="k-text-knot-text-soft hover:k-text-knot-primary" :title="t('webhookPanel.copyUrlTitle')">
            <Copy :size="11" />
          </button>
          <a v-if="url" :href="url" target="_blank" rel="noopener" class="k-text-knot-text-soft hover:k-text-knot-primary" :title="t('webhookPanel.openTitle')">
            <ExternalLink :size="11" />
          </a>
        </div>
        <div class="k-text-[10px] k-text-knot-text-soft k-mt-1">
          {{ t('webhookPanel.metaLine', { method: webhook.method, hits: webhook.hitCount, limit: webhook.rateLimitPerMinute }) }}
        </div>
      </div>

      <div v-if="webhook.hasSecret">
        <div class="k-text-knot-text-soft k-font-semibold k-mb-1">{{ t('webhookPanel.secretHmac') }}</div>
        <div class="k-flex k-items-center k-gap-1 k-bg-knot-bg k-border k-border-knot-border k-rounded-knot-sm k-px-2 k-py-1">
          <code class="k-flex-1 k-truncate k-text-[11px] k-font-mono k-text-knot-text">{{ masked }}</code>
          <button @click="showSecret = !showSecret" class="k-text-knot-text-soft hover:k-text-knot-primary k-text-[10px] k-px-1">
            {{ showSecret ? t('webhookPanel.hide') : t('webhookPanel.show') }}
          </button>
          <button @click="copy(webhook.secretHmac, t('webhookPanel.copyLabelSecret'))" class="k-text-knot-text-soft hover:k-text-knot-primary" :title="t('webhookPanel.copySecretTitle')">
            <Copy :size="11" />
          </button>
        </div>
        <p class="k-text-[10px] k-text-knot-text-soft k-mt-1">
          {{ t('webhookPanel.secretHint') }}
        </p>
      </div>

      <div>
        <div class="k-text-knot-text-soft k-font-semibold k-mb-1 k-flex k-items-center k-gap-1">
          <Terminal :size="11" /> {{ t('webhookPanel.curlTitle') }}
        </div>
        <div class="k-relative k-bg-knot-bg k-border k-border-knot-border k-rounded-knot-sm k-px-2 k-py-1.5">
          <pre class="k-text-[10.5px] k-font-mono k-text-knot-text k-whitespace-pre-wrap k-break-all k-leading-snug k-pr-6">{{ curlExample }}</pre>
          <button
            @click="copy(curlExample, t('webhookPanel.copyLabelCurl'))"
            class="k-absolute k-top-1 k-right-1 k-text-knot-text-soft hover:k-text-knot-primary"
            :title="t('webhookPanel.copyCurlTitle')"
          >
            <Copy :size="11" />
          </button>
        </div>
      </div>

      <div class="k-flex k-items-start k-gap-2 k-bg-knot-warning-soft/60 k-border k-border-knot-warning/30 k-rounded-knot-sm k-px-2 k-py-1.5 k-text-[10.5px] k-text-knot-text-muted">
        <AlertTriangle :size="11" class="k-text-knot-warning k-shrink-0 k-mt-0.5" />
        <span>
          <strong class="k-text-knot-warning">{{ t('webhookPanel.securityTitle') }}</strong>
          {{ t('webhookPanel.securityBody') }}
        </span>
      </div>
    </template>
  </div>
</template>
