<script setup lang="ts">
import { ref } from 'vue';
import { Bot, Copy, Import, Loader2, Sparkles, Clipboard } from 'lucide-vue-next';
import { knotApi } from '../lib/api';
import { normalizeWorkflowImport, parseWorkflowImportText } from '../lib/normalizeWorkflowImport';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const userRequest = ref('');
const prompt = ref('');
const jsonText = ref('');
const loading = ref(false);
const message = ref<string | null>(null);
const messageKind = ref<'success' | 'error'>('success');

async function generatePrompt() {
  loading.value = true;
  try {
    const result = await knotApi.assistantPrompt(userRequest.value);
    prompt.value = result.prompt;
    message.value = userRequest.value.trim() === ''
      ? t('assistantPage.msgTailoredEmpty')
      : t('assistantPage.msgTailored');
    messageKind.value = 'success';
  } catch (e) {
    message.value = (e as Error).message ?? t('assistantPage.msgGenFailed');
    messageKind.value = 'error';
  } finally {
    loading.value = false;
  }
}

async function copyPrompt() {
  await navigator.clipboard?.writeText(prompt.value);
  message.value = t('assistantPage.msgCopied');
  messageKind.value = 'success';
}

async function pasteFromClipboard() {
  try {
    jsonText.value = await navigator.clipboard.readText();
    message.value = t('assistantPage.msgPasteOk');
    messageKind.value = 'success';
  } catch {
    message.value = t('assistantPage.msgPasteDenied');
    messageKind.value = 'error';
  }
}

async function importWorkflow() {
  try {
    const parsed = parseWorkflowImportText(jsonText.value);
    const payload = normalizeWorkflowImport(parsed, {
      label: t('workflowsPage.importedWorkflowDefault'),
      status: 'draft',
    });
    const result = await knotApi.saveWorkflow(payload);
    if (!result?.workflow?.id) {
      throw new Error(t('assistantPage.msgImportNoWorkflow'));
    }
    message.value = t('assistantPage.msgImporting', { id: result.workflow.id });
    messageKind.value = 'success';
    setTimeout(() => {
      const base = (window as { KNOT_BASE_URL?: string }).KNOT_BASE_URL || '';
      window.location.href = `${base}?mode=editor&workflow_id=${result.workflow.id}`;
    }, 1200);
  } catch (e) {
    message.value = (e as Error).message ?? t('assistantPage.msgImportFailed');
    messageKind.value = 'error';
  }
}
</script>

<template>
  <div class="k-p-6 k-max-w-[1200px] k-mx-auto k-space-y-5">
    <header class="k-flex k-items-center k-gap-3">
      <div class="k-h-10 k-w-10 k-rounded-knot-sm k-bg-knot-primary-soft k-text-knot-primary k-flex k-items-center k-justify-center">
        <Bot :size="20" />
      </div>
      <div>
        <h1 class="k-text-2xl k-font-bold k-text-knot-text">{{ t('assistantPage.title') }}</h1>
        <p class="k-text-sm k-text-knot-text-muted">{{ t('assistantPage.subtitle') }}</p>
      </div>
    </header>

    <div
      v-if="message"
      :class="[
        'k-px-4 k-py-3 k-rounded-knot-sm k-text-sm',
        messageKind === 'success'
          ? 'k-bg-knot-success-soft k-text-knot-success'
          : 'k-bg-knot-danger-soft k-text-knot-danger'
      ]"
    >
      {{ message }}
    </div>

    <article class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-4 k-space-y-3">
      <div class="k-flex k-items-center k-justify-between k-gap-3">
        <div class="k-flex k-items-center k-gap-2">
          <Sparkles :size="16" class="k-text-knot-primary" />
          <h2 class="k-font-semibold k-text-knot-text">{{ t('assistantPage.step0Title') }}</h2>
        </div>
        <span class="k-text-xs k-text-knot-text-muted">{{ t('assistantPage.step0Optional') }}</span>
      </div>
      <textarea
        v-model="userRequest"
        rows="4"
        :placeholder="t('assistantPage.step0Placeholder')"
        class="k-w-full k-text-sm k-bg-knot-bg k-border k-border-knot-border k-rounded-knot-sm k-p-3 k-text-knot-text k-resize-y"
      ></textarea>
    </article>

    <section class="k-grid lg:k-grid-cols-2 k-gap-4">
      <article class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-4 k-space-y-3">
        <div class="k-flex k-items-center k-justify-between">
          <h2 class="k-font-semibold k-text-knot-text">{{ t('assistantPage.step1Title') }}</h2>
          <div class="k-flex k-gap-2">
            <button
              @click="generatePrompt"
              :disabled="loading"
              class="k-inline-flex k-items-center k-gap-1 k-px-3 k-py-1.5 k-rounded-knot-sm k-bg-knot-primary k-text-white k-text-sm k-font-semibold disabled:k-opacity-60"
            >
              <Loader2 v-if="loading" :size="14" class="k-animate-spin" />
              <Sparkles v-else :size="14" />
              {{ t('assistantPage.generate') }}
            </button>
            <button
              @click="copyPrompt"
              :disabled="!prompt"
              class="k-inline-flex k-items-center k-gap-1 k-px-3 k-py-1.5 k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-sm disabled:k-opacity-60"
            >
              <Copy :size="14" /> {{ t('assistantPage.copy') }}
            </button>
          </div>
        </div>
        <textarea
          v-model="prompt"
          rows="22"
          :placeholder="t('assistantPage.promptPlaceholder')"
          class="k-w-full k-text-xs k-font-mono k-bg-knot-bg k-border k-border-knot-border k-rounded-knot-sm k-p-3 k-text-knot-text"
        ></textarea>
      </article>

      <article class="k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-4 k-space-y-3">
        <div class="k-flex k-items-center k-justify-between">
          <h2 class="k-font-semibold k-text-knot-text">{{ t('assistantPage.step2Title') }}</h2>
          <button
            @click="pasteFromClipboard"
            class="k-inline-flex k-items-center k-gap-1 k-px-2.5 k-py-1.5 k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-xs k-font-semibold hover:k-border-knot-primary"
          >
            <Clipboard :size="12" /> {{ t('assistantPage.paste') }}
          </button>
        </div>
        <textarea
          v-model="jsonText"
          rows="22"
          :placeholder="t('assistantPage.jsonPlaceholder')"
          class="k-w-full k-text-xs k-font-mono k-bg-knot-bg k-border k-border-knot-border k-rounded-knot-sm k-p-3 k-text-knot-text"
        ></textarea>
        <button
          @click="importWorkflow"
          :disabled="!jsonText.trim()"
          class="k-inline-flex k-items-center k-gap-2 k-px-3 k-py-2 k-rounded-knot-sm k-bg-knot-success k-text-white k-text-sm k-font-semibold disabled:k-opacity-60"
        >
          <Import :size="14" /> {{ t('assistantPage.import') }}
        </button>
      </article>
    </section>
  </div>
</template>
