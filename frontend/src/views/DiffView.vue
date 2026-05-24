<!--
  Knot — Visual diff between two workflow versions (graph + JSON side-by-side).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, onMounted, ref, watch } from 'vue';
import { GitCompare, Loader2, ArrowLeftRight, Plus, Minus, Pencil } from 'lucide-vue-next';
import { knotApi, type WorkflowVersion, type WorkflowDiff } from '../lib/api';

const props = defineProps<{ workflowId: number | null }>();

const versions = ref<WorkflowVersion[]>([]);
const leftId = ref<number | null>(null);
const rightId = ref<number | null>(null);
const diff = ref<WorkflowDiff | null>(null);
const leftDef = ref<unknown>(null);
const rightDef = ref<unknown>(null);
const loading = ref(false);
const error = ref<string | null>(null);

async function loadVersions() {
  if (!props.workflowId) return;
  try {
    const res = await knotApi.listWorkflowVersions(props.workflowId);
    versions.value = res.versions;
    if (versions.value.length >= 2) {
      rightId.value = versions.value[0].id;
      leftId.value = versions.value[1].id;
      await loadDiff();
    } else if (versions.value.length === 1) {
      rightId.value = versions.value[0].id;
      await loadDiff();
    }
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Failed to load versions';
  }
}

async function loadDiff() {
  if (!props.workflowId) return;
  loading.value = true;
  error.value = null;
  try {
    const res = await knotApi.diffWorkflowVersions(props.workflowId, leftId.value, rightId.value);
    diff.value = res.diff;
    leftDef.value = res.left.definition;
    rightDef.value = res.right.definition;
  } catch (e) {
    error.value = e instanceof Error ? e.message : 'Failed to compute diff';
  } finally {
    loading.value = false;
  }
}

function swap() {
  const tmp = leftId.value;
  leftId.value = rightId.value;
  rightId.value = tmp;
  loadDiff();
}

function versionLabel(id: number | null): string {
  if (id === null) return 'Current';
  const v = versions.value.find((x) => x.id === id);
  if (!v) return `#${id}`;
  return v.label ? `${v.label} (#${id})` : `Snapshot #${id} — ${v.createdAt}`;
}

function jsonOf(value: unknown): string {
  try {
    return JSON.stringify(value, null, 2);
  } catch {
    return String(value);
  }
}

watch(() => props.workflowId, loadVersions, { immediate: false });
onMounted(loadVersions);

const summary = computed(() => diff.value?.summary);
</script>

<template>
  <div class="k-min-h-screen k-w-full k-bg-knot-bg k-text-knot-text k-p-6 k-space-y-4">
    <header class="k-flex k-flex-wrap k-items-center k-gap-3 k-justify-between">
      <div class="k-flex k-items-center k-gap-2">
        <GitCompare :size="20" class="k-text-knot-primary" />
        <h1 class="k-text-xl k-font-semibold">Workflow diff</h1>
      </div>
      <div class="k-flex k-items-center k-gap-2 k-text-sm" v-if="summary">
        <span class="k-px-2 k-py-0.5 k-rounded-knot-sm k-bg-knot-success k-text-white">+{{ summary.nodesAdded }} nodes</span>
        <span class="k-px-2 k-py-0.5 k-rounded-knot-sm k-bg-knot-error k-text-white">-{{ summary.nodesRemoved }} nodes</span>
        <span class="k-px-2 k-py-0.5 k-rounded-knot-sm k-bg-knot-warning k-text-white">~{{ summary.nodesChanged }} changed</span>
        <span class="k-px-2 k-py-0.5 k-rounded-knot-sm k-bg-knot-success/50 k-text-white">+{{ summary.edgesAdded }} edges</span>
        <span class="k-px-2 k-py-0.5 k-rounded-knot-sm k-bg-knot-error/50 k-text-white">-{{ summary.edgesRemoved }} edges</span>
      </div>
    </header>

    <section class="k-flex k-flex-wrap k-items-center k-gap-3 k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-3">
      <label class="k-flex k-items-center k-gap-2 k-text-sm">
        <span class="k-text-knot-text-muted">Base</span>
        <select v-model.number="leftId" @change="loadDiff" class="k-bg-knot-bg k-border k-border-knot-border k-rounded-knot-sm k-px-2 k-py-1">
          <option :value="null">Current draft</option>
          <option v-for="v in versions" :key="`l-${v.id}`" :value="v.id">{{ versionLabel(v.id) }}</option>
        </select>
      </label>
      <button @click="swap" class="k-inline-flex k-items-center k-gap-1 k-px-2 k-py-1 k-rounded-knot-sm k-bg-knot-bg k-border k-border-knot-border">
        <ArrowLeftRight :size="14" />
        Swap
      </button>
      <label class="k-flex k-items-center k-gap-2 k-text-sm">
        <span class="k-text-knot-text-muted">Compared</span>
        <select v-model.number="rightId" @change="loadDiff" class="k-bg-knot-bg k-border k-border-knot-border k-rounded-knot-sm k-px-2 k-py-1">
          <option :value="null">Current draft</option>
          <option v-for="v in versions" :key="`r-${v.id}`" :value="v.id">{{ versionLabel(v.id) }}</option>
        </select>
      </label>
      <Loader2 v-if="loading" :size="14" class="k-animate-spin k-text-knot-text-muted" />
      <span v-if="error" class="k-text-sm k-text-knot-error">{{ error }}</span>
    </section>

    <section v-if="diff" class="k-grid k-grid-cols-1 lg:k-grid-cols-2 k-gap-4">
      <article class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-4">
        <h2 class="k-font-semibold k-mb-3">Structural changes</h2>
        <div v-if="diff.nodes.added.length" class="k-mb-3">
          <div class="k-text-sm k-font-medium k-text-knot-success k-flex k-items-center k-gap-1"><Plus :size="14" /> Added nodes</div>
          <ul class="k-text-xs k-mt-1 k-space-y-1">
            <li v-for="(n, i) in diff.nodes.added" :key="`a-${i}`" class="k-pl-3 k-border-l-2 k-border-knot-success">
              <code>{{ n.id }}</code> — <span class="k-text-knot-text-muted">{{ n.type }}</span>
            </li>
          </ul>
        </div>
        <div v-if="diff.nodes.removed.length" class="k-mb-3">
          <div class="k-text-sm k-font-medium k-text-knot-error k-flex k-items-center k-gap-1"><Minus :size="14" /> Removed nodes</div>
          <ul class="k-text-xs k-mt-1 k-space-y-1">
            <li v-for="(n, i) in diff.nodes.removed" :key="`r-${i}`" class="k-pl-3 k-border-l-2 k-border-knot-error">
              <code>{{ n.id }}</code> — <span class="k-text-knot-text-muted">{{ n.type }}</span>
            </li>
          </ul>
        </div>
        <div v-if="diff.nodes.changed.length" class="k-mb-3">
          <div class="k-text-sm k-font-medium k-text-knot-warning k-flex k-items-center k-gap-1"><Pencil :size="14" /> Changed nodes</div>
          <ul class="k-text-xs k-mt-1 k-space-y-2">
            <li v-for="(n, i) in diff.nodes.changed" :key="`c-${i}`" class="k-pl-3 k-border-l-2 k-border-knot-warning">
              <div><code>{{ n.id }}</code> — <span class="k-text-knot-text-muted">{{ n.type }}</span></div>
              <ul class="k-pl-3 k-mt-1 k-space-y-0.5">
                <li v-for="(p, key) in n.patch" :key="`c-${i}-${key}`">
                  <span class="k-text-knot-text-muted">{{ key }}</span>: <span :class="{'k-text-knot-success': p.op === 'add', 'k-text-knot-error': p.op === 'remove', 'k-text-knot-warning': p.op === 'replace'}">{{ p.op }}</span>
                </li>
              </ul>
            </li>
          </ul>
        </div>
        <div v-if="diff.edges.added.length || diff.edges.removed.length">
          <div class="k-text-sm k-font-medium">Edges</div>
          <ul class="k-text-xs k-mt-1 k-space-y-0.5">
            <li v-for="(e, i) in diff.edges.added" :key="`ea-${i}`" class="k-text-knot-success">+ {{ e.source }} → {{ e.target }}</li>
            <li v-for="(e, i) in diff.edges.removed" :key="`er-${i}`" class="k-text-knot-error">− {{ e.source }} → {{ e.target }}</li>
          </ul>
        </div>
        <div v-if="!diff.nodes.added.length && !diff.nodes.removed.length && !diff.nodes.changed.length && !diff.edges.added.length && !diff.edges.removed.length" class="k-text-sm k-text-knot-text-muted">
          No structural changes between the selected versions.
        </div>
      </article>

      <article class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-4">
        <h2 class="k-font-semibold k-mb-3">JSON side-by-side</h2>
        <div class="k-grid k-grid-cols-2 k-gap-3">
          <div>
            <div class="k-text-xs k-text-knot-text-muted k-mb-1">Base</div>
            <pre class="k-bg-knot-bg k-border k-border-knot-border k-rounded-knot-sm k-p-2 k-text-xs k-max-h-[60vh] k-overflow-auto">{{ jsonOf(leftDef) }}</pre>
          </div>
          <div>
            <div class="k-text-xs k-text-knot-text-muted k-mb-1">Compared</div>
            <pre class="k-bg-knot-bg k-border k-border-knot-border k-rounded-knot-sm k-p-2 k-text-xs k-max-h-[60vh] k-overflow-auto">{{ jsonOf(rightDef) }}</pre>
          </div>
        </div>
      </article>
    </section>

    <p v-if="!diff && !loading && !error" class="k-text-sm k-text-knot-text-muted">
      Select two versions to compare. At least one snapshot is required.
    </p>
  </div>
</template>
