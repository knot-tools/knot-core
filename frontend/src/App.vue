<!--
  Knot root component — switches between editor / dashboard / executions modes.
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, onMounted, onUnmounted, ref } from 'vue';
import EditorView from './views/EditorView.vue';
import DashboardView from './views/DashboardView.vue';
import ExecutionsView from './views/ExecutionsView.vue';
import ExecutionDetailView from './views/ExecutionDetailView.vue';
import WorkflowsView from './views/WorkflowsView.vue';
import ConnectorsView from './views/ConnectorsView.vue';
import CredentialsView from './views/CredentialsView.vue';
import InboxView from './views/InboxView.vue';
import AssistantView from './views/AssistantView.vue';
import BookView from './views/BookView.vue';
import DiffView from './views/DiffView.vue';
import DoctorView from './views/DoctorView.vue';
import CapabilitiesView from './views/CapabilitiesView.vue';
import TemplatesView from './views/TemplatesView.vue';
import VariablesView from './views/VariablesView.vue';
import AuditView from './views/AuditView.vue';
import MarketplaceView from './views/MarketplaceView.vue';
import UpdatesView from './views/UpdatesView.vue';
import CompatibilityView from './views/CompatibilityView.vue';
import ObservabilityView from './views/ObservabilityView.vue';
import CommandPalette from './components/CommandPalette.vue';
import DarkModeToggle from './components/DarkModeToggle.vue';
import OnboardingWizard from './components/OnboardingWizard.vue';
import KnotExtensionMount from './components/KnotExtensionMount.vue';
import LicenseActivationModal from './components/licensing/LicenseActivationModal.vue';
import UpdatesFloatingBanner from './components/shell/UpdatesFloatingBanner.vue';
import KDemoBanner from './components/shell/KDemoBanner.vue';
import KBetaBadge from './components/shell/KBetaBadge.vue';
import KConfirmDialog from './components/ui/KConfirmDialog.vue';
import { syncMarketplaceUnreadFromLocalCache } from './lib/marketplaceEditorialUnread';
import { provideToast } from './composables/useToast';
import { provideConfirm } from './composables/useConfirm';
import { KNOT_Z_TOAST } from './lib/overlayStacking';

provideToast();
provideConfirm();
import type { LicenseActivationResponse } from './lib/api';
import type { KnotLicenseActivatedDetail } from './lib/knotCore';

const props = defineProps<{
  mode: string;
  workflowId: number | null;
  executionId: number | null;
  executionTab?: string | null;
}>();

// ADR-20 slice 3: if the URL mode matches one of the active
// extensions declared in window.KNOT_EXTENSIONS, render the generic
// `KnotExtensionMount` instead of a built-in view. The extension's
// own bundle (loaded via <script defer> by preview.php) registers
// its mount function on window.KnotCore which the component picks
// up. Native modes always win over an extension's mode collision.
// ADR-20 slice 5: Core's previous "migration" mode (Pro Pack
// connector migration assistant) was renamed to
// "pro-pack-migration" so Knot Migration can claim the bare
// "migration" kebab name without colliding.
const extensionForMode = computed<{ id: string } | null>(() => {
  const knotCore = (window as unknown as { KnotCore?: { extensions: Array<{ id: string; mode: string }> } }).KnotCore;
  if (!knotCore) {
    return null;
  }
  const match = knotCore.extensions.find((ext) => ext.mode === props.mode);
  return match ? { id: match.id } : null;
});

// Global LicenseActivationModal mount (ADR-20 license activation hook).
// Any extension can request the activation modal via
// `window.KnotCore.openLicenseActivationModal(id, label)` which
// dispatches the `knot:open-license-activation` event we listen for
// below. The modal is shipped by Core (single CSRF dance, single
// signed-verdict cache write), extensions do not duplicate it.
const globalLicenseModalOpen = ref(false);
const globalLicenseModalExtensionId = ref('');
const globalLicenseModalExtensionLabel = ref('');

function handleOpenLicenseActivation(event: Event) {
  const detail = (event as CustomEvent<{ extensionId?: string; extensionLabel?: string }>).detail;
  if (!detail || typeof detail.extensionId !== 'string' || detail.extensionId === '') {
    return;
  }
  globalLicenseModalExtensionId.value = detail.extensionId;
  globalLicenseModalExtensionLabel.value = detail.extensionLabel ?? detail.extensionId;
  globalLicenseModalOpen.value = true;
}

function handleGlobalLicenseActivated(payload: LicenseActivationResponse) {
  if (!payload.activated) {
    return;
  }
  const eventDetail: KnotLicenseActivatedDetail = {
    extensionId: globalLicenseModalExtensionId.value,
    fingerprint: payload.fingerprint,
    verdict: payload.verdict,
  };
  window.dispatchEvent(
    new CustomEvent('knot:extension-license-activated', { detail: eventDetail }),
  );
  globalLicenseModalOpen.value = false;
}

onMounted(() => {
  syncMarketplaceUnreadFromLocalCache();
  window.addEventListener('knot:open-license-activation', handleOpenLicenseActivation);
});
onUnmounted(() => {
  window.removeEventListener('knot:open-license-activation', handleOpenLicenseActivation);
});

const view = computed(() => {
  switch (props.mode) {
    case 'dashboard':
      return DashboardView;
    case 'observability':
      return ObservabilityView;
    case 'executions':
    case 'queue':
      return ExecutionsView;
    case 'execution':
      return ExecutionDetailView;
    case 'workflows':
      return WorkflowsView;
    case 'connectors':
      return ConnectorsView;
    case 'credentials':
      return CredentialsView;
    case 'inbox':
      return InboxView;
    case 'assistant':
      return AssistantView;
    case 'book':
      return BookView;
    case 'diff':
      return DiffView;
    case 'doctor':
      return DoctorView;
    case 'capabilities':
      return CapabilitiesView;
    case 'compatibility':
      return CompatibilityView;
    case 'templates':
      return TemplatesView;
    case 'variables':
      return VariablesView;
    case 'audit':
      return AuditView;
    case 'marketplace':
      return MarketplaceView;
    case 'updates':
      return UpdatesView;
    case 'editor':
    default:
      return EditorView;
  }
});
</script>

<template>
  <div class="k-h-full k-min-h-[600px] k-flex k-flex-col k-bg-knot-bg k-text-knot-text k-font-sans">
    <KDemoBanner />
    <UpdatesFloatingBanner :mode="props.mode" />
    <div class="k-flex-1 k-min-h-0 k-flex k-flex-col">
    <Teleport to="body">
      <div class="k-fixed k-bottom-4 k-right-4" :style="{ zIndex: KNOT_Z_TOAST }">
        <DarkModeToggle />
      </div>
    </Teleport>
    <CommandPalette />
    <OnboardingWizard />
    <KnotExtensionMount
      v-if="extensionForMode"
      class="k-flex-1 k-min-h-0 k-flex k-flex-col"
      :extension-id="extensionForMode.id"
    />
    <component
      v-else
      :is="view"
      class="k-flex-1 k-min-h-0 k-flex k-flex-col"
      :workflow-id="props.workflowId"
      :execution-id="props.executionId"
      :execution-tab="props.mode === 'queue' ? 'queue' : props.executionTab"
    />
    </div>
    <KBetaBadge />
    <LicenseActivationModal
      :open="globalLicenseModalOpen"
      :extension-id="globalLicenseModalExtensionId"
      :extension-label="globalLicenseModalExtensionLabel"
      @close="globalLicenseModalOpen = false"
      @activated="handleGlobalLicenseActivated"
    />
    <KToastContainer />
    <KConfirmDialog />
  </div>
</template>
