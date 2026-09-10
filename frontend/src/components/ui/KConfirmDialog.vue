<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useConfirm } from '../../composables/useConfirm';
import { parseMarkdownLight } from '../../lib/markdownLight';
import { KNOT_Z_DIALOG } from '../../lib/overlayStacking';

const { t } = useI18n();
const confirmApi = useConfirm();

const open = computed(() => confirmApi.state.value.open);
const opts = computed(() => confirmApi.state.value.options);

const overlayStyle = { zIndex: KNOT_Z_DIALOG };

const detailsHtml = computed(() => {
  const raw = opts.value.details?.trim() ?? '';
  return raw === '' ? '' : parseMarkdownLight(raw);
});

const panelClass = computed(() =>
  detailsHtml.value
    ? 'k-w-full k-max-w-lg k-rounded-knot-lg k-bg-knot-surface k-border k-border-knot-border k-p-5 k-shadow-knot-lg'
    : 'k-w-full k-max-w-md k-rounded-knot-lg k-bg-knot-surface k-border k-border-knot-border k-p-5 k-shadow-knot-lg',
);

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
      <div :class="panelClass">
        <h2 class="k-text-base k-font-semibold k-text-knot-text">{{ opts.title }}</h2>
        <p v-if="opts.message" class="k-mt-2 k-text-sm k-text-knot-text-muted">{{ opts.message }}</p>
        <div
          v-if="detailsHtml"
          class="k-mt-3"
          data-knot-test="knot-confirm-details"
        >
          <p
            v-if="opts.detailsLabel"
            class="k-text-xs k-font-semibold k-uppercase k-tracking-wider k-text-knot-text-muted"
          >
            {{ opts.detailsLabel }}
          </p>
          <div
            class="k-mt-1 k-max-h-48 k-overflow-y-auto k-rounded-md k-border k-border-knot-border k-bg-knot-surface-soft k-p-3 k-text-sm k-text-knot-text"
            v-html="detailsHtml"
          />
        </div>
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
            data-knot-test="knot-confirm-accept"
            @click="confirmApi.answer(true)"
          >
            {{ opts.confirmLabel ?? t('actions.confirm') }}
          </button>
        </div>
      </div>
    </div>
  </Teleport>
</template>
