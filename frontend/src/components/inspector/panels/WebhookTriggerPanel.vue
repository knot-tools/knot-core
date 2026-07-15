<!-- Copyright (C) 2026 Knot — GPL-3.0-or-later -->
<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import WebhookPanel from '../../../canvas/WebhookPanel.vue';

defineProps<{ workflowId: number | null }>();

const { t } = useI18n();
</script>

<template>
  <div class="k-space-y-3">
    <div v-if="!workflowId" class="k-text-xs k-text-knot-text-muted">
      {{ t('webhookPanel.saveFirst') }}
    </div>
    <WebhookPanel v-else :workflow-id="workflowId" />
  </div>
</template>
