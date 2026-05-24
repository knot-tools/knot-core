<!-- Copyright (C) 2026 Knot — GPL-3.0-or-later -->
<script setup lang="ts">
import WebhookPanel from '../../../canvas/WebhookPanel.vue';
defineProps<{ workflowId: number | null }>();
</script>

<template>
  <div class="k-space-y-3">
    <div v-if="!workflowId" class="k-text-xs k-text-knot-text-muted">Save the workflow once to provision a webhook URL.</div>
    <WebhookPanel v-else :workflow-id="workflowId" />
  </div>
</template>
