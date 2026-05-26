<!--
  Responsive video embed (editorial URL — must be https allow-listed server-side).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed } from 'vue';
import { useId } from 'vue';
import { PlayCircle } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

const props = defineProps<{
  title?: string;
  /** HTTPS embed or watch URL (YouTube / Vimeo style) */
  src?: string;
  caption?: string;
  ariaLabel?: string;
}>();

const { t } = useI18n();
const headingId = useId();
const heading = computed(() => props.title ?? t('marketplace.videoTitle'));

const embedUrl = computed(() => {
  const u = props.src?.trim();
  if (!u || !u.startsWith('https://')) {
    return null;
  }
  // YouTube watch → embed
  try {
    const url = new URL(u);
    if (url.hostname.includes('youtube.com') && url.pathname === '/watch') {
      const id = url.searchParams.get('v');
      return id ? `https://www.youtube.com/embed/${encodeURIComponent(id)}` : null;
    }
    if (url.hostname === 'youtu.be') {
      const id = url.pathname.replace(/^\//, '');
      return id ? `https://www.youtube.com/embed/${encodeURIComponent(id)}` : null;
    }
    if (url.hostname.includes('vimeo.com')) {
      const parts = url.pathname.split('/').filter(Boolean);
      const id = parts[0];
      return id ? `https://player.vimeo.com/video/${encodeURIComponent(id)}` : null;
    }
  } catch {
    return null;
  }
  return u;
});
</script>

<template>
  <section
    class="k-space-y-2"
    role="region"
    :aria-label="props.ariaLabel ?? heading"
    :aria-labelledby="props.ariaLabel ? undefined : headingId"
  >
    <h2 :id="headingId" class="k-flex k-items-center k-gap-2 k-text-sm k-font-bold k-text-knot-text">
      <PlayCircle :size="16" class="k-text-knot-primary" aria-hidden="true" />
      {{ heading }}
    </h2>

    <div
      v-if="!embedUrl"
      class="k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface-soft k-p-4 k-text-xs k-text-knot-text-soft"
      role="status"
    >
      {{ t('marketplace.videoMissing') }}
    </div>

    <div v-else class="k-relative k-rounded-knot-md k-overflow-hidden k-border k-border-knot-border k-aspect-video k-bg-black">
      <iframe
        :src="embedUrl"
        class="k-absolute k-inset-0 k-h-full k-w-full"
        :title="heading"
        allow="accelerometer; autoplay; clipboard-write; encrypted-media; gyroscope; picture-in-picture; web-share"
        allowfullscreen
      />
    </div>

    <p v-if="caption" class="k-text-[11px] k-text-knot-text-muted">{{ caption }}</p>
  </section>
</template>
