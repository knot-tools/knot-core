<!--
  ProblemsPanel — VS Code-style bottom panel listing live workflow validation issues.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, ref } from 'vue';
import { AlertTriangle, AlertCircle, ChevronDown, ChevronUp } from 'lucide-vue-next';
import type { ValidationIssue } from '../lib/validator';
import { formatValidationIssueMessage } from '../lib/validator';

const props = defineProps<{
  issues: ValidationIssue[];
}>();

const emit = defineEmits<{
  (e: 'jump', nodeId: string): void;
}>();

const collapsed = ref(false);

const errorCount = computed(() => props.issues.filter((i) => i.severity === 'error').length);
const warningCount = computed(() => props.issues.filter((i) => i.severity === 'warning').length);

function jump(issue: ValidationIssue) {
  if (issue.nodeId) emit('jump', issue.nodeId);
}
</script>

<template>
  <div
    v-if="issues.length > 0"
    data-knot-test="knot-problems-panel"
    class="k-border-t k-border-knot-border k-bg-knot-surface k-text-sm"
    role="region"
    aria-label="Workflow problems"
  >
    <button
      @click="collapsed = !collapsed"
      class="k-w-full k-flex k-items-center k-justify-between k-px-4 k-py-2 hover:k-bg-knot-surface-soft k-transition"
    >
      <div class="k-flex k-items-center k-gap-3">
        <span v-if="errorCount > 0" class="k-flex k-items-center k-gap-1 k-text-knot-danger k-font-semibold">
          <AlertCircle :size="14" /> {{ errorCount }}
        </span>
        <span v-if="warningCount > 0" class="k-flex k-items-center k-gap-1 k-text-knot-warning k-font-semibold">
          <AlertTriangle :size="14" /> {{ warningCount }}
        </span>
        <span class="k-text-knot-text-muted">Problems</span>
      </div>
      <component :is="collapsed ? ChevronUp : ChevronDown" :size="14" class="k-text-knot-text-muted" />
    </button>
    <ul v-if="!collapsed" class="k-max-h-40 k-overflow-y-auto k-divide-y k-divide-knot-border">
      <li
        v-for="(issue, idx) in issues"
        :key="idx"
        @click="jump(issue)"
        :class="[
          'k-px-4 k-py-2 k-flex k-items-start k-gap-2',
          issue.nodeId ? 'k-cursor-pointer hover:k-bg-knot-surface-soft' : '',
        ]"
      >
        <AlertCircle v-if="issue.severity === 'error'" :size="14" class="k-text-knot-danger k-mt-0.5" />
        <AlertTriangle v-else :size="14" class="k-text-knot-warning k-mt-0.5" />
        <div class="k-flex-1">
          <div class="k-text-knot-text">{{ formatValidationIssueMessage(issue) }}</div>
          <div class="k-text-[11px] k-text-knot-text-soft k-font-mono">
            {{ issue.code }}<span v-if="issue.nodeId"> · node {{ issue.nodeId }}</span>
          </div>
        </div>
      </li>
    </ul>
  </div>
</template>
