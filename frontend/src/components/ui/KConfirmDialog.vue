<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useConfirm } from '../../composables/useConfirm';
import { KNOT_Z_DIALOG } from '../../lib/overlayStacking';

const { t } = useI18n();
const confirmApi = useConfirm();

const open = computed(() => confirmApi.state.value.open);
const opts = computed(() => confirmApi.state.value.options);

const overlayStyle = { zIndex: KNOT_Z_DIALOG };

const confirmClass = computed(() =>
  opts.value.danger
    ? 'k-bg-knot-danger k-text-white hover:k-opacity-90'
    : 'k-bg-knot-primary k-text-white hover:k-opacity-90',
);
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      data-knot-test="knot-confirm-dialog"
      class="k-fixed k-inset-0 k-flex k-items-center k-justify-center k-bg-black/50"
      :style="overlayStyle"
      role="dialog"
      aria-modal="true"
    >
      <div class="k-w-full k-max-w-md k-rounded-knot-lg k-bg-knot-surface k-border k-border-knot-border k-p-5 k-shadow-knot-lg">
        <h2 class="k-text-base k-font-semibold k-text-knot-text">{{ opts.title }}</h2>
        <p v-if="opts.message" class="k-mt-2 k-text-sm k-text-knot-text-muted">{{ opts.message }}</p>
        <div class="k-mt-5 k-flex k-justify-end k-gap-2">
          <button
            type="button"
            class="k-btn k-btn--ghost k-text-sm"
            @click="confirmApi.answer(false)"
          >
            {{ opts.cancelLabel ?? t('actions.cancel') }}
          </button>
          <button
            type="button"
            class="k-btn k-text-sm k-font-semibold"
            :class="confirmClass"
            @click="confirmApi.answer(true)"
          >
            {{ opts.confirmLabel ?? t('actions.confirm') }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
