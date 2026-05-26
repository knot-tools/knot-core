<!--
  Error / disabled envelope for Marketplace (API or operator gate).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { AlertTriangle, Ban, ExternalLink, RefreshCw } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

defineEmits<{
  retry: [];
}>();

const props = defineProps<{
  message: string;
  code?: string | null;
  variant?: 'error' | 'warning';
  killSwitch?: boolean;
}>();

const { t } = useI18n();

const pricingExternalHref = 'https://knot.tools/pricing/';

function isBlockedUi(): boolean {
  return props.code === 'marketplace_ui_disabled' || props.killSwitch === true;
}
</script>

<template>
  <div class="k-max-w-[960px] k-mx-auto k-p-6">
    <div
      role="alert"
      :class="[
        'k-rounded-knot-md k-border k-p-5 k-space-y-2',
        variant === 'warning' || isBlockedUi()
          ? 'k-border-knot-warning k-bg-knot-warning-soft k-text-knot-warning'
          : 'k-border-knot-danger k-bg-knot-danger-soft k-text-knot-danger',
      ]"
    >
      <div class="k-flex k-items-center k-gap-2 k-font-semibold">
        <Ban v-if="isBlockedUi()" :size="18" />
        <AlertTriangle v-else :size="18" />
        {{ isBlockedUi() ? (killSwitch ? t('marketplace.killSwitchTitle') : t('marketplace.blockedTitle')) : t('marketplace.unavailableTitle') }}
      </div>
      <p class="k-text-sm k-whitespace-pre-wrap">
        {{ message }}
      </p>
      <div
        v-if="!isBlockedUi()"
        class="k-flex k-flex-wrap k-gap-2 k-pt-2"
      >
        <button
          type="button"
          class="k-inline-flex k-items-center k-gap-2 k-px-3 k-py-2 k-text-xs k-font-semibold k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border hover:k-bg-knot-surface-soft focus-visible:k-outline focus-visible:k-outline-2 focus-visible:k-outline-offset-2"
          @click="$emit('retry')"
        >
          <RefreshCw :size="14" aria-hidden="true" />
          {{ t('marketplace.unavailableRetry') }}
        </button>
        <a
          :href="pricingExternalHref"
          target="_blank"
          rel="noopener noreferrer"
          class="k-inline-flex k-items-center k-gap-2 k-px-3 k-py-2 k-text-xs k-font-semibold k-rounded-knot-sm k-text-knot-primary k-border k-border-knot-primary/40 hover:k-bg-knot-primary-soft focus-visible:k-outline focus-visible:k-outline-2 focus-visible:k-outline-offset-2"
        >
          {{ t('marketplace.unavailableExternal') }}
          <ExternalLink :size="14" aria-hidden="true" />
        </a>
      </div>
    </div>
  </div>
</template>
