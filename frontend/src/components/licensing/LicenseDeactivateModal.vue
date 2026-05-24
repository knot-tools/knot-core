<!--
  License deactivation modal — releases instance binding on license server.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { ref, computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { KeyRound, Loader2, CheckCircle2, XCircle, X } from 'lucide-vue-next';
import { knotApi, type LicenseDeactivationResponse } from '../../lib/api';
import { KNOT_Z_DIALOG } from '../../lib/overlayStacking';

const props = defineProps<{
  open: boolean;
  extensionId: string;
  extensionLabel: string;
}>();

const emit = defineEmits<{
  (e: 'close'): void;
  (e: 'deactivated', payload: LicenseDeactivationResponse): void;
}>();

const { t } = useI18n();

const activationCode = ref('');
const submitting = ref(false);
const result = ref<LicenseDeactivationResponse | null>(null);
const error = ref<string | null>(null);

const codeLooksValid = computed(() =>
  /^[A-Z0-9-]{12,80}$/.test(activationCode.value.trim()),
);

function close() {
  if (submitting.value) {
    return;
  }
  activationCode.value = '';
  result.value = null;
  error.value = null;
  emit('close');
}

async function submit() {
  if (!codeLooksValid.value || submitting.value) {
    return;
  }
  submitting.value = true;
  error.value = null;
  result.value = null;
  try {
    const res = await knotApi.licenseDeactivate(props.extensionId, activationCode.value.trim());
    result.value = res;
    if (res.deactivated) {
      emit('deactivated', res);
    } else {
      const backendErr = res.backend && typeof res.backend === 'object' ? res.backend as Record<string, unknown> : null;
      error.value = backendErr
        ? String(backendErr.message ?? backendErr.error ?? t('licenseDeactivateModal.rejectBackend'))
        : t('licenseDeactivateModal.rejectGeneric');
    }
  } catch (err) {
    error.value = err instanceof Error ? err.message : t('licenseDeactivateModal.requestFailed');
  } finally {
    submitting.value = false;
  }
}
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="k-fixed k-inset-0 k-bg-black/50 k-flex k-items-center k-justify-center k-px-4"
      :style="{ zIndex: KNOT_Z_DIALOG }"
      role="dialog"
      aria-modal="true"
      :aria-label="extensionLabel"
      @click.self="close"
    >
      <div class="k-bg-knot-surface k-rounded-knot-lg k-shadow-knot-lg k-w-full k-max-w-md k-p-6 k-border k-border-knot-border">
        <div class="k-flex k-items-start k-justify-between k-gap-3 k-mb-4">
          <div class="k-flex k-items-center k-gap-2">
            <KeyRound :size="20" class="k-text-knot-warning" />
            <h2 class="k-text-lg k-font-bold k-text-knot-text">{{ extensionLabel }}</h2>
          </div>
          <button type="button" class="k-text-knot-text-soft hover:k-text-knot-text" @click="close">
            <X :size="18" />
          </button>
        </div>

        <p class="k-text-sm k-text-knot-text-muted k-mb-4">
          {{ t('licenseDeactivateModal.hint') }}
        </p>

        <label class="k-block k-text-xs k-font-semibold k-text-knot-text-soft k-mb-1" for="deact-code">
          {{ t('licenseDeactivateModal.codeLabel') }}
        </label>
        <input
          id="deact-code"
          v-model="activationCode"
          type="text"
          autocomplete="off"
          spellcheck="false"
          class="k-w-full k-px-3 k-py-2 k-rounded-knot-sm k-border k-border-knot-border k-bg-knot-surface k-font-mono k-text-sm"
          :placeholder="t('licenseDeactivateModal.codePlaceholder')"
          @keyup.enter="submit"
        />

        <div v-if="error" class="k-mt-3 k-text-sm k-text-knot-danger k-flex k-items-start k-gap-2">
          <XCircle :size="16" class="k-shrink-0 k-mt-0.5" /> {{ error }}
        </div>
        <div v-if="result?.deactivated" class="k-mt-3 k-text-sm k-text-knot-success k-flex k-items-start k-gap-2">
          <CheckCircle2 :size="16" class="k-shrink-0 k-mt-0.5" /> {{ t('licenseDeactivateModal.success') }}
        </div>

        <div class="k-mt-6 k-flex k-justify-end k-gap-2">
          <button
            type="button"
            class="k-px-4 k-py-2 k-text-sm k-rounded-knot-sm k-border k-border-knot-border"
            :disabled="submitting"
            @click="close"
          >
            {{ t('actions.cancel') }}
          </button>
          <button
            type="button"
            class="k-px-4 k-py-2 k-text-sm k-font-semibold k-rounded-knot-sm k-bg-knot-warning k-text-white disabled:k-opacity-50"
            :disabled="!codeLooksValid || submitting"
            @click="submit"
          >
            <Loader2 v-if="submitting" :size="14" class="k-inline k-animate-spin k-mr-1" />
            {{ t('licenseDeactivateModal.submit') }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
