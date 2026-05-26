<!--
  Accessible FAQ accordion (keyboard: Enter/Space toggles, roving focus optional — single buttons).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, ref } from 'vue';
import { useId } from 'vue';
import { HelpCircle } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

export interface FaqItem {
  id?: string;
  question?: string;
  answer?: string;
}

const props = withDefaults(
  defineProps<{
    title?: string;
    items?: FaqItem[];
    ariaLabel?: string;
  }>(),
  { items: () => [] },
);

const { t } = useI18n();
const headingId = useId();
const heading = computed(() => props.title ?? t('marketplace.faqTitle'));
const list = computed(() => (Array.isArray(props.items) ? props.items : []));

const openId = ref<string | null>(null);

function toggle(id: string): void {
  openId.value = openId.value === id ? null : id;
}

function itemId(it: FaqItem, idx: number): string {
  return it.id ?? `faq-${idx}`;
}

function onFaqKeydown(event: KeyboardEvent, idx: number): void {
  const buttons = (event.currentTarget as HTMLElement | null)
    ?.closest('[data-faq-list]')
    ?.querySelectorAll<HTMLButtonElement>('button[type="button"]');
  if (!buttons?.length) {
    return;
  }
  let next = idx;
  if (event.key === 'ArrowDown') {
    next = Math.min(idx + 1, buttons.length - 1);
  } else if (event.key === 'ArrowUp') {
    next = Math.max(idx - 1, 0);
  } else if (event.key === 'Home') {
    next = 0;
  } else if (event.key === 'End') {
    next = buttons.length - 1;
  } else {
    return;
  }
  event.preventDefault();
  buttons[next]?.focus();
}
</script>

<template>
  <section
    class="k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface k-p-5 k-shadow-knot-sm"
    role="region"
    :aria-label="props.ariaLabel ?? heading"
    :aria-labelledby="props.ariaLabel ? undefined : headingId"
  >
    <h2 :id="headingId" class="k-mb-4 k-flex k-items-center k-gap-2 k-text-sm k-font-bold k-text-knot-text">
      <HelpCircle :size="16" class="k-text-knot-primary" aria-hidden="true" />
      {{ heading }}
    </h2>

    <p v-if="list.length === 0" class="k-text-xs k-text-knot-text-soft">{{ t('marketplace.faqEmpty') }}</p>

    <div v-else class="k-space-y-2" data-faq-list>
      <div
        v-for="(it, idx) in list"
        :key="itemId(it, idx)"
        class="k-rounded-knot-sm k-border k-border-knot-border k-overflow-hidden"
      >
        <h3 class="k-m-0">
          <button
            type="button"
            class="k-w-full k-flex k-items-center k-justify-between k-gap-2 k-px-3 k-py-2.5 k-text-left k-text-xs k-font-semibold k-text-knot-text k-bg-knot-surface-soft hover:k-bg-knot-surface focus-visible:k-outline focus-visible:k-outline-2 focus-visible:k-outline-offset-2 focus-visible:k-outline-knot-primary"
            :aria-expanded="openId === itemId(it, idx)"
            :aria-controls="`panel-${itemId(it, idx)}`"
            :id="`btn-${itemId(it, idx)}`"
            @click="toggle(itemId(it, idx))"
            @keydown.enter.prevent="toggle(itemId(it, idx))"
            @keydown.space.prevent="toggle(itemId(it, idx))"
            @keydown="onFaqKeydown($event, idx)"
          >
            {{ it.question ?? t('marketplace.editorialUntitled') }}
            <span class="k-text-knot-text-muted" aria-hidden="true">{{ openId === itemId(it, idx) ? '−' : '+' }}</span>
          </button>
        </h3>
        <div
          v-show="openId === itemId(it, idx)"
          :id="`panel-${itemId(it, idx)}`"
          role="region"
          :aria-labelledby="`btn-${itemId(it, idx)}`"
          class="k-px-3 k-pb-3 k-pt-1 k-text-[11px] k-text-knot-text-muted"
        >
          {{ it.answer ?? '' }}
        </div>
      </div>
    </div>
  </section>
</template>
