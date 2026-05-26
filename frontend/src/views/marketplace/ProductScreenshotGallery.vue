<!--
  Responsive screenshot grid with native dialog lightbox.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { X } from 'lucide-vue-next';

export interface ScreenshotItem {
  src: string;
  alt?: string;
}

const props = withDefaults(
  defineProps<{
    items?: ScreenshotItem[];
    ariaLabel?: string;
  }>(),
  { items: () => [] },
);

const { t } = useI18n();

const dialogRef = ref<HTMLDialogElement | null>(null);
const activeIndex = ref(0);

function openAt(index: number): void {
  activeIndex.value = index;
  dialogRef.value?.showModal();
}

function closeDialog(): void {
  dialogRef.value?.close();
}

function onDialogClick(event: MouseEvent): void {
  if (event.target === dialogRef.value) {
    closeDialog();
  }
}
</script>

<template>
  <section class="k-space-y-3" role="region" :aria-label="ariaLabel ?? t('marketplace.galleryTitle')">
    <h2 class="k-text-sm k-font-bold k-text-knot-text">{{ t('marketplace.galleryTitle') }}</h2>
    <p v-if="!(props.items?.length)" class="k-text-xs k-text-knot-text-muted">{{ t('marketplace.galleryEmpty') }}</p>
    <div v-else class="k-grid k-gap-3 sm:k-grid-cols-2 lg:k-grid-cols-3">
      <button
        v-for="(shot, idx) in props.items"
        :key="`${shot.src}-${idx}`"
        type="button"
        class="k-group k-rounded-knot-sm k-border k-border-knot-border k-overflow-hidden k-bg-knot-surface-soft hover:k-border-knot-primary k-transition-colors"
        @click="openAt(idx)"
      >
        <img
          :src="shot.src"
          :alt="shot.alt ?? t('marketplace.galleryImageFallbackAlt')"
          class="k-w-full k-h-32 k-object-cover"
          :loading="idx === 0 ? 'eager' : 'lazy'"
          decoding="async"
        />
      </button>
    </div>

    <dialog
      ref="dialogRef"
      class="km-screenshot-dialog k-backdrop:bg-black/60 k-border-0 k-p-0 k-bg-transparent k-max-w-[min(96vw,960px)]"
      @click="onDialogClick"
    >
      <div class="k-relative k-rounded-knot-md k-overflow-hidden k-bg-knot-surface k-border k-border-knot-border">
        <button
          type="button"
          class="k-absolute k-top-2 k-right-2 k-z-10 k-p-2 k-rounded-knot-sm k-bg-knot-surface/90 k-text-knot-text hover:k-bg-knot-surface-soft"
          :aria-label="t('marketplace.galleryClose')"
          @click="closeDialog"
        >
          <X :size="18" />
        </button>
        <img
          v-if="props.items?.[activeIndex]"
          :src="props.items[activeIndex]!.src"
          :alt="props.items[activeIndex]!.alt ?? t('marketplace.galleryImageFallbackAlt')"
          class="k-w-full k-max-h-[80vh] k-object-contain"
        />
      </div>
    </dialog>
  </section>
</template>

<style scoped>
.km-screenshot-dialog::backdrop {
  background: rgb(0 0 0 / 0.55);
}
</style>
