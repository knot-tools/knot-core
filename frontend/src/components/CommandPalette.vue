<!--
  CommandPalette — Cmd+K global search.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, nextTick, onMounted, onUnmounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Search } from 'lucide-vue-next';
import { knotApi } from '../lib/api';
import { isMarketplaceUiEnabled } from '../lib/marketplaceUi';
import type { KnotCommandPaletteEntry, KnotCoreSurface } from '../lib/knotCore';
import { KNOT_Z_MODAL, knotViewportTopOffset } from '../lib/overlayStacking';

const open = ref(false);
const query = ref('');
const results = ref<Array<{ kind: string; id: number | string; label: string; subtitle?: string; href: string }>>([]);
const cursor = ref(0);
const inputEl = ref<HTMLInputElement | null>(null);
const baseUrl = ((window as any).KNOT_BASE_URL || '') as string;

const { t } = useI18n();

const commandPaletteStyle = {
  zIndex: KNOT_Z_MODAL,
  paddingTop: knotViewportTopOffset(2),
};

// Extension-contributed entries (ADR-20 Phase 6g). Reactive copy of
// `window.KnotCore.commandPalette.getEntries()`, refreshed on every
// `knot:command-palette-updated` event so the palette stays in sync
// with extension routing without polling.
const extensionEntries = ref<KnotCommandPaletteEntry[]>([]);

function readKnotCorePalette(): KnotCommandPaletteEntry[] {
  const core = (window as unknown as { KnotCore?: KnotCoreSurface }).KnotCore;
  if (!core?.commandPalette || typeof core.commandPalette.getEntries !== 'function') {
    return [];
  }
  try {
    return core.commandPalette.getEntries();
  } catch (_e) {
    return [];
  }
}

function refreshExtensionEntries(): void {
  extensionEntries.value = readKnotCorePalette();
}

async function refresh() {
  const q = query.value.trim();
  results.value = [];
  if (!q) return;
  try {
    const wfs = await knotApi.listWorkflows();
    const qLower = q.toLowerCase();
    const matched = [];
    for (const w of wfs.workflows) {
      if ((w.label || '').toLowerCase().includes(qLower)) {
        matched.push({
          kind: 'workflow',
          id: w.id,
          label: w.label,
          subtitle: t('commandPalette.workflowSubtitle', {
            id: w.id,
            status: String(w.status),
          }),
          href: `${baseUrl}?mode=editor&workflow_id=${w.id}`,
        });
      }
    }
    results.value = matched;
  } catch { /* silent */ }
  cursor.value = 0;
}

let debounce: ReturnType<typeof setTimeout> | undefined;
watch(query, () => {
  if (debounce) clearTimeout(debounce);
  debounce = setTimeout(refresh, 150);
});

function onKey(e: KeyboardEvent) {
  const isMod = e.metaKey || e.ctrlKey;
  if (isMod && e.key.toLowerCase() === 'k') {
    e.preventDefault();
    open.value = !open.value;
    if (open.value) {
      refreshExtensionEntries();
      nextTick(() => inputEl.value?.focus());
    }
    return;
  }
  if (!open.value) return;
  if (e.key === 'Escape') { open.value = false; return; }
  if (e.key === 'ArrowDown') { e.preventDefault(); cursor.value = Math.min(cursor.value + 1, results.value.length - 1); }
  if (e.key === 'ArrowUp') { e.preventDefault(); cursor.value = Math.max(cursor.value - 1, 0); }
  if (e.key === 'Enter') {
    const r = results.value[cursor.value];
    if (r) window.location.href = r.href;
  }
}

function onPaletteOpenRequest(): void {
  if (!open.value) {
    open.value = true;
    refreshExtensionEntries();
    nextTick(() => inputEl.value?.focus());
  }
}

function onPaletteCloseRequest(): void {
  if (open.value) {
    open.value = false;
  }
}

onMounted(() => {
  window.addEventListener('keydown', onKey);
  window.addEventListener('knot:command-palette-updated', refreshExtensionEntries);
  window.addEventListener('knot:command-palette-open', onPaletteOpenRequest);
  window.addEventListener('knot:command-palette-close', onPaletteCloseRequest);
  refreshExtensionEntries();
});
onUnmounted(() => {
  window.removeEventListener('keydown', onKey);
  window.removeEventListener('knot:command-palette-updated', refreshExtensionEntries);
  window.removeEventListener('knot:command-palette-open', onPaletteOpenRequest);
  window.removeEventListener('knot:command-palette-close', onPaletteCloseRequest);
});

/**
 * Group extension entries by `category` and filter by current query.
 * Categories preserve insertion order via Map semantics so an
 * extension can decide its own grouping (e.g. "Knot Migration",
 * "Knot Migration / Discovery").
 */
const groupedExtensionEntries = computed(() => {
  const q = query.value.trim().toLowerCase();
  const matches = (entry: KnotCommandPaletteEntry): boolean => {
    if (q === '') return true;
    if (entry.label.toLowerCase().includes(q)) return true;
    if (entry.description?.toLowerCase().includes(q)) return true;
    if (entry.keywords?.some((k) => k.toLowerCase().includes(q))) return true;
    return false;
  };
  const groups = new Map<string, KnotCommandPaletteEntry[]>();
  for (const entry of extensionEntries.value) {
    if (!matches(entry)) continue;
    const cat = entry.category ?? t('commandPalette.categoryExtensions');
    const bucket = groups.get(cat);
    if (bucket) {
      bucket.push(entry);
    } else {
      groups.set(cat, [entry]);
    }
  }
  return Array.from(groups.entries()).map(([category, entries]) => ({ category, entries }));
});

function runEntry(entry: KnotCommandPaletteEntry): void {
  if (entry.action) {
    try {
      entry.action();
      open.value = false;
      return;
    } catch (err) {
      console.warn('[KnotCore.commandPalette] entry action threw:', err);
    }
  }
  if (entry.href) {
    window.location.href = entry.href;
  }
}

const navItems = computed(() => {
  const all = [
    { labelKey: 'nav.dashboard', href: `${baseUrl}?mode=dashboard`, hideIfNoMarketplace: false },
    { labelKey: 'nav.observability', href: `${baseUrl}?mode=observability`, hideIfNoMarketplace: false },
    { labelKey: 'nav.workflows', href: `${baseUrl}?mode=workflows`, hideIfNoMarketplace: false },
    { labelKey: 'nav.executions', href: `${baseUrl}?mode=executions`, hideIfNoMarketplace: false },
    { labelKey: 'nav.connectors', href: `${baseUrl}?mode=connectors`, hideIfNoMarketplace: false },
    { labelKey: 'nav.credentials', href: `${baseUrl}?mode=credentials`, hideIfNoMarketplace: false },
    { labelKey: 'nav.marketplace', href: `${baseUrl}?mode=marketplace`, hideIfNoMarketplace: true },
    { labelKey: 'nav.templates', href: `${baseUrl}?mode=marketplace&tab=templates`, hideIfNoMarketplace: true },
    { labelKey: 'nav.variables', href: `${baseUrl}?mode=variables`, hideIfNoMarketplace: false },
    { labelKey: 'nav.audit', href: `${baseUrl}?mode=audit`, hideIfNoMarketplace: false },
    { labelKey: 'nav.doctor', href: `${baseUrl}?mode=doctor`, hideIfNoMarketplace: false },
    { labelKey: 'nav.inbox', href: `${baseUrl}?mode=inbox`, hideIfNoMarketplace: false },
    { labelKey: 'nav.assistant', href: `${baseUrl}?mode=assistant`, hideIfNoMarketplace: false },
    { labelKey: 'nav.book', href: `${baseUrl}?mode=book`, hideIfNoMarketplace: false },
  ];
  const enabled = isMarketplaceUiEnabled();
  const filtered = enabled ? all : all.filter((row) => !row.hideIfNoMarketplace);

  return filtered
    .filter((row) => {
      if (!query.value) return true;
      const ql = query.value.toLowerCase();
      return t(row.labelKey).toLowerCase().includes(ql);
    })
    .map(({ hideIfNoMarketplace: _, labelKey, ...rest }) => ({
      ...rest,
      label: t(labelKey),
    }));
});
</script>

<template>
  <Teleport to="body">
    <div
      v-if="open"
      class="k-fixed k-inset-0 k-bg-black/50 k-backdrop-blur-sm k-flex k-items-start k-justify-center k-px-4"
      :style="commandPaletteStyle"
      @click.self="open = false"
    >
      <div class="k-w-full k-max-w-xl k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-lg k-shadow-knot-lg k-overflow-hidden">
        <div class="k-flex k-items-center k-gap-2 k-px-3 k-py-3 k-border-b k-border-knot-border">
          <Search :size="16" class="k-text-knot-text-muted" />
          <input
            ref="inputEl"
            v-model="query"
            :placeholder="t('commandPalette.placeholder')"
            class="k-flex-1 k-bg-transparent k-text-sm k-text-knot-text focus:k-outline-none"
          />
          <span class="k-text-[10px] k-text-knot-text-soft k-font-mono k-px-1.5 k-py-0.5 k-rounded-knot-sm k-bg-knot-surface-soft">ESC</span>
        </div>
        <div class="k-max-h-[60vh] k-overflow-y-auto">
          <div v-if="results.length" class="k-py-1">
            <div class="k-px-3 k-py-1 k-text-[10px] k-uppercase k-tracking-wider k-text-knot-text-soft k-font-bold">{{ t('commandPalette.sectionWorkflows') }}</div>
            <a
              v-for="(r, i) in results"
              :key="r.kind + r.id"
              :href="r.href"
              :class="[
                'k-block k-px-3 k-py-2 k-no-underline',
                i === cursor ? 'k-bg-knot-primary-soft k-text-knot-primary' : 'k-text-knot-text hover:k-bg-knot-surface-soft',
              ]"
            >
              <div class="k-text-sm k-font-semibold">{{ r.label }}</div>
              <div class="k-text-[11px] k-text-knot-text-muted">{{ r.subtitle }}</div>
            </a>
          </div>
          <div
            v-for="group in groupedExtensionEntries"
            :key="'ext-group-' + group.category"
            class="k-py-1 k-border-t k-border-knot-border"
          >
            <div class="k-px-3 k-py-1 k-text-[10px] k-uppercase k-tracking-wider k-text-knot-text-soft k-font-bold">{{ group.category }}</div>
            <button
              v-for="entry in group.entries"
              :key="entry.id"
              type="button"
              class="k-w-full k-text-left k-block k-px-3 k-py-2 k-text-sm k-text-knot-text hover:k-bg-knot-surface-soft k-no-underline k-cursor-pointer k-border-0 k-bg-transparent"
              @click="runEntry(entry)"
            >
              <div class="k-font-semibold">{{ entry.label }}</div>
              <div v-if="entry.description" class="k-text-[11px] k-text-knot-text-muted">{{ entry.description }}</div>
              <span
                v-if="entry.hint"
                class="k-text-[10px] k-text-knot-text-soft k-font-mono k-px-1.5 k-py-0.5 k-rounded-knot-sm k-bg-knot-surface-soft k-float-right"
              >{{ entry.hint }}</span>
            </button>
          </div>
          <div class="k-py-1 k-border-t k-border-knot-border">
            <div class="k-px-3 k-py-1 k-text-[10px] k-uppercase k-tracking-wider k-text-knot-text-soft k-font-bold">{{ t('commandPalette.sectionNavigate') }}</div>
            <a
              v-for="it in navItems"
              :key="it.label"
              :href="it.href"
              class="k-block k-px-3 k-py-2 k-text-sm k-text-knot-text hover:k-bg-knot-surface-soft k-no-underline"
            >{{ it.label }}</a>
          </div>
        </div>
      </div>
    </div>
  </Teleport>
</template>
