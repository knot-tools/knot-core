<!--
  Product changelog timeline with lightweight markdown notes.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';

import { parseMarkdownLight } from '../../lib/markdownLight';

export interface ChangelogEntry {
  version?: string;
  date?: string;
  notes?: string;
}

const props = withDefaults(
  defineProps<{
    entries?: ChangelogEntry[];
    ariaLabel?: string;
  }>(),
  { entries: () => [] },
);

const { t } = useI18n();

const rows = computed(() =>
  (props.entries ?? []).filter((row) => row.version || row.notes || row.date),
);

function notesHtml(notes?: string): string {
  if (!notes?.trim()) {
    return '';
  }
  return parseMarkdownLight(notes.trim());
}
</script>

<template>
  <section class="k-space-y-4" role="region" :aria-label="ariaLabel ?? t('marketplace.changelogTitle')">
    <h2 class="k-text-sm k-font-bold k-text-knot-text">{{ t('marketplace.changelogTitle') }}</h2>
    <p v-if="rows.length === 0" class="k-text-xs k-text-knot-text-muted">{{ t('marketplace.changelogEmpty') }}</p>
    <ol v-else class="k-relative k-border-l k-border-knot-border k-pl-4 k-space-y-4">
      <li v-for="(entry, idx) in rows" :key="`${entry.version ?? 'v'}-${idx}`" class="k-space-y-1">
        <div class="k-flex k-flex-wrap k-items-baseline k-gap-2">
          <span v-if="entry.version" class="k-text-sm k-font-semibold k-text-knot-text">v{{ entry.version }}</span>
          <time v-if="entry.date" class="k-text-[11px] k-text-knot-text-soft">{{ entry.date }}</time>
        </div>
        <div
          v-if="entry.notes"
          class="k-text-sm k-text-knot-text-muted k-prose-knot"
          v-html="notesHtml(entry.notes)"
        />
      </li>
    </ol>
  </section>
</template>
