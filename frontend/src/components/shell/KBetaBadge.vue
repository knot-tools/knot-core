<!--
  Discreet beta badge — visible when releaseChannel is beta.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { knotApi } from '../../lib/api';

const { t } = useI18n();
const releaseChannel = ref<string>('beta');
const version = ref<string>('');

onMounted(async () => {
  try {
    const health = await knotApi.health();
    releaseChannel.value = health.releaseChannel ?? 'beta';
    version.value = health.version ?? '';
  } catch {
    releaseChannel.value = 'beta';
  }
});

const showBadge = computed(() => releaseChannel.value === 'beta');

const feedbackHref = computed(
  () => 'mailto:contact@knot.tools?subject=' + encodeURIComponent('Knot Core beta feedback'),
);
</script>

<template>
  <footer
    v-if="showBadge"
    class="k-shrink-0 k-border-t k-border-knot-border k-bg-knot-surface-soft k-px-4 k-py-2 k-flex k-flex-wrap k-items-center k-justify-center k-gap-x-2 k-gap-y-1 k-text-xs k-text-knot-text-muted"
    data-testid="knot-beta-badge"
  >
    <span class="k-font-medium k-text-knot-text">
      {{ t('shell.betaBadge', { version: version || '…' }) }}
    </span>
    <a
      :href="feedbackHref"
      class="k-text-knot-primary hover:k-underline"
      :aria-label="t('shell.betaFeedbackAria')"
    >
      {{ t('shell.betaFeedback') }}
    </a>
  </footer>
</template>
