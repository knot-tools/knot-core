<script setup lang="ts">
import { ref, nextTick } from 'vue';
import { Bot, Copy, Import, Loader2, Sparkles, Clipboard, ExternalLink, AlertTriangle } from 'lucide-vue-next';
import { knotApi, type WorkflowDefinition } from '../lib/api';
import {
  normalizeWorkflowImport,
  parseWorkflowImportText,
  extractRepairs,
  WorkflowImportFormatError,
  WorkflowImportLegacyStepsError,
  type RepairEntry,
} from '../lib/normalizeWorkflowImport';
import { buildChatbotFixMessage } from '../lib/chatbotFix';
import { mergeLocalAndRemoteLint } from '../composables/useWorkflowLinter';
import {
  formatValidationIssueMessage,
  validateWorkflow,
  type ValidationIssue,
} from '../lib/validator';
import { useI18n } from 'vue-i18n';

const { t } = useI18n();

const userRequest = ref('');
const prompt = ref('');
const annex = ref<string | null>(null);
const jsonText = ref('');
const loading = ref(false);
const message = ref<string | null>(null);
const messageKind = ref<'success' | 'error' | 'info'>('success');
const preflightBlocked = ref(false);
const preflightMissing = ref<Array<{ id: string; label: string; licenseStatus: string }>>([]);
const importedWorkflowId = ref<number | null>(null);
const unknownTypes = ref<string[]>([]);
const lintIssues = ref<ValidationIssue[]>([]);
const autoRepairs = ref<RepairEntry[]>([]);
const chatbotFixRound = ref(0);
const assistantRoot = ref<HTMLElement | null>(null);

function scrollAssistantToTop() {
  void nextTick(() => {
    assistantRoot.value?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    window.scrollTo({ top: 0, behavior: 'smooth' });
    document.documentElement.scrollTo({ top: 0, behavior: 'smooth' });
  });
}

const baseUrl = (window as { KNOT_BASE_URL?: string }).KNOT_BASE_URL ?? '';

function editorUrl(id: number): string {
  return `${baseUrl}?mode=editor&workflow_id=${id}&layout=1`;
}

function definitionToLintGraph(definition: WorkflowDefinition) {
  const nodes = (definition.nodes ?? []).map((n) => {
    const row = n as Record<string, unknown>;
    return {
      id: String(row.id ?? ''),
      data: {
        type: String(row.type ?? ''),
        config: (row.config as Record<string, unknown>) ?? {},
      },
    };
  });
  const edges = (definition.edges ?? []).map((e) => {
    const row = e as Record<string, unknown>;
    return {
      source: String(row.source ?? ''),
      target: String(row.target ?? ''),
      sourceHandle: (row.sourceHandle as string | null | undefined) ?? null,
    };
  });
  return { nodes, edges };
}

async function runPostImportLint(definition: WorkflowDefinition) {
  const graph = definitionToLintGraph(definition);
  const local = validateWorkflow(graph.nodes, graph.edges);
  let remote: ValidationIssue[] = [];
  try {
    const result = await knotApi.lintWorkflowDefinition(definition);
    remote = (result.issues ?? []) as ValidationIssue[];
  } catch {
    remote = [];
  }
  lintIssues.value = mergeLocalAndRemoteLint(remote, local);
}

async function resolveInstalledSlugs(): Promise<string[]> {
  try {
    const res = await knotApi.connectors();
    return (res.connectors ?? [])
      .map((c) => String(c.metadata?.id ?? '').trim())
      .filter((id) => id.length > 0);
  } catch {
    return [];
  }
}

async function copyFixForChatbot() {
  const incremental = chatbotFixRound.value > 0;
  const installedSlugs = await resolveInstalledSlugs();
  const text = buildChatbotFixMessage(lintIssues.value, jsonText.value, {
    incremental,
    installedSlugs,
  });
  await navigator.clipboard?.writeText(text);
  chatbotFixRound.value += 1;
  message.value = incremental
    ? t('assistantPage.copyFixIncrementalDone')
    : t('assistantPage.copyFixDone');
  messageKind.value = 'success';
}

function marketplaceUrl(): string {
  return `${baseUrl}?mode=marketplace&tab=packs`;
}

function preflightCtaKey(status: string): string {
  if (status === 'expired') return 'assistantPage.preflightCtaRenew';
  if (status === 'tampered' || status === 'invalid') return 'assistantPage.preflightCtaReinstall';
  return 'assistantPage.preflightCtaBuy';
}

async function generatePrompt() {
  loading.value = true;
  preflightBlocked.value = false;
  preflightMissing.value = [];
  try {
    const result = await knotApi.assistantPrompt(userRequest.value);
    prompt.value = result.prompt;
    annex.value = result.annex ?? null;
    message.value = userRequest.value.trim() === ''
      ? t('assistantPage.msgTailoredEmpty')
      : t('assistantPage.msgTailored');
    messageKind.value = 'success';
  } catch (e) {
    const err = e as Error & { code?: string; details?: { preflight?: { missing?: typeof preflightMissing.value } } };
    if (err.code === 'assistant_preflight_blocked') {
      preflightBlocked.value = true;
      preflightMissing.value = err.details?.preflight?.missing ?? [];
      message.value = t('assistantPage.preflightBlocked');
      messageKind.value = 'error';
      return;
    }
    message.value = err.message ?? t('assistantPage.msgGenFailed');
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

async function copyAnnex() {
  if (!annex.value) return;
  await navigator.clipboard?.writeText(annex.value);
  message.value = t('assistantPage.msgAnnexCopied');
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

function collectUnknownTypes(definition: { nodes?: Array<{ type?: string }> }, known: Set<string>): string[] {
  const nodes = definition.nodes ?? [];
  const unknown = new Set<string>();
  for (const node of nodes) {
    const type = node.type ?? '';
    if (type && !known.has(type) && !type.startsWith('ext:')) {
      unknown.add(type);
    }
  }
  return [...unknown];
}

async function importWorkflow(force = false) {
  if (!force) {
    unknownTypes.value = [];
  }
  importedWorkflowId.value = null;
  lintIssues.value = [];
  autoRepairs.value = [];
  chatbotFixRound.value = 0;
  try {
    const parsed = parseWorkflowImportText(jsonText.value);
    const payload = normalizeWorkflowImport(parsed, {
      label: t('workflowsPage.importedWorkflowDefault'),
      status: 'draft',
    });

    autoRepairs.value = extractRepairs(payload.definition);

    const connectors = await knotApi.connectors();
    const known = new Set(connectors.connectors.map((c) => String(c.metadata.id)));
    const unknown = collectUnknownTypes(payload.definition ?? { nodes: [] }, known);
    if (unknown.length > 0 && !force) {
      unknownTypes.value = unknown;
      message.value = t('assistantPage.errUnknownConnectors', { types: unknown.join(', ') });
      messageKind.value = 'error';
      return;
    }

    const result = await knotApi.saveWorkflow(payload);
    if (!result?.workflow?.id) {
      throw new Error(t('assistantPage.msgImportNoWorkflow'));
    }
    importedWorkflowId.value = result.workflow.id;
    if (payload.definition) {
      await runPostImportLint(payload.definition);
    }
    const warnCount = lintIssues.value.filter((i) => i.severity === 'warning').length;
    const repairCount = autoRepairs.value.length;
    if (repairCount > 0 && warnCount > 0) {
      message.value = t('assistantPage.msgImportSuccessWithRepairs', { id: result.workflow.id, repairs: repairCount, warnings: warnCount });
    } else if (repairCount > 0) {
      message.value = t('assistantPage.msgImportSuccessRepaired', { id: result.workflow.id, count: repairCount });
    } else if (warnCount > 0) {
      message.value = t('assistantPage.msgImportSuccessWithWarnings', { id: result.workflow.id, count: warnCount });
    } else {
      message.value = t('assistantPage.msgImportSuccess', { id: result.workflow.id });
    }
    messageKind.value = warnCount > 0 ? 'info' : 'success';
    scrollAssistantToTop();
  } catch (e) {
    if (e instanceof WorkflowImportLegacyStepsError) {
      message.value = t('assistantPage.errLegacySteps');
    } else if (e instanceof WorkflowImportFormatError) {
      message.value = t('assistantPage.errWrongFormat');
    } else if (e instanceof SyntaxError) {
      message.value = t('assistantPage.errInvalidJson');
    } else {
      message.value = (e as Error).message ?? t('assistantPage.msgImportFailed');
    }
    messageKind.value = 'error';
  }
}

async function importAnywayConfirm() {
  await importWorkflow(true);
}
</script>

<template>
  <div ref="assistantRoot" class="k-flex k-flex-col k-h-full k-min-h-0 k-px-6 k-py-4 k-gap-4">
    <header class="k-flex k-items-center k-gap-3 k-shrink-0">
      <div class="k-h-10 k-w-10 k-rounded-knot-sm k-bg-knot-primary-soft k-text-knot-primary k-flex k-items-center k-justify-center">
        <Bot :size="20" />
      </div>
      <div>
        <h1 class="k-text-2xl k-font-bold k-text-knot-text">{{ t('assistantPage.title') }}</h1>
        <p class="k-text-sm k-text-knot-text-muted">{{ t('assistantPage.subtitle') }}</p>
      </div>
    </header>

    <div
      v-if="preflightBlocked"
      class="k-shrink-0 k-bg-knot-warning-soft k-border k-border-knot-warning k-text-knot-warning k-px-4 k-py-3 k-rounded-knot-sm k-text-sm k-space-y-2"
    >
      <div class="k-flex k-items-start k-gap-2 k-font-semibold">
        <AlertTriangle :size="16" class="k-shrink-0 k-mt-0.5" />
        <span>{{ t('assistantPage.preflightBlocked') }}</span>
      </div>
      <ul class="k-list-disc k-pl-5">
        <li v-for="item in preflightMissing" :key="item.id">
          {{ item.label }} ({{ item.id }})
        </li>
      </ul>
      <a
        :href="marketplaceUrl()"
        class="k-inline-flex k-items-center k-gap-1 k-text-sm k-font-semibold k-text-knot-primary hover:k-underline"
      >
        {{ t(preflightCtaKey(preflightMissing[0]?.licenseStatus ?? 'missing')) }}
        <ExternalLink :size="12" />
      </a>
    </div>

    <div
      v-if="message"
      :class="[
        'k-shrink-0 k-px-4 k-py-3 k-rounded-knot-sm k-text-sm',
        messageKind === 'success'
          ? 'k-bg-knot-success-soft k-text-knot-success'
          : messageKind === 'info'
            ? 'k-bg-knot-primary-soft k-text-knot-primary'
            : 'k-bg-knot-danger-soft k-text-knot-danger'
      ]"
    >
      <p>{{ message }}</p>
      <div v-if="importedWorkflowId" class="k-mt-2 k-flex k-flex-wrap k-gap-2">
        <a
          :href="editorUrl(importedWorkflowId)"
          class="k-inline-flex k-items-center k-gap-1 k-px-3 k-py-1.5 k-rounded-knot-sm k-bg-knot-primary k-text-white k-text-sm k-font-semibold k-no-underline"
        >
          <ExternalLink :size="14" />
          {{ t('assistantPage.openEditor') }}
        </a>
        <span class="k-text-xs k-self-center">{{ t('assistantPage.postImportHint') }}</span>
        <button
          v-if="lintIssues.length"
          type="button"
          class="k-inline-flex k-items-center k-gap-1 k-px-3 k-py-1.5 k-rounded-knot-sm k-border k-border-knot-border k-text-sm k-font-semibold"
          @click="() => void copyFixForChatbot()"
        >
          <Copy :size="14" />
          {{ t('assistantPage.copyFixForChatbot') }}
        </button>
      </div>
      <div v-if="lintIssues.length" class="k-mt-3 k-space-y-1">
        <p class="k-font-semibold k-text-xs">{{ t('assistantPage.lintIssuesTitle') }}</p>
        <ul class="k-list-disc k-pl-5 k-text-xs k-space-y-0.5">
          <li v-for="(issue, idx) in lintIssues" :key="idx">
            {{ formatValidationIssueMessage(issue) }}
          </li>
        </ul>
      </div>
      <div v-if="autoRepairs.length" class="k-mt-3 k-space-y-1">
        <p class="k-font-semibold k-text-xs k-text-knot-success">{{ t('assistantPage.autoRepairsTitle') }}</p>
        <ul class="k-list-disc k-pl-5 k-text-xs k-space-y-0.5 k-text-knot-text-muted">
          <li v-for="(r, idx) in autoRepairs" :key="idx">{{ r.detail }}</li>
        </ul>
      </div>
      <div v-if="unknownTypes.length" class="k-mt-2">
        <button
          type="button"
          class="k-text-xs k-font-semibold k-underline"
          @click="importAnywayConfirm"
        >
          {{ t('assistantPage.importAnyway') }}
        </button>
      </div>
    </div>

    <article class="k-shrink-0 k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-4 k-space-y-3">
      <div class="k-flex k-items-center k-justify-between k-gap-3">
        <div class="k-flex k-items-center k-gap-2">
          <Sparkles :size="16" class="k-text-knot-primary" />
          <h2 class="k-font-semibold k-text-knot-text">{{ t('assistantPage.step0Title') }}</h2>
        </div>
        <span class="k-text-xs k-text-knot-text-muted">{{ t('assistantPage.step0Optional') }}</span>
      </div>
      <textarea
        v-model="userRequest"
        rows="6"
        :placeholder="t('assistantPage.step0Placeholder')"
        class="knot-assistant-field k-w-full k-min-h-[8rem] k-text-sm k-bg-knot-bg k-border k-border-knot-border k-rounded-knot-sm k-p-3 k-text-knot-text k-resize-y"
      ></textarea>
    </article>

    <section class="k-shrink-0 k-grid lg:k-grid-cols-2 k-gap-4">
      <article class="k-flex k-flex-col k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-4 k-gap-3">
        <div class="k-flex k-items-center k-justify-between k-flex-wrap k-gap-2">
          <h2 class="k-font-semibold k-text-knot-text">{{ t('assistantPage.step1Title') }}</h2>
          <div class="k-flex k-gap-2 k-flex-wrap">
            <button
              @click="generatePrompt"
              :disabled="loading || preflightBlocked"
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
            <button
              v-if="annex"
              @click="copyAnnex"
              class="k-inline-flex k-items-center k-gap-1 k-px-3 k-py-1.5 k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-sm"
            >
              <Copy :size="14" /> {{ t('assistantPage.copyAnnex') }}
            </button>
          </div>
        </div>
        <textarea
          v-model="prompt"
          :placeholder="t('assistantPage.promptPlaceholder')"
          class="knot-assistant-scroll k-w-full k-text-xs k-font-mono k-bg-knot-bg k-border k-border-knot-border k-rounded-knot-sm k-p-3 k-text-knot-text"
        ></textarea>
      </article>

      <article class="k-flex k-flex-col k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-md k-p-4 k-gap-3">
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
          :placeholder="t('assistantPage.jsonPlaceholder')"
          class="knot-assistant-scroll k-w-full k-text-xs k-font-mono k-bg-knot-bg k-border k-border-knot-border k-rounded-knot-sm k-p-3 k-text-knot-text"
        ></textarea>
        <button
          @click="() => void importWorkflow()"
          :disabled="!jsonText.trim()"
          class="k-shrink-0 k-inline-flex k-items-center k-gap-2 k-px-3 k-py-2 k-rounded-knot-sm k-bg-knot-success k-text-white k-text-sm k-font-semibold disabled:k-opacity-60"
        >
          <Import :size="14" /> {{ t('assistantPage.import') }}
        </button>
      </article>
    </section>
  </div>
</template>

<style scoped>
.knot-assistant-scroll {
  min-height: 14rem;
  max-height: min(22rem, 42vh);
  overflow-y: auto;
  resize: vertical;
}
</style>
