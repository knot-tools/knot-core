<!--
  Image gallery (HTTPS CDN URLs validated server-side).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed } from 'vue';
import { useId } from 'vue';
import { Images } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

export interface GalleryImage {
  src?: string;
  alt?: string;
}

const props = withDefaults(
  defineProps<{
    title?: string;
    images?: GalleryImage[];
    ariaLabel?: string;
  }>(),
  { images: () => [] },
);

const { t } = useI18n();
const headingId = useId();
const heading = computed(() => props.title ?? t('marketplace.galleryTitle'));
const list = computed(() => (Array.isArray(props.images) ? props.images : []).filter((i) => i.src));
</script>

<template>
  <section
    class="k-space-y-3"
    role="region"
    :aria-label="props.ariaLabel ?? heading"
    :aria-labelledby="props.ariaLabel ? undefined : headingId"
  >
    <h2 :id="headingId" class="k-flex k-items-center k-gap-2 k-text-sm k-font-bold k-text-knot-text">
      <Images :size="16" class="k-text-knot-primary" aria-hidden="true" />
      {{ heading }}
    </h2>

    <p v-if="list.length === 0" class="k-text-xs k-text-knot-text-soft">{{ t('marketplace.galleryEmpty') }}</p>

    <ul
      v-else
      class="k-grid k-gap-3 sm:k-grid-cols-2 lg:k-grid-cols-3 k-list-none"
    >
      <li v-for="(img, idx) in list" :key="idx">
        <figure class="k-rounded-knot-md k-border k-border-knot-border k-overflow-hidden k-bg-knot-surface k-shadow-knot-sm">
          <img
            :src="img.src"
            :alt="img.alt ?? t('marketplace.galleryImageFallbackAlt')"
            class="k-w-full k-h-40 k-object-cover"
            loading="lazy"
            decoding="async"
          />
        </figure>
      </li>
    </ul>
  </section>
</template>
