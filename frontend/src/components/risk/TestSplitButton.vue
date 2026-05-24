<!--
  TestSplitButton — V2.5 UX-3
  Split-button for the editor "Test" action with risk-aware behaviour.

    [Tester ▾]
       ├─ Test à blanc (dry-run)             ← default for any risk level
       ├─ Test réel sur petite donnée        ← caution / critical
       └─ Test réel complet                  ← critical only, with confirm

  - On `safe` workflows, "Test à blanc" runs `simulate()` and "Test réel"
    runs `execute()` without any extra confirmation.
  - On `caution`, "Test réel" prompts a single-step confirm.
  - On `critical`, "Test réel" requires a typed confirmation
    ("YES" or workflow ref) to avoid muscle-memory accidents.

  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<template>
  <div class="relative inline-flex" data-component="test-split-button">
    <button
      type="button"
      class="rounded-l-md border border-slate-300 bg-white px-3 py-1.5 text-sm font-medium hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-500"
      @click="onPrimary"
    >
      <span class="inline-flex items-center gap-1.5">
        <Beaker :size="14" />
        {{ t('test.dryRun') }}
      </span>
    </button>
    <button
      type="button"
      :aria-expanded="open"
      class="rounded-r-md border border-l-0 border-slate-300 bg-white px-1.5 py-1.5 hover:bg-slate-50 focus:outline-none focus:ring-2 focus:ring-emerald-500"
      @click="open = !open"
    >
      <ChevronDown :size="14" />
    </button>

    <div
      v-if="open"
      class="absolute right-0 top-full z-30 mt-1 min-w-[18rem] rounded-md border border-slate-200 bg-white shadow-lg"
      role="menu"
    >
      <button
        type="button"
        class="flex w-full items-start gap-2 px-3 py-2 text-left text-sm hover:bg-slate-50"
        role="menuitem"
        @click="run('dry')"
      >
        <Beaker :size="14" class="mt-0.5 text-slate-500" />
        <div>
          <div class="font-medium">{{ t('test.dryRun') }}</div>
          <p class="text-xs text-slate-500">
            {{ t('test.dryRunDesc') }}
          </p>
        </div>
      </button>

      <button
        type="button"
        class="flex w-full items-start gap-2 px-3 py-2 text-left text-sm hover:bg-amber-50"
        role="menuitem"
        @click="onRealSmall"
      >
        <Activity :size="14" class="mt-0.5 text-amber-500" />
        <div>
          <div class="font-medium">{{ t('test.realSmall') }}</div>
          <p class="text-xs text-slate-500">
            {{ t('test.realSmallDesc') }}
          </p>
        </div>
      </button>

      <button
        v-if="riskLevel === 'critical'"
        type="button"
        class="flex w-full items-start gap-2 border-t border-slate-100 px-3 py-2 text-left text-sm hover:bg-red-50"
        role="menuitem"
        @click="onRealFull"
      >
        <OctagonAlert :size="14" class="mt-0.5 text-red-500" />
        <div>
          <div class="font-medium">{{ t('test.realFull') }}</div>
          <p class="text-xs text-slate-500">
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
