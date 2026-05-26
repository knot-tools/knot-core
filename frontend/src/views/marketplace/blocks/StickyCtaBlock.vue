<!--
  Sticky call-to-action strip (stays visible while scrolling the host).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed } from 'vue';
import { useId } from 'vue';
import { Megaphone } from 'lucide-vue-next';
import { useI18n } from 'vue-i18n';

import { useSanitizedMarketplaceHref } from '../../../composables/useSanitizedMarketplaceHref';

const props = defineProps<{
  title?: string;
  body?: string;
  ctaLabel?: string;
  ctaHref?: string;
  ariaLabel?: string;
}>();

const { t } = useI18n();
const { sanitize } = useSanitizedMarketplaceHref();
const headingId = useId();
const heading = computed(() => props.title ?? t('marketplace.stickyCtaTitle'));
const label = computed(() => props.ctaLabel ?? t('marketplace.stickyCtaButton'));
const href = computed(() => sanitize(props.ctaHref) ?? sanitize('https://knot.tools/pricing'));
</script>

<template>
  <aside
    class="k-sticky k-bottom-3 k-z-10 k-mt-4 k-rounded-knot-md k-border k-border-knot-primary k-bg-knot-primary-soft k-p-4 k-shadow-knot-md"
    role="complementary"
    :aria-label="props.ariaLabel ?? t('marketplace.stickyCtaAria')"
  >
    <div class="k-flex k-flex-col k-gap-3 sm:k-flex-row sm:k-items-center sm:k-justify-between">
      <div class="k-flex k-items-start k-gap-3 k-min-w-0">
        <Megaphone :size="20" class="k-text-knot-primary k-shrink-0 k-mt-0.5" aria-hidden="true" />
        <div>
          <p :id="headingId" class="k-text-sm k-font-bold k-text-knot-text">{{ heading }}</p>
          <p v-if="body" class="k-mt-1 k-text-xs k-text-knot-text-muted">{{ body }}</p>
        </div>
      </div>
      <a
        :href="href"
        target="_blank"
        rel="noopener noreferrer"
        class="k-inline-flex k-items-center k-justify-center k-px-4 k-py-2 k-rounded-knot-sm k-bg-knot-primary k-text-white k-text-xs k-font-semibold hover:k-opacity-95 focus-visible:k-outline focus-visible:k-outline-2 focus-visible:k-outline-offset-2 focus-visible:k-outline-knot-primary"
        :aria-describedby="body ? headingId : undefined"
      >
        {{ label }}
      </a>
    </div>
  </aside>
</template>
