<!--
  TestSplitButton — V2.5 UX-3
  Split-button for the editor "Test" action with risk-aware behaviour.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<template>
  <div class="k-relative k-inline-flex" data-component="test-split-button">
    <button
      type="button"
      class="k-rounded-l-knot-sm k-border k-border-knot-border k-bg-knot-surface k-px-3 k-py-1.5 k-text-sm k-font-medium hover:k-bg-knot-surface-soft focus:k-outline-none focus:k-ring-2 focus:k-ring-knot-primary"
      @click="onPrimary"
    >
      <span class="k-inline-flex k-items-center k-gap-1.5">
        <Beaker :size="14" />
        {{ t('test.dryRun') }}
      </span>
    </button>
    <button
      type="button"
      :aria-expanded="open"
      class="k-rounded-r-knot-sm k-border k-border-l-0 k-border-knot-border k-bg-knot-surface k-px-1.5 k-py-1.5 hover:k-bg-knot-surface-soft focus:k-outline-none focus:k-ring-2 focus:k-ring-knot-primary"
      @click="open = !open"
    >
      <ChevronDown :size="14" />
    </button>

    <div
      v-if="open"
      class="k-absolute k-right-0 k-top-full k-z-30 k-mt-1 k-min-w-[18rem] k-rounded-knot-sm k-border k-border-knot-border k-bg-knot-surface k-shadow-lg"
      role="menu"
    >
      <button
        type="button"
        class="k-flex k-w-full k-items-start k-gap-2 k-px-3 k-py-2 k-text-left k-text-sm hover:k-bg-knot-surface-soft"
        role="menuitem"
        @click="run('dry')"
      >
        <Beaker :size="14" class="k-mt-0.5 k-text-knot-text-muted" />
        <div>
          <div class="k-font-medium">{{ t('test.dryRun') }}</div>
          <p class="k-text-xs k-text-knot-text-muted">
            {{ t('test.dryRunDesc') }}
          </p>
        </div>
      </button>

      <button
        type="button"
        class="k-flex k-w-full k-items-start k-gap-2 k-px-3 k-py-2 k-text-left k-text-sm hover:k-bg-knot-warning-soft"
        role="menuitem"
        @click="onRealSmall"
      >
        <Activity :size="14" class="k-mt-0.5 k-text-knot-warning" />
        <div>
          <div class="k-font-medium">{{ t('test.realSmall') }}</div>
          <p class="k-text-xs k-text-knot-text-muted">
            {{ t('test.realSmallDesc') }}
          </p>
        </div>
      </button>

      <button
        v-if="riskLevel === 'critical'"
        type="button"
        class="k-flex k-w-full k-items-start k-gap-2 k-border-t k-border-knot-border k-px-3 k-py-2 k-text-left k-text-sm hover:k-bg-knot-danger-soft"
        role="menuitem"
        @click="onRealFull"
      >
        <OctagonAlert :size="14" class="k-mt-0.5 k-text-knot-danger" />
        <div>
          <div class="k-font-medium">{{ t('test.realFull') }}</div>
          <p class="k-text-xs k-text-knot-text-muted">
            {{ t('test.realFullDesc') }}
          </p>
        </div>
      </button>
    </div>
  </div>
</template>

<script setup lang="ts">
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { Activity, Beaker, ChevronDown, OctagonAlert } from 'lucide-vue-next';
import type { RiskLevel } from '../../composables/useNodeRisk';
import { useConfirm } from '../../composables/useConfirm';

const confirmDialog = useConfirm();

const props = defineProps<{
  riskLevel: RiskLevel;
  workflowRef: string;
}>();

const emit = defineEmits<{
  (e: 'run', mode: 'dry' | 'real-small' | 'real-full'): void;
}>();

const { t } = useI18n();
const open = ref(false);

function onPrimary(): void {
  open.value = false;
  emit('run', 'dry');
}

function run(mode: 'dry' | 'real-small' | 'real-full'): void {
  open.value = false;
  emit('run', mode);
}

function onRealSmall(): void {
  if (props.riskLevel === 'safe') {
    run('real-small');
    return;
  }
  void confirmDialog
    .confirm({
      title: t('test.confirmRealSmallTitle'),
      message: t('test.confirmRealSmall'),
    })
    .then((ok) => {
      if (ok) run('real-small');
    });
}

function onRealFull(): void {
  const expected = props.workflowRef || 'YES';
  const got = window.prompt(
    t('test.confirmRealFull', { ref: expected }) as string,
  );
  if (got === expected) {
    run('real-full');
  }
}
</script>
