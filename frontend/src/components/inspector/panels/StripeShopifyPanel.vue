<!-- Copyright (C) 2026 Knot — GPL-3.0-or-later -->
<script setup lang="ts">
import { computed } from 'vue';
import { CreditCard } from 'lucide-vue-next';

const props = defineProps<{ modelValue: Record<string, unknown>; nodeType: string }>();
const emit = defineEmits<{ (e: 'update:modelValue', v: Record<string, unknown>): void }>();

const ACTIONS_STRIPE = ['create_customer', 'create_payment_intent', 'refund', 'fetch_invoice', 'list_charges'];
const ACTIONS_SHOPIFY = ['create_order', 'fetch_order', 'list_products', 'update_inventory'];
const actions = computed(() => (props.nodeType.includes('stripe') ? ACTIONS_STRIPE : ACTIONS_SHOPIFY));

const action = computed({
  get: () => String(props.modelValue?.action ?? ''),
  set: (v) => emit('update:modelValue', { ...props.modelValue, action: v }),
});
const credentialRef = computed({
  get: () => String(props.modelValue?.credentialRef ?? ''),
  set: (v) => emit('update:modelValue', { ...props.modelValue, credentialRef: v }),
});
const params = computed({
  get: () => props.modelValue?.params ?? {},
  set: (v) => emit('update:modelValue', { ...props.modelValue, params: v }),
});
</script>

<template>
  <div class="k-space-y-3">
    <div class="k-flex k-items-center k-gap-2 k-text-knot-primary">
      <CreditCard :size="14" /><span class="k-text-xs k-font-bold k-uppercase k-tracking-wider">{{ nodeType }}</span>
    </div>
    <div class="k-space-y-1">
      <label class="k-text-[11px] k-text-knot-text-soft k-font-bold">Action</label>
      <select v-model="action" class="k-w-full k-px-2 k-py-1.5 k-text-sm k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text">
        <option value="">— select —</option>
        <option v-for="a in actions" :key="a" :value="a">{{ a }}</option>
      </select>
    </div>
    <div class="k-space-y-1">
      <label class="k-text-[11px] k-text-knot-text-soft k-font-bold">Credential reference</label>
      <input v-model="credentialRef" :placeholder="'credential:' + (nodeType.includes('stripe') ? 'stripe' : 'shopify') + ':label'" class="k-w-full k-px-2 k-py-1.5 k-text-sm k-font-mono k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text" />
    </div>
    <div class="k-space-y-1">
      <label class="k-text-[11px] k-text-knot-text-soft k-font-bold">Parameters (JSON)</label>
      <textarea
        :value="JSON.stringify(params, null, 2)"
        rows="5"
        @input="(e) => { try { params = JSON.parse((e.target as HTMLTextAreaElement).value || '{}'); } catch {} }"
        class="k-w-full k-px-2 k-py-1.5 k-text-xs k-font-mono k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text"
      ></textarea>
    </div>
  </div>
</template>
