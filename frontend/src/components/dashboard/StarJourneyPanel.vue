<!--
  Star journey — three PME paths from dashboard to first successful run.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { Rocket, FileWarning, FileText, UserPlus, ArrowRight } from 'lucide-vue-next';

const { t } = useI18n();

const baseUrl = computed(() =>
  typeof window !== 'undefined' && (window as unknown as { KNOT_BASE_URL?: string }).KNOT_BASE_URL
    ? (window as unknown as { KNOT_BASE_URL?: string }).KNOT_BASE_URL!
    : '',
);

const journeys = computed(() => [
  {
    key: 'overdue',
    icon: FileWarning,
    title: t('starJourney.overdueTitle'),
    body: t('starJourney.overdueBody'),
    file: '02-relance-facture-impayee.knot.json',
    href: `${baseUrl.value}?mode=workflows&starter=02-relance-facture-impayee`,
  },
  {
    key: 'quote',
    icon: FileText,
    title: t('starJourney.quoteTitle'),
    body: t('starJourney.quoteBody'),
    file: '04-devis-to-facture.knot.json',
    href: `${baseUrl.value}?mode=workflows&starter=04-devis-to-facture`,
  },
  {
    key: 'welcome',
    icon: UserPlus,
    title: t('starJourney.welcomeTitle'),
    body: t('starJourney.welcomeBody'),
    file: '07-new-customer-welcome.knot.json',
    href: `${baseUrl.value}?mode=workflows&starter=07-new-customer-welcome`,
  },
]);
</script>

<template>
  <section
    class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-5"
    data-testid="star-journey-panel"
  >
    <h2 class="k-text-sm k-font-bold k-text-knot-text k-flex k-items-center k-gap-2 k-mb-1">
      <Rocket :size="16" class="k-text-knot-primary" />
      {{ t('starJourney.title') }}
    </h2>
    <p class="k-text-xs k-text-knot-text-muted k-mb-4">{{ t('starJourney.subtitle') }}</p>

    <ol class="k-grid k-grid-cols-1 md:k-grid-cols-3 k-gap-3 k-list-none k-p-0 k-m-0">
      <li
        v-for="(j, idx) in journeys"
        :key="j.key"
        class="k-border k-border-knot-border k-rounded-knot-sm k-p-3 k-flex k-flex-col k-gap-2 k-bg-knot-surface-soft"
      >
        <div class="k-flex k-items-center k-gap-2">
          <span
            class="k-h-6 k-w-6 k-rounded-full k-bg-knot-primary k-text-white k-text-[11px] k-font-bold k-flex k-items-center k-justify-center"
          >{{ idx + 1 }}</span>
          <component :is="j.icon" :size="14" class="k-text-knot-primary" />
          <span class="k-text-sm k-font-semibold k-text-knot-text">{{ j.title }}</span>
        </div>
        <p class="k-text-[11px] k-text-knot-text-muted k-leading-snug k-flex-1">{{ j.body }}</p>
        <code class="k-text-[10px] k-font-mono k-text-knot-text-soft">examples/starter/{{ j.file }}</code>
        <a
          :href="j.href"
          class="k-inline-flex k-items-center k-gap-1 k-text-xs k-font-semibold k-text-knot-primary hover:k-underline"
        >
          {{ t('starJourney.cta') }}
          <ArrowRight :size="12" />
        </a>
      </li>
    </ol>

    <p class="k-mt-3 k-text-[11px] k-text-knot-text-muted">
      {{ t('starJourney.footer') }}
      <a :href="baseUrl + '?mode=doctor'" class="k-text-knot-primary k-font-semibold hover:k-underline">
        {{ t('starJourney.doctorLink') }}
      </a>
    </p>
  </section>
</template>
