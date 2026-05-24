<!--
  OnboardingWizard — first-run experience for Knot admin.
  Copyright (C) 2026 Knot — GPL-3.0-or-later

  Plan post-Phase 5 chantier 7.A. Eight-step onboarding: intro → prerequisites
  (Dolibarr cron module, PHP extensions, CRON_KEY) → Knot jobs → hosting cron URL
  → rights → encryption → SMTP → starters. Runtime needs PHP + Dolibarr only.

  Design inspiration: knot.tools landing — dark hero, gradient
  accent, calm typography, generous whitespace, single primary CTA
  per step. Respects prefers-reduced-motion (no shimmer in that
  branch).
-->
<script setup lang="ts">
import { computed, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import {
  CheckCircle2,
  AlertTriangle,
  ChevronRight,
  ChevronLeft,
  Loader2,
  Sparkles,
  ListChecks,
  ClipboardCheck,
  CalendarClock,
  Server,
  ShieldCheck,
  KeyRound,
  Mail,
  X,
  PartyPopper,
  Copy,
} from 'lucide-vue-next';

interface OnboardingState {
  completed: boolean;
  isAdmin: boolean;
  cron: {
    ok: boolean;
    globalEnabled: boolean;
    jobsEnabled: boolean;
    recentRun: boolean;
    knotJobsRegistered: number;
    knotJobsActive: number;
    webUrl: string | null;
    userLogin: string | null;
  };
  prerequisites: {
    dolibarrCronModule: boolean;
    phpExtensionsOk: boolean;
    phpExtensionsMissing: string[];
    cronKeyConfigured: boolean | null;
  };
  rights: { count: number; expected: number };
  encryption: { ok: boolean; fingerprint: string | null };
  smtp: { configured: boolean; host: string | null };
  starter: { available: boolean; count: number };
}

const props = defineProps<{
  forceOpen?: boolean;
}>();

const emit = defineEmits<{ (e: 'close'): void }>();

const { t } = useI18n();

const visible = ref(false);
const loading = ref(false);
const submitting = ref(false);
const error = ref<string | null>(null);
const step = ref(0);
const state = ref<OnboardingState | null>(null);
const importedCount = ref<number | null>(null);

const apiBase = computed(() => {
  const win = window as unknown as { KNOT_API_BASE?: string };
  return (win.KNOT_API_BASE ?? '').replace(/\/+$/, '');
});

const csrfToken = computed(() => {
  const win = window as unknown as { KNOT_CSRF_TOKEN?: string };
  return win.KNOT_CSRF_TOKEN ?? '';
});

// Dolibarr permissions admin link. Best-effort: matches the path
// used by Dolibarr V20+. Falls back gracefully if the host server
// rewrites paths.
const permissionsUrl = computed(() => {
  const win = window as unknown as { KNOT_BASE_URL?: string };
  const root = win.KNOT_BASE_URL?.split('/custom/')[0] ?? '';
  return `${root}/user/perms.php?id=`;
});

const dolibarrCronListUrl = computed(() => {
  const win = window as unknown as { KNOT_BASE_URL?: string };
  const root = win.KNOT_BASE_URL?.split('/custom/')[0] ?? '';
  return `${root}/cron/list.php`;
});

const dolibarrModulesUrl = computed(() => {
  const win = window as unknown as { KNOT_BASE_URL?: string };
  const root = win.KNOT_BASE_URL?.split('/custom/')[0] ?? '';
  return `${root}/admin/modules.php`;
});

const stepDefs = computed(() => [
  {
    key: 'postsetup' as const,
    label: t('onboarding.steps.postsetup'),
    icon: ListChecks,
  },
  {
    key: 'prereq' as const,
    label: t('onboarding.steps.prereq'),
    icon: ClipboardCheck,
  },
  {
    key: 'knot_jobs' as const,
    label: t('onboarding.steps.knot_jobs'),
    icon: CalendarClock,
  },
  {
    key: 'cron' as const,
    label: t('onboarding.steps.cron'),
    icon: Server,
  },
  {
    key: 'rights' as const,
    label: t('onboarding.steps.rights'),
    icon: ShieldCheck,
  },
  {
    key: 'encryption' as const,
    label: t('onboarding.steps.encryption'),
    icon: KeyRound,
  },
  {
    key: 'smtp' as const,
    label: t('onboarding.steps.smtp'),
    icon: Mail,
  },
  {
    key: 'starters' as const,
    label: t('onboarding.steps.starters'),
    icon: Sparkles,
  },
]);

const rightsRows = computed(() => [
  { code: 'knot.workflow.read', label: t('onboarding.step4.rightWorkflowRead') },
  { code: 'knot.workflow.write', label: t('onboarding.step4.rightWorkflowWrite') },
  { code: 'knot.workflow.execute', label: t('onboarding.step4.rightWorkflowExecute') },
  { code: 'knot.credentials.write', label: t('onboarding.step4.rightCredentialsWrite') },
  { code: 'knot.admin', label: t('onboarding.step4.rightAdmin') },
]);

const starterCards = computed(() => [
  {
    name: t('marketplace.starterHelloWorldName'),
    desc: t('marketplace.starterHelloWorldDesc'),
    emoji: '👋',
  },
  {
    name: t('marketplace.starterInvoiceReminderName'),
    desc: t('marketplace.starterInvoiceReminderDesc'),
    emoji: '💸',
  },
  {
    name: t('marketplace.starterWebhookTaskName'),
    desc: t('marketplace.starterWebhookTaskDesc'),
    emoji: '🪝',
  },
  {
    name: t('marketplace.starterProposalInvoiceName'),
    desc: t('marketplace.starterProposalInvoiceDesc'),
    emoji: '📄',
  },
  {
    name: t('marketplace.starterBackupName'),
    desc: t('marketplace.starterBackupDesc'),
    emoji: '💾',
  },
]);

const STEP_TOTAL = 8;

async function fetchState() {
  loading.value = true;
  error.value = null;
  try {
    const res = await fetch(`${apiBase.value}/onboarding.php`, {
      credentials: 'same-origin',
      headers: { Accept: 'application/json' },
    });
    const json = await res.json();
    if (!res.ok || json?.error) {
      throw new Error(json?.message ?? t('onboarding.errorLoadFailed'));
    }
    state.value = json.data ?? json;
  } catch (e) {
    error.value = e instanceof Error ? e.message : t('onboarding.errorLoadGeneric');
  } finally {
    loading.value = false;
  }
}

async function postAction(action: string, body: Record<string, unknown> | null = null) {
  submitting.value = true;
  error.value = null;
  try {
    const res = await fetch(`${apiBase.value}/onboarding.php?action=${action}`, {
      method: 'POST',
      credentials: 'same-origin',
      headers: {
        'Content-Type': 'application/json',
        'X-Csrf-Token': csrfToken.value,
        Accept: 'application/json',
      },
      body: body ? JSON.stringify(body) : '{}',
    });
    const json = await res.json();
    if (!res.ok || json?.error) {
      throw new Error(json?.message ?? t('onboarding.errorActionFailed'));
    }
    return json.data ?? json;
  } catch (e) {
    error.value = e instanceof Error ? e.message : t('onboarding.errorActionGeneric');
    throw e;
  } finally {
    submitting.value = false;
  }
}

async function complete() {
  await postAction('complete');
  visible.value = false;
  emit('close');
  // Open Knot setup (engine, hosting cron URL, health) after first-run UX.
  const win = window as unknown as { KNOT_SETUP_URL?: string };
  const setupUrl = (win.KNOT_SETUP_URL ?? '').trim();
  setTimeout(() => {
    if (setupUrl) {
      const topWin = window.top;
      try {
        (topWin ?? window).location.assign(setupUrl);
      } catch {
        window.location.assign(setupUrl);
      }
    } else {
      window.location.reload();
    }
  }, 200);
}

async function importStarters() {
  const result = await postAction('import_starters');
  importedCount.value = Array.isArray(result.imported) ? result.imported.length : 0;
}

async function enableKnotCronJobs() {
  await postAction('enable_knot_cron');
  await fetchState();
}

function next() {
  if (step.value < stepDefs.value.length - 1) step.value += 1;
  else complete();
}

function prev() {
  if (step.value > 0) step.value -= 1;
}

function close() {
  visible.value = false;
  emit('close');
}

async function copyCronUrl() {
  const url = state.value?.cron.webUrl;
  if (!url) {
    return;
  }
  try {
    await navigator.clipboard.writeText(url);
  } catch {
    try {
      const ta = document.createElement('textarea');
      ta.value = url;
      ta.style.position = 'fixed';
      ta.style.left = '-9999px';
      document.body.appendChild(ta);
      ta.select();
      document.execCommand('copy');
      document.body.removeChild(ta);
    } catch {
      /* ignore */
    }
  }
}

onMounted(async () => {
  const win = window as unknown as { KNOT_FIRSTRUN_COMPLETED?: boolean; KNOT_USER_ADMIN?: boolean };
  // Auto-show for eligible users until KNOT_FIRSTRUN_COMPLETED is set server-side.
  // Trust GET /api/onboarding.php for `completed` so we do not flash the overlay
  // when the window bootstrap flag is stale. Manual launches via forceOpen always
  // run (e.g. re-open from Help).
  const auto = !win.KNOT_FIRSTRUN_COMPLETED && Boolean(win.KNOT_USER_ADMIN);
  if (!props.forceOpen && !auto) {
    return;
  }
  await fetchState();
  if (!props.forceOpen && state.value?.completed) {
    return;
  }
  visible.value = true;
});

const cronOk = computed(() => state.value?.cron.ok ?? false);
const knotJobsEnabled = computed(() => state.value?.cron.jobsEnabled ?? false);
const knotSetupUrl = computed(() => {
  const win = window as unknown as { KNOT_SETUP_URL?: string };
  return (win.KNOT_SETUP_URL ?? '').trim();
});

const prereqHardOk = computed(() => {
  const p = state.value?.prerequisites;
  if (!p) return false;
  return p.dolibarrCronModule && p.phpExtensionsOk;
});

const rightsOk = computed(() => (state.value?.rights.count ?? 0) >= (state.value?.rights.expected ?? 5));
const encryptionOk = computed(() => state.value?.encryption.ok ?? false);
const smtpOk = computed(() => state.value?.smtp.configured ?? false);
const starterCount = computed(() => state.value?.starter.count ?? 0);
</script>

<template>
  <Teleport to="body">
    <transition
      enter-active-class="k-transition-opacity k-duration-300 k-ease-out"
      leave-active-class="k-transition-opacity k-duration-200 k-ease-in"
      enter-from-class="k-opacity-0"
      leave-to-class="k-opacity-0"
    >
      <!-- z-[100002]: above Dolibarr top/side chrome (often 1e4+) and Knot editor modals -->
      <div
        v-if="visible"
        class="knot-onboarding-scroll k-fixed k-inset-0 k-z-[100002] k-overflow-y-auto k-overscroll-contain"
        role="dialog"
        aria-modal="true"
        aria-labelledby="knot-onboarding-title"
      >
        <!-- Backdrops stay fixed so they cover the viewport while the panel scrolls -->
        <div
          class="k-fixed k-inset-0 k-bg-knot-bg/95 k-backdrop-blur-knot"
          @click="close"
        />
        <div
          class="k-fixed k-inset-0 k-pointer-events-none k-opacity-80 k-bg-knot-mesh"
        />
        <div
          class="k-fixed k-inset-0 k-pointer-events-none k-opacity-30 k-bg-knot-noise k-mix-blend-overlay"
        />

        <div
          class="k-relative k-z-10 k-flex k-min-h-full k-items-center k-justify-center k-p-3 sm:k-p-4 k-py-6 sm:k-py-8"
        >
        <!-- Panel: max viewport height, column flex so only the body scrolls -->
        <div
          class="k-relative k-my-auto k-flex k-h-auto k-w-full k-max-w-2xl k-max-h-[min(92dvh,calc(100dvh-1.5rem))] k-min-h-0 k-flex-col k-overflow-hidden k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface/95 k-backdrop-blur-knot k-shadow-knot-xl"
        >
          <!-- Stepper rail + close (close beside rail so last step emoji is not covered) -->
          <div class="k-shrink-0 k-flex k-items-start k-gap-2 k-pl-6 k-pr-3 k-pt-6 k-pb-3 sm:k-gap-3">
            <div class="k-min-w-0 k-flex-1">
              <div class="k-flex k-items-center k-justify-between k-gap-2">
              <div
                v-for="(def, idx) in stepDefs"
                :key="def.key"
                class="k-flex k-items-center k-flex-1 k-gap-2 k-min-w-0"
              >
                <div
                  :class="[
                    'k-w-8 k-h-8 k-rounded-knot-pill k-flex k-items-center k-justify-center k-text-xs k-font-bold k-shrink-0 k-transition-all k-duration-knot k-ease-knot',
                    idx === step
                      ? 'k-bg-knot-hero k-text-white k-shadow-[0_4px_14px_rgba(99,102,241,0.4)] k-scale-110'
                      : idx < step
                        ? 'k-bg-knot-success k-text-white'
                        : 'k-bg-knot-surface-soft k-text-knot-text-soft k-border k-border-knot-border-strong'
                  ]"
                >
                  <CheckCircle2 v-if="idx < step" :size="14" />
                  <component v-else :is="def.icon" :size="13" />
                </div>
                <span
                  :class="[
                    'k-text-xs k-font-semibold k-truncate k-hidden md:k-inline',
                    idx === step ? 'k-text-knot-text' : 'k-text-knot-text-soft'
                  ]"
                >
                  {{ def.label }}
                </span>
                <div
                  v-if="idx < stepDefs.length - 1"
                  :class="[
                    'k-flex-1 k-h-px k-mx-1',
                    idx < step ? 'k-bg-knot-success/60' : 'k-bg-knot-border'
                  ]"
                />
              </div>
              </div>
            </div>
            <button
              type="button"
              @click="close"
              class="k-shrink-0 k-mt-0.5 k-flex k-h-8 k-w-8 k-items-center k-justify-center k-rounded-knot-pill k-border k-border-knot-border-strong k-bg-knot-surface-soft k-text-knot-text-soft k-transition-colors hover:k-border-knot-primary hover:k-text-knot-text"
              :aria-label="t('onboarding.closeWizardAria')"
            >
              <X :size="14" />
            </button>
          </div>

          <div class="knot-onboarding-scroll k-flex k-min-h-0 k-flex-1 k-flex-col k-overflow-y-auto k-overscroll-contain k-px-8 k-pt-2 k-pb-6">
            <div v-if="error" class="k-mb-4 k-px-4 k-py-2 k-bg-knot-danger-soft k-text-knot-danger k-rounded-knot-sm k-text-sm k-flex k-items-center k-gap-2">
              <AlertTriangle :size="14" />
              {{ error }}
            </div>

            <div v-if="loading" class="k-flex-1 k-flex k-items-center k-justify-center k-text-knot-text-soft k-gap-2">
              <Loader2 :size="16" class="k-animate-spin" /> {{ t('onboarding.loading') }}
            </div>

            <!-- Step 0 — Post-wizard checklist (guide only) -->
            <div v-else-if="step === 0" class="k-flex-1 k-flex k-flex-col k-gap-5">
              <header>
                <p class="k-text-xs k-uppercase k-tracking-wider k-text-knot-text-soft k-font-semibold k-mb-2">
                  {{ t('onboarding.stepBadge', { n: 1, total: STEP_TOTAL }) }}
                </p>
                <h2 id="knot-onboarding-title" class="k-text-2xl k-font-bold k-text-knot-text k-leading-tight">
                  {{ t('onboarding.step0.heroTitle') }}
                  <span class="k-block k-bg-clip-text k-text-transparent k-bg-knot-hero">{{ t('onboarding.step0.heroAccent') }}</span>
                </h2>
                <p class="k-mt-3 k-text-sm k-text-knot-text-muted k-leading-relaxed">
                  {{ t('onboarding.step0.lead') }}
                </p>
                <p class="k-mt-3 k-rounded-knot-sm k-border k-border-knot-border k-bg-knot-bg/40 k-p-3 k-text-xs k-text-knot-text-muted">
                  <span class="k-mr-1" aria-hidden="true">ℹ️</span>
                  {{ t('onboarding.step0.serverHint') }}
                </p>
              </header>

              <div
                class="k-rounded-knot-md k-p-5 k-border k-border-knot-border k-bg-knot-surface-soft k-text-sm k-text-knot-text-muted k-leading-relaxed"
              >
                <ol class="k-list-decimal k-pl-5 k-space-y-4 k-text-knot-text">
                  <li>
                    <strong class="k-text-knot-text">{{ t('onboarding.step0.ol1Title') }}</strong>
                    <p class="k-mt-1 k-font-normal">
                      {{ t('onboarding.step0.ol1Desc') }}
                    </p>
                  </li>
                  <li>
                    <strong class="k-text-knot-text">{{ t('onboarding.step0.ol2Title') }}</strong>
                    <p class="k-mt-1 k-font-normal">
                      {{ t('onboarding.step0.ol2Desc') }}
                    </p>
                  </li>
                  <li>
                    <strong class="k-text-knot-text">{{ t('onboarding.step0.ol3Title') }}</strong>
                    <p class="k-mt-1 k-font-normal">
                      {{ t('onboarding.step0.ol3Desc') }}
                    </p>
                  </li>
                  <li>
                    <strong class="k-text-knot-text">{{ t('onboarding.step0.ol4Title') }}</strong>
                    <p class="k-mt-1 k-font-normal">
                      {{ t('onboarding.step0.ol4Desc') }}
                    </p>
                  </li>
                </ol>
              </div>
            </div>

            <!-- Step 1 — Prerequisites (Dolibarr cron module, PHP, CRON_KEY) -->
            <div v-else-if="step === 1" class="k-flex-1 k-flex k-flex-col k-gap-5">
              <header>
                <p class="k-text-xs k-uppercase k-tracking-wider k-text-knot-text-soft k-font-semibold k-mb-2">
                  {{ t('onboarding.stepBadge', { n: 2, total: STEP_TOTAL }) }}
                </p>
                <h2 class="k-text-2xl k-font-bold k-text-knot-text k-leading-tight">
                  {{ t('onboarding.step1.title') }}
                </h2>
                <p class="k-mt-3 k-text-sm k-text-knot-text-muted k-leading-relaxed">
                  {{ t('onboarding.step1.intro') }}
                </p>
              </header>

              <div class="k-space-y-3">
                <div
                  :class="[
                    'k-rounded-knot-md k-border k-p-4 k-text-sm',
                    state?.prerequisites?.dolibarrCronModule
                      ? 'k-border-knot-success/30 k-bg-knot-success-soft'
                      : 'k-border-knot-warning/40 k-bg-knot-warning-soft',
                  ]"
                >
                  <div class="k-flex k-items-start k-gap-3">
                    <CheckCircle2
                      v-if="state?.prerequisites?.dolibarrCronModule"
                      :size="18"
                      class="k-shrink-0 k-text-knot-success"
                    />
                    <AlertTriangle v-else :size="18" class="k-shrink-0 k-text-knot-warning" />
                    <div>
                      <p class="k-font-semibold k-text-knot-text">
                        {{ t('onboarding.step1.cronModuleTitle') }}
                      </p>
                      <p class="k-mt-1 k-text-knot-text-muted">
                        {{ t('onboarding.step1.cronModuleLead') }}
                        <a
                          :href="dolibarrModulesUrl"
                          target="_blank"
                          rel="noopener"
                          class="k-ml-1 k-font-semibold k-text-knot-primary hover:k-underline"
                        >
                          {{ t('onboarding.step1.cronModuleLink') }}
                        </a>
                      </p>
                    </div>
                  </div>
                </div>

                <div
                  :class="[
                    'k-rounded-knot-md k-border k-p-4 k-text-sm',
                    state?.prerequisites?.phpExtensionsOk
                      ? 'k-border-knot-success/30 k-bg-knot-success-soft'
                      : 'k-border-knot-danger/30 k-bg-knot-danger-soft',
                  ]"
                >
                  <div class="k-flex k-items-start k-gap-3">
                    <CheckCircle2
                      v-if="state?.prerequisites?.phpExtensionsOk"
                      :size="18"
                      class="k-shrink-0 k-text-knot-success"
                    />
                    <AlertTriangle v-else :size="18" class="k-shrink-0 k-text-knot-danger" />
                    <div>
                      <p class="k-font-semibold k-text-knot-text">
                        {{ t('onboarding.step1.phpTitle') }}
                      </p>
                      <p v-if="state?.prerequisites?.phpExtensionsOk" class="k-mt-1 k-text-knot-text-muted">
                        {{ t('onboarding.step1.phpOk') }}
                      </p>
                      <p v-else class="k-mt-1 k-text-knot-danger">
                        {{ t('onboarding.step1.phpMissingLead') }}
                        <code class="k-font-mono k-text-[11px]">{{ (state?.prerequisites?.phpExtensionsMissing ?? []).join(', ') }}</code>
                        {{ t('onboarding.step1.phpMissingSuffix') }}
                      </p>
                    </div>
                  </div>
                </div>

                <div
                  :class="[
                    'k-rounded-knot-md k-border k-p-4 k-text-sm',
                    state?.prerequisites?.cronKeyConfigured === false
                      ? 'k-border-knot-warning/40 k-bg-knot-warning-soft'
                      : 'k-border-knot-border k-bg-knot-surface-soft',
                  ]"
                >
                  <div class="k-flex k-items-start k-gap-3">
                    <KeyRound
                      :size="18"
                      :class="[
                        'k-shrink-0',
                        state?.prerequisites?.cronKeyConfigured === false ? 'k-text-knot-warning' : 'k-text-knot-primary',
                      ]"
                    />
                    <div>
                      <p class="k-font-semibold k-text-knot-text">
                        {{ t('onboarding.step1.cronKeyTitle') }}
                      </p>
                      <p v-if="state?.prerequisites?.cronKeyConfigured === null" class="k-mt-1 k-text-knot-text-muted">
                        {{ t('onboarding.step1.cronKeyUnknown') }}
                      </p>
                      <p v-else-if="state?.prerequisites?.cronKeyConfigured" class="k-mt-1 k-text-knot-text-muted">
                        {{ t('onboarding.step1.cronKeyOk') }}
                      </p>
                      <p v-else class="k-mt-1 k-text-knot-text-muted">
                        {{ t('onboarding.step1.cronKeyMissing') }}
                      </p>
                    </div>
                  </div>
                </div>
              </div>

              <div
                v-if="!prereqHardOk"
                class="k-rounded-knot-sm k-border k-border-knot-warning/30 k-bg-knot-warning-soft/50 k-p-3 k-text-xs k-text-knot-text"
              >
                {{ t('onboarding.step1.fixHint') }}
              </div>
            </div>

            <!-- Step 2 — Enable Knot rows in Dolibarr cron -->
            <div v-else-if="step === 2" class="k-flex-1 k-flex k-flex-col k-gap-5">
              <header>
                <p class="k-text-xs k-uppercase k-tracking-wider k-text-knot-text-soft k-font-semibold k-mb-2">
                  {{ t('onboarding.stepBadge', { n: 3, total: STEP_TOTAL }) }}
                </p>
                <h2 class="k-text-2xl k-font-bold k-text-knot-text k-leading-tight">
                  {{ t('onboarding.step2.title') }}
                </h2>
                <p class="k-mt-3 k-text-sm k-text-knot-text-muted k-leading-relaxed">
                  {{ t('onboarding.step2.intro') }}
                </p>
              </header>

              <div
                v-if="state?.cron.globalEnabled === false"
                class="k-rounded-knot-md k-border k-border-knot-warning/40 k-bg-knot-warning-soft k-p-4 k-text-sm k-text-knot-text"
              >
                <p class="k-flex k-items-start k-gap-2">
                  <AlertTriangle :size="18" class="k-shrink-0 k-text-knot-warning" />
                  <span>
                    {{ t('onboarding.step2.globalDisabledWarn') }}
                  </span>
                </p>
              </div>

              <div
                v-else-if="(state?.cron.knotJobsRegistered ?? 0) === 0"
                class="k-rounded-knot-md k-border k-border-knot-border k-bg-knot-surface-soft k-p-4 k-text-sm k-text-knot-text-muted"
              >
                <p class="k-mb-3">
                  {{ t('onboarding.step2.noneRegisteredLead') }}
                </p>
                <a
                  v-if="knotSetupUrl"
                  :href="knotSetupUrl"
                  class="k-inline-flex k-items-center k-gap-1 k-text-sm k-font-semibold k-text-knot-primary hover:k-underline"
                >
                  {{ t('onboarding.step2.openSetup') }} <ChevronRight :size="14" />
                </a>
              </div>

              <div v-else class="k-space-y-4">
                <div
                  :class="[
                    'k-rounded-knot-md k-border k-p-4 k-text-sm k-flex k-flex-col sm:k-flex-row sm:k-items-center k-gap-3',
                    knotJobsEnabled
                      ? 'k-bg-knot-success-soft k-border-knot-success/30'
                      : 'k-bg-knot-warning-soft k-border-knot-warning/30',
                  ]"
                >
                  <div class="k-flex k-items-center k-gap-2 k-shrink-0">
                    <CheckCircle2 v-if="knotJobsEnabled" :size="18" class="k-text-knot-success" />
                    <AlertTriangle v-else :size="18" class="k-text-knot-warning" />
                    <span class="k-font-semibold k-text-knot-text">
                      {{ t('onboarding.step2.jobsCounts', { active: state?.cron.knotJobsActive ?? 0, registered: state?.cron.knotJobsRegistered ?? 0 }) }}
                    </span>
                  </div>
                  <p class="k-flex-1 k-text-xs sm:k-text-sm k-text-knot-text-muted">
                    <template v-if="knotJobsEnabled">
                      {{ t('onboarding.step2.jobsEnabledHint') }}
                    </template>
                    <template v-else>
                      {{ t('onboarding.step2.jobsDisabledHint') }}
                    </template>
                  </p>
                </div>

                <div class="k-flex k-flex-wrap k-items-center k-gap-2">
                  <button
                    type="button"
                    :disabled="submitting || knotJobsEnabled"
                    @click="enableKnotCronJobs"
                    class="k-inline-flex k-items-center k-gap-2 k-rounded-knot-sm k-bg-knot-primary k-px-4 k-py-2.5 k-text-sm k-font-semibold k-text-white hover:k-bg-knot-primary-strong disabled:k-cursor-not-allowed disabled:k-opacity-50"
                  >
                    <Loader2 v-if="submitting" :size="15" class="k-animate-spin" />
                    <template v-else>▶️</template>
                    {{ t('onboarding.step2.enableAll') }}
                  </button>
                </div>
              </div>
            </div>

            <!-- Step 3 — Hosting cron URL -->
            <div v-else-if="step === 3" class="k-flex-1 k-flex k-flex-col k-gap-5">
              <header>
                <p class="k-text-xs k-uppercase k-tracking-wider k-text-knot-text-soft k-font-semibold k-mb-2">
                  {{ t('onboarding.stepBadge', { n: 4, total: STEP_TOTAL }) }}
                </p>
                <h2 class="k-text-2xl k-font-bold k-text-knot-text k-leading-tight">
                  {{ t('onboarding.step3.title') }}
                </h2>
                <p class="k-mt-3 k-text-sm k-text-knot-text-muted k-leading-relaxed">
                  {{ t('onboarding.step3.intro') }}
                </p>
              </header>

              <div
                v-if="state?.prerequisites?.cronKeyConfigured === false"
                class="k-rounded-knot-sm k-border k-border-knot-warning/30 k-bg-knot-warning-soft/50 k-p-3 k-text-xs k-text-knot-text"
              >
                {{ t('onboarding.step3.needCronKey') }}
              </div>

              <div class="k-rounded-knot-md k-p-5 k-border k-border-knot-border k-bg-knot-surface-soft k-text-sm k-text-knot-text-muted k-leading-relaxed k-space-y-4">
                <p class="k-text-knot-text k-font-semibold">{{ t('onboarding.step3.urlHeading') }}</p>
                <div v-if="state?.cron.webUrl" class="k-space-y-3">
                  <p>
                    {{ t('onboarding.step3.urlIntro') }}
                  </p>
                  <div
                    v-if="state.cron.userLogin"
                    class="k-text-xs k-text-knot-text-soft"
                  >
                    {{ t('onboarding.step3.cronUserLead') }}
                    <code class="k-font-mono k-text-[11px] k-px-1.5 k-py-0.5 k-bg-knot-bg k-rounded-knot-sm">{{ state.cron.userLogin }}</code>
                  </div>
                  <div class="k-flex k-flex-col sm:k-flex-row k-gap-2">
                    <input
                      type="text"
                      readonly
                      :value="state.cron.webUrl"
                      class="k-flex-1 k-min-w-0 k-px-3 k-py-2 k-rounded-knot-sm k-bg-knot-bg k-border k-border-knot-border k-font-mono k-text-[11px] k-text-knot-text"
                      :aria-label="t('onboarding.cronUrlAria')"
                    />
                    <button
                      type="button"
                      @click="copyCronUrl"
                      class="k-inline-flex k-items-center k-justify-center k-gap-1.5 k-px-3 k-py-2 k-rounded-knot-sm k-bg-knot-primary k-text-white k-text-xs k-font-semibold hover:k-bg-knot-primary-strong"
                    >
                      <Copy :size="13" /> {{ t('onboarding.copyCronUrl') }}
                    </button>
                  </div>
                  <p class="k-text-xs">
                    {{ t('onboarding.step3.crontabExample') }}
                    <code class="k-block k-mt-1 k-p-2 k-bg-knot-bg k-rounded-knot-sm k-font-mono k-text-[10px] k-break-all">
                      <span>*/5 * * * * wget -q -O /dev/null "{{ state.cron.webUrl }}</span>
                    </code>
                    <span class="k-block k-mt-1">{{ t('onboarding.step3.curlEquivalent') }}</span>
                  </p>
                </div>
                <div v-else class="k-space-y-2">
                  <p>
                    {{ t('onboarding.step3.urlMissingExplain') }}
                  </p>
                  <p class="k-text-xs">
                    {{ t('onboarding.step3.urlRecoverHint') }}
                  </p>
                </div>
                <div class="k-pt-2 k-border-t k-border-knot-border">
                  <p class="k-text-knot-text k-font-semibold k-mb-1">{{ t('onboarding.step3.sshCliTitle') }}</p>
                  <p class="k-text-xs">
                    {{ t('onboarding.step3.sshCliBody') }}
                  </p>
                </div>
              </div>

              <div
                :class="[
                  'k-rounded-knot-md k-p-4 k-border k-text-sm k-flex k-flex-col sm:k-flex-row sm:k-items-center k-gap-3',
                  state?.cron.globalEnabled === false || !knotJobsEnabled
                    ? 'k-bg-knot-warning-soft k-border-knot-warning/30 k-text-knot-text'
                    : cronOk
                      ? 'k-bg-knot-success-soft k-border-knot-success/30 k-text-knot-text'
                      : 'k-bg-knot-surface-soft k-border-knot-border k-text-knot-text',
                ]"
              >
                <div class="k-flex k-items-center k-gap-2 k-shrink-0">
                  <CheckCircle2 v-if="cronOk" :size="18" class="k-text-knot-success" />
                  <AlertTriangle
                    v-else-if="state?.cron.globalEnabled === false || !knotJobsEnabled"
                    :size="18"
                    class="k-text-knot-warning"
                  />
                  <Loader2
                    v-else
                    :size="18"
                    class="k-animate-spin k-text-knot-primary"
                  />
                  <span class="k-font-semibold">
                    <template v-if="cronOk">{{ t('onboarding.step3.cronHeartbeatOk') }}</template>
                    <template v-else-if="state?.cron.globalEnabled === false">{{ t('onboarding.step3.cronHeartbeatGlobalBlocked') }}</template>
                    <template v-else-if="!knotJobsEnabled">{{ t('onboarding.step3.cronHeartbeatJobsIncomplete') }}</template>
                    <template v-else>{{ t('onboarding.step3.cronHeartbeatPending') }}</template>
                  </span>
                </div>
                <p class="k-flex-1 k-text-knot-text-muted k-text-xs sm:k-text-sm k-leading-relaxed">
                  <template v-if="state?.cron.globalEnabled === false">
                    {{ t('onboarding.step3.cronExplainGlobalBlocked') }}
                    <button type="button" class="k-underline k-font-semibold k-text-knot-primary" @click="fetchState">{{ t('onboarding.step3.refreshLink') }}</button>.
                  </template>
                  <template v-else-if="!knotJobsEnabled">
                    {{ t('onboarding.step3.cronExplainNeedsJobs') }}
                  </template>
                  <template v-else-if="!cronOk">
                    {{ t('onboarding.step3.cronExplainPending') }}
                    <button type="button" class="k-underline k-font-semibold k-text-knot-primary k-ml-1" @click="fetchState">{{ t('onboarding.step3.refreshButton') }}</button>
                  </template>
                  <template v-else>
                    {{ t('onboarding.step3.cronExplainOk') }}
                  </template>
                </p>
                <a
                  :href="dolibarrCronListUrl"
                  target="_blank"
                  rel="noopener"
                  class="k-inline-flex k-items-center k-gap-1 k-text-xs k-font-semibold k-text-knot-primary hover:k-underline k-shrink-0"
                >
                  {{ t('onboarding.step3.scheduledTasksLink') }} <ChevronRight :size="12" />
                </a>
              </div>
            </div>

            <!-- Step 4 — Permissions -->
            <div v-else-if="step === 4" class="k-flex-1 k-flex k-flex-col k-gap-5">
              <header>
                <p class="k-text-xs k-uppercase k-tracking-wider k-text-knot-text-soft k-font-semibold k-mb-2">
                  {{ t('onboarding.stepBadge', { n: 5, total: STEP_TOTAL }) }}
                </p>
                <h2 class="k-text-2xl k-font-bold k-text-knot-text k-leading-tight">
                  {{ t('onboarding.step4.title') }}
                </h2>
                <p class="k-mt-3 k-text-sm k-text-knot-text-muted k-leading-relaxed">
                  {{ t('onboarding.step4.intro') }}
                </p>
              </header>

              <div class="k-grid k-grid-cols-1 sm:k-grid-cols-2 k-gap-3 k-text-sm">
                <div
                  v-for="r in rightsRows"
                  :key="r.code"
                  class="k-bg-knot-surface-soft k-border k-border-knot-border k-rounded-knot-sm k-px-3 k-py-2 k-flex k-items-center k-gap-2"
                >
                  <ShieldCheck :size="13" class="k-text-knot-primary" />
                  <span class="k-flex-1">
                    <code class="k-font-mono k-text-[11px] k-text-knot-primary k-block">{{ r.code }}</code>
                    <span class="k-text-knot-text-soft k-text-xs">{{ r.label }}</span>
                  </span>
                </div>
              </div>

              <div
                :class="[
                  'k-rounded-knot-md k-p-4 k-border k-text-sm k-flex k-items-center k-gap-3',
                  rightsOk
                    ? 'k-bg-knot-success-soft k-border-knot-success/30 k-text-knot-success'
                    : 'k-bg-knot-warning-soft k-border-knot-warning/30 k-text-knot-warning'
                ]"
              >
                <CheckCircle2 v-if="rightsOk" :size="16" />
                <AlertTriangle v-else :size="16" />
                <span class="k-flex-1">
                  <strong>{{ t('onboarding.step4.rightsCount', { count: state?.rights.count ?? 0, expected: state?.rights.expected ?? 5 }) }}</strong>
                  <span v-if="!rightsOk" class="k-text-knot-text-muted k-ml-1">
                    {{ t('onboarding.step4.rightsRenewHint') }}
                  </span>
                </span>
                <a
                  :href="permissionsUrl"
                  target="_blank"
                  rel="noopener"
                  class="k-text-xs k-font-semibold hover:k-underline k-shrink-0"
                >
                  {{ t('onboarding.step4.assignUsersLink') }}
                </a>
              </div>
            </div>

            <!-- Step 5 — Encryption -->
            <div v-else-if="step === 5" class="k-flex-1 k-flex k-flex-col k-gap-5">
              <header>
                <p class="k-text-xs k-uppercase k-tracking-wider k-text-knot-text-soft k-font-semibold k-mb-2">
                  {{ t('onboarding.stepBadge', { n: 6, total: STEP_TOTAL }) }}
                </p>
                <h2 class="k-text-2xl k-font-bold k-text-knot-text k-leading-tight">
                  {{ t('onboarding.step5.title') }}
                </h2>
                <p class="k-mt-3 k-text-sm k-text-knot-text-muted k-leading-relaxed">
                  {{ t('onboarding.step5.intro') }}
                </p>
              </header>

              <div
                :class="[
                  'k-rounded-knot-md k-p-5 k-border',
                  encryptionOk
                    ? 'k-bg-knot-success-soft k-border-knot-success/30'
                    : 'k-bg-knot-danger-soft k-border-knot-danger/30'
                ]"
              >
                <div class="k-flex k-items-start k-gap-4">
                  <div
                    :class="[
                      'k-w-10 k-h-10 k-rounded-knot-pill k-flex k-items-center k-justify-center k-shrink-0',
                      encryptionOk ? 'k-bg-knot-success k-text-white' : 'k-bg-knot-danger k-text-white'
                    ]"
                  >
                    <KeyRound :size="18" />
                  </div>
                  <div class="k-flex-1">
                    <p :class="['k-font-semibold k-mb-1 k-text-sm', encryptionOk ? 'k-text-knot-success' : 'k-text-knot-danger']">
                      {{ encryptionOk ? t('onboarding.step5.encryptionReady') : t('onboarding.step5.encryptionMissingUid') }}
                    </p>
                    <p v-if="encryptionOk" class="k-text-knot-text-muted k-text-sm k-leading-relaxed">
                      {{ t('onboarding.step5.fingerprintLead') }}
                      <code class="k-font-mono k-text-[11px] k-px-1.5 k-py-0.5 k-bg-knot-bg k-rounded-knot-sm k-text-knot-text">
                        {{ state?.encryption.fingerprint }}
                      </code>
                      <span class="k-block k-mt-2 k-text-xs">
                        {{ t('onboarding.step5.backupExplain') }}
                      </span>
                    </p>
                    <p v-else class="k-text-knot-text-muted k-text-sm k-leading-relaxed">
                      {{ t('onboarding.step5.encryptionFix') }}
                    </p>
                  </div>
                </div>
              </div>
            </div>

            <!-- Step 6 — SMTP optional -->
            <div v-else-if="step === 6" class="k-flex-1 k-flex k-flex-col k-gap-5">
              <header>
                <p class="k-text-xs k-uppercase k-tracking-wider k-text-knot-text-soft k-font-semibold k-mb-2">
                  {{ t('onboarding.step6.badgeOptional', { n: 7, total: STEP_TOTAL }) }}
                </p>
                <h2 class="k-text-2xl k-font-bold k-text-knot-text k-leading-tight">
                  {{ t('onboarding.step6.titleLead') }}
                  <span class="k-text-knot-text-soft k-font-normal">{{ t('onboarding.step6.titleOptionalSuffix') }}</span>
                </h2>
                <p class="k-mt-3 k-text-sm k-text-knot-text-muted k-leading-relaxed">
                  {{ t('onboarding.step6.intro') }}
                </p>
              </header>

              <div
                :class="[
                  'k-rounded-knot-md k-p-5 k-border k-flex k-items-start k-gap-4',
                  smtpOk
                    ? 'k-bg-knot-success-soft k-border-knot-success/30'
                    : 'k-bg-knot-surface-soft k-border-knot-border'
                ]"
              >
                <div
                  :class="[
                    'k-w-10 k-h-10 k-rounded-knot-pill k-flex k-items-center k-justify-center k-shrink-0',
                    smtpOk ? 'k-bg-knot-success k-text-white' : 'k-bg-knot-primary-soft k-text-knot-primary'
                  ]"
                >
                  <Mail :size="18" />
                </div>
                <div class="k-flex-1 k-text-sm">
                  <p :class="['k-font-semibold k-mb-1', smtpOk ? 'k-text-knot-success' : 'k-text-knot-text']">
                    {{ smtpOk ? t('onboarding.step6.smtpReady', { host: state?.smtp.host ?? '' }) : t('onboarding.step6.smtpMissing') }}
                  </p>
                  <p class="k-text-knot-text-muted k-leading-relaxed">
                    <template v-if="smtpOk">
                      {{ t('onboarding.step6.smtpOkDetail') }}
                    </template>
                    <template v-else>
                      {{ t('onboarding.step6.smtpMissingDetail') }}
                    </template>
                  </p>
                </div>
              </div>
            </div>

            <!-- Step 7 — Starters -->
            <div v-else-if="step === 7" class="k-flex-1 k-flex k-flex-col k-gap-5">
              <header>
                <p class="k-text-xs k-uppercase k-tracking-wider k-text-knot-text-soft k-font-semibold k-mb-2">
                  {{ t('onboarding.stepBadge', { n: 8, total: STEP_TOTAL }) }}
                </p>
                <h2 class="k-text-2xl k-font-bold k-text-knot-text k-leading-tight">
                  {{ t('onboarding.step7.titleLine1') }}
                  <span class="k-block k-bg-clip-text k-text-transparent k-bg-knot-hero">{{ t('onboarding.step7.titleGradient') }}</span>
                </h2>
                <p class="k-mt-3 k-text-sm k-text-knot-text-muted k-leading-relaxed">
                  {{ t('onboarding.step7.intro') }}
                </p>
              </header>

              <div class="k-grid k-grid-cols-1 sm:k-grid-cols-2 k-gap-3">
                <div
                  v-for="card in starterCards"
                  :key="card.name"
                  class="k-bg-knot-surface-soft k-border k-border-knot-border k-rounded-knot-sm k-p-3 k-flex k-items-start k-gap-3 k-text-sm"
                >
                  <span class="k-text-2xl k-leading-none">{{ card.emoji }}</span>
                  <span>
                    <strong class="k-block k-text-knot-text">{{ card.name }}</strong>
                    <span class="k-text-knot-text-soft k-text-xs">{{ card.desc }}</span>
                  </span>
                </div>
              </div>

              <div
                v-if="importedCount !== null"
                class="k-rounded-knot-sm k-p-3 k-bg-knot-success-soft k-border k-border-knot-success/30 k-text-knot-success k-text-sm k-flex k-items-center k-gap-2"
              >
                <PartyPopper :size="14" />
                <span>{{ t('onboarding.step7.importedBanner', { count: importedCount }) }}</span>
              </div>

              <button
                v-else
                @click="importStarters"
                :disabled="submitting || starterCount === 0"
                class="k-inline-flex k-items-center k-gap-2 k-px-4 k-py-2.5 k-rounded-knot-sm k-bg-knot-hero k-text-white k-font-semibold k-text-sm k-shadow-[0_8px_24px_rgba(99,102,241,0.4)] hover:k-shadow-[0_12px_32px_rgba(99,102,241,0.55)] disabled:k-opacity-50 k-transition-shadow k-self-start"
              >
                <Loader2 v-if="submitting" :size="14" class="k-animate-spin" />
                <Sparkles v-else :size="14" />
                {{ t('marketplace.importStartersCta', { count: starterCount }) }}
              </button>
            </div>
          </div>

          <!-- Footer -->
          <footer class="k-shrink-0 k-flex k-items-center k-justify-between k-gap-3 k-border-t k-border-knot-border k-bg-knot-surface-soft/60 k-px-8 k-py-4">
            <button
              @click="close"
              class="k-text-xs k-text-knot-text-soft hover:k-text-knot-text k-font-semibold"
            >
              {{ t('onboarding.skipNow') }}
            </button>

            <div class="k-flex k-items-center k-gap-2">
              <button
                v-if="step > 0"
                @click="prev"
                :disabled="submitting"
                class="k-inline-flex k-items-center k-gap-1 k-px-3 k-py-1.5 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border-strong k-text-knot-text k-text-sm k-font-semibold hover:k-border-knot-primary disabled:k-opacity-50"
              >
                <ChevronLeft :size="13" /> {{ t('onboarding.previous') }}
              </button>
              <button
                v-if="step < stepDefs.length - 1"
                @click="next"
                :disabled="submitting"
                class="k-inline-flex k-items-center k-gap-1 k-px-3.5 k-py-1.5 k-rounded-knot-sm k-bg-knot-primary k-text-white k-text-sm k-font-semibold hover:k-bg-knot-primary-strong disabled:k-opacity-50"
              >
                {{ t('onboarding.next') }} <ChevronRight :size="13" />
              </button>
              <button
                v-else
                @click="complete"
                :disabled="submitting"
                class="k-inline-flex k-items-center k-gap-1.5 k-px-4 k-py-1.5 k-rounded-knot-sm k-bg-knot-hero k-text-white k-text-sm k-font-semibold k-shadow-[0_4px_12px_rgba(99,102,241,0.4)] hover:k-shadow-[0_8px_20px_rgba(99,102,241,0.5)] disabled:k-opacity-50"
              >
                <Loader2 v-if="submitting" :size="13" class="k-animate-spin" />
                <CheckCircle2 v-else :size="13" />
                {{ t('onboarding.finish') }}
              </button>
            </div>
          </footer>
        </div>
        </div>
      </div>
    </transition>
  </Teleport>
</template>

<style scoped>
/* Thin, low-contrast scrollbar — keeps the modal ambiance on WebKit + Firefox */
.knot-onboarding-scroll {
  scrollbar-width: thin;
  scrollbar-color: rgb(99 102 241 / 0.28) transparent;
}
.knot-onboarding-scroll::-webkit-scrollbar {
  width: 7px;
}
.knot-onboarding-scroll::-webkit-scrollbar-track {
  background: transparent;
}
.knot-onboarding-scroll::-webkit-scrollbar-thumb {
  background-color: rgb(99 102 241 / 0.22);
  border-radius: 9999px;
  border: 2px solid transparent;
  background-clip: padding-box;
}
.knot-onboarding-scroll::-webkit-scrollbar-thumb:hover {
  background-color: rgb(99 102 241 / 0.38);
}
</style>
