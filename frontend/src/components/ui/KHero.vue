<!--
  KHero — gradient hero band aligned with knot.tools landing.
  Copyright (C) 2026 Knot — GPL-3.0-or-later

  Plan post-Phase 5 chantier 8.B (design alignment with the
  public knot.tools landing page). Reusable hero band: mesh
  gradient + grain + bold headline with gradient text-clip on
  the highlighted word + optional emoji pill + CTA slot. Lives
  inside the page flow, not full-bleed, so the existing left
  navigation isn't disturbed.
-->
<script setup lang="ts">
import { computed } from 'vue';

const props = withDefaults(defineProps<{
  variant?: 'large' | 'compact';
  pill?: string | null;
  pillEmoji?: string | null;
  title: string;
  highlight?: string | null;
  subtitle?: string | null;
}>(), {
  variant: 'compact',
  pill: null,
  pillEmoji: null,
  highlight: null,
  subtitle: null,
});

// Splits the title around `highlight` so the highlighted phrase
// receives the gradient text-clip treatment seen on the landing
// page. If `highlight` is missing or not found, the whole title
// is rendered plain so the component never breaks.
const titleParts = computed<{ before: string; mid: string; after: string }>(() => {
  if (!props.highlight) return { before: props.title, mid: '', after: '' };
  const idx = props.title.indexOf(props.highlight);
  if (idx < 0) return { before: props.title, mid: '', after: '' };
  return {
    before: props.title.slice(0, idx),
    mid: props.highlight,
    after: props.title.slice(idx + props.highlight.length),
  };
});

const sizeClass = computed(() => {
  return props.variant === 'large'
    ? 'k-py-12 sm:k-py-16'
    : 'k-py-8 sm:k-py-10';
});

const titleClass = computed(() => {
  return props.variant === 'large'
    ? 'k-text-4xl sm:k-text-5xl'
    : 'k-text-2xl sm:k-text-3xl';
});
</script>

<template>
  <section
    :class="[
      'k-relative k-overflow-hidden k-rounded-knot-lg k-border k-border-knot-border',
      sizeClass,
    ]"
  >
    <div class="k-absolute k-inset-0 k-bg-knot-mesh" aria-hidden="true" />
    <div
      class="k-absolute k-inset-0 k-opacity-30 k-bg-knot-noise k-mix-blend-overlay k-pointer-events-none"
      aria-hidden="true"
    />

    <div class="k-relative k-px-6 sm:k-px-10 k-flex k-flex-col k-items-start k-gap-4 k-max-w-4xl">
      <div
        v-if="pill"
        class="k-inline-flex k-items-center k-gap-2 k-px-3 k-py-1 k-rounded-knot-pill k-bg-knot-glass-bg k-backdrop-blur-knot k-border k-border-knot-border-strong k-text-xs k-font-semibold k-text-knot-text-muted"
      >
        <span v-if="pillEmoji" class="k-text-sm k-leading-none">{{ pillEmoji }}</span>
        <span>{{ pill }}</span>
      </div>

      <h1
        :class="[
          'k-font-bold k-leading-[1.1] k-tracking-tight k-text-knot-text',
          titleClass,
        ]"
      >
        <template v-if="highlight && titleParts.mid">
          <span>{{ titleParts.before }}</span>
          <span
            class="k-bg-clip-text k-text-transparent k-bg-knot-hero"
          >{{ titleParts.mid }}</span>
          <span>{{ titleParts.after }}</span>
        </template>
        <template v-else>
          {{ title }}
        </template>
      </h1>

      <p
        v-if="subtitle"
        :class="[
          'k-text-knot-text-muted k-leading-relaxed',
          variant === 'large' ? 'k-text-base sm:k-text-lg' : 'k-text-sm sm:k-text-base',
        ]"
      >
        {{ subtitle }}
      </p>

      <div v-if="$slots.actions" class="k-mt-2 k-flex k-flex-wrap k-items-center k-gap-3">
        <slot name="actions" />
      </div>

      <div v-if="$slots.aside" class="k-mt-2">
        <slot name="aside" />
      </div>
    </div>

    <div v-if="$slots.right" class="k-absolute k-top-4 k-right-4 sm:k-top-6 sm:k-right-6">
      <slot name="right" />
    </div>
  </section>
</template>
