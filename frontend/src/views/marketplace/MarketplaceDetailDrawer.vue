<!--
  Slide-in preview drawer (420px) for curated/related marketplace cards.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, inject, ref, watch } from 'vue';
import { ExternalLink, X } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

import { trackMarketplaceEvent } from '../../lib/marketplaceTrack';

import { knotMarketplaceBlockContextKey } from './contextKey';
import type { MarketplaceDrawerTarget } from './types';

const props = defineProps<{
  open: boolean;
  target: MarketplaceDrawerTarget | null;
}>();

const emit = defineEmits<{
  close: [];
}>();

const { t } = useI18n();
const ctx = inject(knotMarketplaceBlockContextKey);
const dialogRef = ref<HTMLDialogElement | null>(null);

const pack = computed(() => {
  if (!props.target || props.target.kind !== 'product') {
    return null;
  }
  return ctx?.marketplace.value?.packs.find((p) => p.slug === props.target!.slug) ?? null;
});

const template = computed(() => {
  if (!props.target || props.target.kind !== 'template') {
    return null;
  }
  return ctx?.marketplace.value?.templates.find((row) => row.slug === props.target!.slug) ?? null;
});

const heading = computed(() => pack.value?.label ?? template.value?.label ?? props.target?.slug ?? '');

const body = computed(() => pack.value?.description ?? template.value?.description ?? '');

watch(
  () => props.open,
  (isOpen) => {
    const dlg = dialogRef.value;
    if (!dlg) {
      return;
    }
    if (isOpen && !dlg.open) {
      dlg.showModal();
      if (props.target) {
        trackMarketplaceEvent('drawer.open', {
          kind: props.target.kind,
          slug: props.target.slug,
        });
      }
    } else if (!isOpen && dlg.open) {
      dlg.close();
    }
  },
);

function onClose(): void {
  emit('close');
}

function openFullPage(): void {
  if (!props.target || !ctx) {
    return;
  }
  onClose();
  ctx.navigate(
    props.target.kind === 'template'
      ? `/template/${props.target.slug}`
      : `/product/${props.target.slug}`,
  );
}

function onDialogCancel(event: Event): void {
  event.preventDefault();
  onClose();
}
</script>

<template>
  <dialog
    ref="dialogRef"
    class="km-drawer k-fixed k-inset-y-0 k-right-0 k-left-auto k-m-0 k-h-full k-max-h-full k-w-[min(100vw,420px)] k-border-0 k-p-0 k-bg-transparent"
    :aria-label="t('marketplace.drawerAria')"
    @close="onClose"
    @cancel="onDialogCancel"
  >
    <div class="km-drawer__panel k-h-full k-flex k-flex-col k-bg-knot-surface k-border-l k-border-knot-border k-shadow-knot-premium">
      <header class="k-flex k-items-start k-justify-between k-gap-3 k-p-4 k-border-b k-border-knot-border">
        <div class="k-min-w-0">
          <p class="k-text-[11px] k-font-bold k-uppercase k-tracking-wide k-text-knot-text-soft">
            {{ target?.kind === 'template' ? t('marketplace.tabTemplates') : t('marketplace.tabPacks') }}
          </p>
          <h2 class="k-text-lg k-font-bold k-text-knot-text k-truncate">{{ heading }}</h2>
        </div>
        <button
          type="button"
          class="k-p-2 k-rounded-knot-sm hover:k-bg-knot-surface-soft"
          :aria-label="t('marketplace.drawerClose')"
          @click="onClose"
        >
          <X :size="18" />
        </button>
      </header>

      <div class="k-flex-1 k-overflow-y-auto k-p-4 k-space-y-4">
        <p class="k-text-sm k-text-knot-text-muted">{{ body }}</p>
        <dl v-if="pack" class="k-grid k-grid-cols-2 k-gap-2 k-text-xs">
          <div>
            <dt class="k-text-knot-text-soft">{{ t('marketplace.drawerTier') }}</dt>
            <dd class="k-font-semibold">{{ pack.tier }}</dd>
          </div>
          <div v-if="pack.version">
            <dt class="k-text-knot-text-soft">{{ t('marketplace.drawerVersion') }}</dt>
            <dd class="k-font-semibold">{{ pack.version }}</dd>
          </div>
        </dl>
        <dl v-else-if="template" class="k-grid k-grid-cols-2 k-gap-2 k-text-xs">
          <div>
            <dt class="k-text-knot-text-soft">{{ t('marketplace.allCategories') }}</dt>
            <dd class="k-font-semibold">{{ template.category }}</dd>
          </div>
          <div>
            <dt class="k-text-knot-text-soft">{{ t('marketplace.drawerTier') }}</dt>
            <dd class="k-font-semibold">{{ template.tier }}</dd>
          </div>
        </dl>
      </div>

      <footer class="k-p-4 k-border-t k-border-knot-border">
        <button
          type="button"
          class="k-w-full k-inline-flex k-items-center k-justify-center k-gap-2 k-px-4 k-py-2 k-rounded-knot-sm k-bg-knot-primary k-text-white k-text-sm k-font-semibold hover:k-opacity-95"
          @click="openFullPage"
        >
          {{ t('marketplace.drawerOpenFull') }}
          <ExternalLink :size="14" />
        </button>
      </footer>
    </div>
  </dialog>
</template>

<style scoped>
.km-drawer::backdrop {
  background: rgb(15 23 42 / 0.35);
}

@media (prefers-reduced-motion: no-preference) {
  .km-drawer__panel {
    animation: km-drawer-in 220ms ease-out;
  }
}

@keyframes km-drawer-in {
  from {
    transform: translateX(100%);
  }
  to {
    transform: translateX(0);
  }
}

@media (prefers-reduced-motion: reduce) {
  .km-drawer__panel {
    animation: none;
  }
}
</style>
