<!--
  License activation modal — V2.5.0b Marketplace UI.

  Opened from ConnectorsView ("Activate" button on a Pro/Enterprise
  extension) and from the migration assistant. The user pastes the
  activation_code received by email after the Stripe checkout, and
  Knot Core forwards it to license.knot.tools/api/license/activate
  with the locally-computed instance fingerprint. On success the
  signed verdict is cached so the next /api/connectors.php call
  shows the extension as `loaded`.

  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { ref, computed } from 'vue';
import { KeyRound, Loader2, CheckCircle2, XCircle, X, AlertTriangle } from 'lucide-vue-next';
import { knotApi, type LicenseActivationResponse } from '../../lib/api';
import { KNOT_Z_DIALOG } from '../../lib/overlayStacking';

const props = defineProps<{
  open: boolean;
  extensionId: string;
  extensionLabel: string;
}>();

const emit = defineEmits<{
  (e: 'close'): void;
  (e: 'activated', payload: LicenseActivationResponse): void;
}>();

const activationCode = ref('');
const submitting = ref(false);
const result = ref<LicenseActivationResponse | null>(null);
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
    const res = await knotApi.licenseActivate(props.extensionId, activationCode.value.trim());
    result.value = res;
    if (res.activated) {
      emit('activated', res);
    } else {
      const backendErr = res.backend && typeof res.backend === 'object' ? res.backend as Record<string, unknown> : null;
      error.value = backendErr
        ? String(backendErr.message ?? backendErr.error ?? 'Activation rejected by license backend.')
        : 'Activation rejected by license backend.';
    }
  } catch (err) {
    error.value = err instanceof Error ? err.message : 'Activation request failed.';
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
      @click.self="close"
    >
      <div
        class="k-bg-knot-surface k-text-knot-text k-rounded-knot-md k-shadow-knot-lg k-w-full k-max-w-lg k-p-6 k-space-y-5"
        role="dialog"
        aria-modal="true"
      >
        <header class="k-flex k-items-start k-justify-between k-gap-3">
          <div>
            <h2 class="k-text-lg k-font-bold k-text-knot-text k-flex k-items-center k-gap-2">
              <KeyRound :size="18" class="k-text-knot-primary" />
              Activate {{ extensionLabel }}
            </h2>
            <p class="k-text-xs k-text-knot-text-muted k-mt-1">
              Paste the activation code received by email after the Stripe checkout.
              The code is bound to this Dolibarr instance fingerprint.
            </p>
          </div>
          <button
            type="button"
            class="k-text-knot-text-soft hover:k-text-knot-text k-p-1"
            @click="close"
            :disabled="submitting"
          >
            <X :size="18" />
          </button>
        </header>

        <form @submit.prevent="submit" class="k-space-y-4">
          <div>
            <label class="k-block k-text-xs k-font-semibold k-text-knot-text-muted k-uppercase k-tracking-wider k-mb-1">
              Activation code
            </label>
            <input
              v-model="activationCode"
              type="text"
              autocomplete="off"
              spellcheck="false"
              class="k-w-full k-px-3 k-py-2.5 k-text-sm k-font-mono k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border focus:k-outline-none focus:k-border-knot-primary k-text-knot-text"
              placeholder="KNOTPRO-XXXX-XXXX-XXXX-XXXX"
              :disabled="submitting"
            />
            <p
              v-if="activationCode && !codeLooksValid"
              class="k-text-xs k-text-knot-warning k-mt-1"
            >
              The activation code looks malformed. Expected ≥12 chars, only A–Z, 0–9 and dashes.
            </p>
          </div>

          <div
            v-if="error"
            class="k-bg-knot-danger-soft k-text-knot-danger k-px-3 k-py-2 k-rounded-knot-sm k-text-sm k-flex k-items-start k-gap-2"
          >
            <XCircle :size="14" class="k-mt-0.5 k-shrink-0" />
            <div>{{ error }}</div>
          </div>

          <div
            v-if="result?.activated && result.cacheWriteError"
            class="k-bg-knot-warning-soft k-border k-border-knot-warning/40 k-text-knot-warning k-px-3 k-py-2 k-rounded-knot-sm k-text-sm k-flex k-items-start k-gap-2"
            role="alert"
          >
            <AlertTriangle :size="14" class="k-mt-0.5 k-shrink-0" />
            <div>
              Local license cache could not be written: {{ result.cacheWriteError }}.
              Activation is saved on license.knot.tools, but Knot Core cannot store the
              signed verdict locally — check filesystem permissions on
              <code>documents/knot/licenses/</code> and activate again if the extension
              menu stays hidden.
            </div>
          </div>

          <div
            v-if="result?.activated"
            class="k-bg-knot-success-soft k-text-knot-success k-px-3 k-py-2 k-rounded-knot-sm k-text-sm"
          >
            <div class="k-flex k-items-center k-gap-2 k-font-semibold">
              <CheckCircle2 :size="14" /> Extension activated.
            </div>
          </div>

          <div class="k-flex k-items-center k-justify-end k-gap-2 k-pt-2">
            <button
              type="button"
              class="k-px-4 k-py-2 k-text-sm k-text-knot-text-muted hover:k-text-knot-text"
              @click="close"
              :disabled="submitting"
            >
              {{ result?.activated ? 'Close' : 'Cancel' }}
            </button>
            <button
              v-if="!result?.activated"
              type="submit"
              class="k-px-5 k-py-2 k-text-sm k-font-semibold k-text-white k-bg-knot-primary hover:k-bg-knot-primary-strong k-rounded-knot-sm k-flex k-items-center k-gap-2 disabled:k-opacity-60"
              :disabled="!codeLooksValid || submitting"
            >
              <Loader2 v-if="submitting" :size="14" class="k-animate-spin" />
              <KeyRound v-else :size="14" />
              {{ submitting ? 'Activating…' : 'Activate' }}
            </button>
          </div>
        </form>

        <footer class="k-text-[11px] k-text-knot-text-soft k-border-t k-border-knot-border k-pt-3">
          Knot Core never stores your activation code: it is forwarded once to
          <code>license.knot.tools</code>, then the signed verdict is cached locally and
          revalidated periodically.
        </footer>
      </div>
    </div>
  </Teleport>
</template>
