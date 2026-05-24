<!--
  ExecutionErrorPanel — structured Knot errors (ADR-007) + legacy string fallback.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<template>
  <div :class="panelClass">
    <div class="k-flex k-items-start k-gap-2">
      <OctagonAlert :size="16" class="k-mt-0.5 k-shrink-0" />
      <div class="k-min-w-0 k-flex-1">
        <p class="k-font-semibold">{{ unified.title }}</p>
        <p v-if="unified.hint" class="k-mt-1 k-text-sm k-opacity-90">{{ unified.hint }}</p>
        <button
          v-if="licenseExtensionId"
          type="button"
          class="k-mt-2 k-text-sm k-font-medium k-underline k-underline-offset-2 hover:k-opacity-90"
          @click="openLicenseActivation"
        >
          {{ t('executionError.activateLicense', 'Activate extension license') }}
        </button>
        <p v-if="unified.knotCode" class="k-mt-2 k-font-mono k-text-[11px] k-opacity-80">
          {{ t('executionError.codeLabel', 'Code') }}: {{ unified.knotCode }}
        </p>
        <div v-if="safeHref" class="k-mt-2">
          <a
            :href="safeHref"
            target="_blank"
            rel="noopener noreferrer"
            class="k-text-sm k-font-medium k-underline k-underline-offset-2 hover:k-opacity-90"
          >
            {{ t('executionError.documentation', 'Documentation') }}
          </a>
        </div>
        <details class="k-mt-2">
          <summary
            class="k-cursor-pointer k-text-xs k-opacity-80 hover:k-opacity-100"
          >
            {{ t('executionError.showTechnical', 'Voir le détail technique') }}
          </summary>
          <pre
            class="k-mt-2 k-max-h-48 k-overflow-auto k-rounded k-bg-white/50 k-p-2 k-text-[11px] dark:k-bg-black/20"
          >{{ unified.technical }}</pre>
        </details>
      </div>
    </div>
  </div>
</template>

<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { OctagonAlert } from 'lucide-vue-next';
import {
  translateExecutionError,
  safeExecutionDocHref,
  extractExtensionIdFromLicenseError,
  type KnotErrorPayload,
} from './ExecutionErrorTranslator';

const props = defineProps<{
  /** Legacy plain string or Knot payload object */
  payload: string | KnotErrorPayload | Record<string, unknown> | null | undefined;
  /** Optional extension id when the caller already knows it (Pro Pack nodes). */
  extensionId?: string | null;
}>();

const { t } = useI18n();

const unified = computed(() => translateExecutionError(props.payload));

const safeHref = computed(() => safeExecutionDocHref(unified.value.docLink ?? null));

const licenseExtensionId = computed(() => {
  if (unified.value.bucket !== 'license') {
    return null;
  }
  if (props.extensionId && props.extensionId.trim() !== '') {
    return props.extensionId.trim();
  }
  return extractExtensionIdFromLicenseError(unified.value.technical);
});

function openLicenseActivation(): void {
  const extId = licenseExtensionId.value;
  if (!extId) {
    return;
  }
  const knotCore = (window as { KnotCore?: { openLicenseActivationModal?: (id: string) => void } }).KnotCore;
  knotCore?.openLicenseActivationModal?.(extId);
}

const panelClass = computed(() => {
  const sev = unified.value.severity ?? 'error';
  const base =
    'k-rounded-md k-border k-p-3 k-text-sm';
  if (sev === 'warning') {
    return `${base} k-border-knot-warning/40 k-bg-knot-warning-soft k-text-knot-warning`;
  }
  if (sev === 'info') {
    return `${base} k-border-knot-primary/30 k-bg-knot-primary-soft k-text-knot-primary`;
  }
  return `${base} k-border-knot-danger/40 k-bg-knot-danger-soft k-text-knot-danger`;
});
</script>
