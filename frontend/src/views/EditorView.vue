<!--
  Editor view (Vue Flow canvas + Knot custom nodes/edges).
  Copyright (C) 2026 Knot — GPL-3.0-or-later
-->
<script setup lang="ts">
import { computed, nextTick, onMounted, provide, ref, watch } from 'vue';
import WorkflowActivationDialog from '../components/risk/WorkflowActivationDialog.vue';
import TestSplitButton from '../components/risk/TestSplitButton.vue';
import { buildWorkflowRiskSummary } from '../composables/useWorkflowRiskSummary';
import { useToast } from '../composables/useToast';
import { useConfirm } from '../composables/useConfirm';
import { KNOT_CONNECTORS_KEY } from '../lib/knotConnectorContext';
import { useI18n } from 'vue-i18n';
import {
  VueFlow,
  useVueFlow,
  ConnectionMode,
  MarkerType,
  type Edge,
  type Node,
  type Connection,
  type NodeMouseEvent,
} from '@vue-flow/core';
import { Background, BackgroundVariant } from '@vue-flow/background';
import { Controls } from '@vue-flow/controls';
import { MiniMap } from '@vue-flow/minimap';
import {
  Workflow,
  Search,
  MousePointerClick,
  Save,
  Play,
  Trash2,
  Settings2,
  AlertCircle,
  CheckCircle2,
  Loader2,
  History,
  Undo2,
  Redo2,
  LayoutTemplate,
  FlaskConical,
  ArrowLeft,
  X,
  Braces,
  Lock,
} from 'lucide-vue-next';
import { buildChatbotFixMessage } from '../lib/chatbotFix';
import {
  normalizeWorkflowImport,
  parseWorkflowImportText,
  extractRepairs,
  WorkflowImportLegacyStepsError,
} from '../lib/normalizeWorkflowImport';
import { loadEditorUiState, saveEditorUiState } from '../lib/editorUiState';
import { useHistory } from '../composables/useHistory';
import { autoLayout } from '../lib/autoLayout';
import { createWorkflowLinter } from '../composables/useWorkflowLinter';
import type { ValidationIssue, KnotNodeLike, KnotEdgeLike } from '../lib/validator';
import ProblemsPanel from '../components/ProblemsPanel.vue';
import TestDataModal from '../components/TestDataModal.vue';
import SimulationSidePanel from '../components/SimulationSidePanel.vue';
import FullTraceModal from '../components/FullTraceModal.vue';
import MigrationBanner from '../components/licensing/MigrationBanner.vue';
import ExecutionErrorPanel from '../components/risk/ExecutionErrorPanel.vue';
import { extractKnotPayloadFromUnknown } from '../components/risk/ExecutionErrorTranslator';

import KnotNode from '../canvas/KnotNode.vue';
import KnotEdge from '../canvas/KnotEdge.vue';
import WebhookPanel from '../canvas/WebhookPanel.vue';
import NodeInspectorBody from '../components/inspector/NodeInspectorBody.vue';
import { PALETTE_SECTIONS, NODE_REGISTRY, resolveNodeMeta, categoryColorHex } from '../canvas/nodeRegistry';
import { connectorMessageKey, resolveConnectorLabel } from '../lib/connectorLabels';
import { KNOWN_SKU_PRO_PACK } from '../lib/known-skus';
import {
  type ConnectorDescriptor,
  type Workflow as KnotWorkflow,
  type WorkflowDefinition,
  type WorkflowVersion,
} from '../lib/api';
import { useEditorWorkflowApi } from '../composables/useEditorWorkflowApi';
import { getConnectorDescriptorsCached } from '../lib/connectorDescriptorsCache';
import { buildConnectorSchemaIndex, resolveConnectorConfigSchema } from '../lib/resolveConnectorSchema';
import { KNOT_CANVAS_SM_INSPECTOR } from '../lib/knotCanvasSmInspector';
import {
  IDEMPOTENCY_PLACEHOLDER_DOLIBARR,
  IDEMPOTENCY_PLACEHOLDER_GENERIC,
  IDEMPOTENCY_PLACEHOLDER_HTTP,
} from '../lib/idempotencyPlaceholders';
import { deriveEdgeType, edgeMarker, handleColor } from '../lib/edgeSemantics';
import { computeExecutionVisualization } from '../lib/executionPath';
import { KNOT_QUICK_ADD_KEY } from '../lib/knotQuickAddContext';

import '@vue-flow/core/dist/style.css';
import '@vue-flow/core/dist/theme-default.css';
import '@vue-flow/controls/dist/style.css';
import '@vue-flow/minimap/dist/style.css';

const props = defineProps<{
  workflowId: number | null;
  executionId: number | null;
}>();

const workflowsListUrl = computed(() => {
  const path = window.location.pathname;
  return `${path}?mode=workflows`;
});

const {
  addNodes,
  addEdges,
  removeNodes,
  screenToFlowCoordinate,
  onConnect,
  setNodes,
  setEdges,
  fitView,
  updateNodeInternals,
  setViewport,
  onViewportChange,
} = useVueFlow();

const editorApi = useEditorWorkflowApi();

/** Recalculate handle positions so edges render correctly after async load / connector catalog. */
function refreshCanvasGeometry(nodeIds?: string[]) {
  const run = () => {
    const ids = nodeIds ?? (nodes.value as Node[]).map((n) => String(n.id));
    if (ids.length > 0) {
      updateNodeInternals(ids);
    }
  };
  void nextTick(() => {
    requestAnimationFrame(() => {
      run();
      requestAnimationFrame(() => {
        run();
        // Catalog / fitView / viewport restore can settle after first paint.
        window.setTimeout(run, 50);
        window.setTimeout(run, 200);
        window.setTimeout(run, 450);
      });
    });
  });
}

function onCanvasNodesInitialized() {
  refreshCanvasGeometry();
}

const { t } = useI18n();

function paletteNodeLabel(nodeId: string): string {
  const reg = NODE_REGISTRY[nodeId];
  return resolveConnectorLabel(connectorMessageKey(nodeId, 'label'), reg?.label ?? nodeId);
}

function paletteNodeDescription(nodeId: string): string {
  const reg = NODE_REGISTRY[nodeId];
  return resolveConnectorLabel(connectorMessageKey(nodeId, 'description'), reg?.description ?? '');
}

// When opening an existing workflow we briefly want an empty canvas — the
// real nodes are fetched in onMounted via loadWorkflow().
const hasInitialWorkflow = !!(props.workflowId && props.workflowId > 0);

const workflowName = ref(hasInitialWorkflow ? '' : t('editor.defaultWorkflowName'));
const workflowRef = ref('');
const workflowStatus = ref<'draft' | 'active' | 'disabled' | 'error' | 'archived'>('draft');
const currentWorkflowId = ref<number | null>(props.workflowId ?? null);

function createSampleNodes(): Node[] {
  return [
    {
      id: 'manual_1',
      type: 'knot',
      position: { x: 80, y: 200 },
      data: {
        type: 'trigger.manual',
        label: t('editor.starterManualLabel'),
        subtitle: t('editor.starterManualSubtitle'),
        config: {},
        status: 'success',
      },
    },
    {
      id: 'set_1',
      type: 'knot',
      position: { x: 380, y: 200 },
      data: {
        type: 'logic.set',
        label: t('editor.starterSetLabel'),
        subtitle: t('editor.starterSetSubtitle'),
        config: { values: { greeting: t('editor.starterGreetingValue') } },
      },
    },
    {
      id: 'email_1',
      type: 'knot',
      position: { x: 700, y: 200 },
      data: {
        type: 'action.email',
        label: t('editor.starterEmailLabel'),
        subtitle: t('editor.starterEmailSubtitle'),
        config: {
          to: 'ops@example.com',
          subject: t('editor.starterEmailSubject'),
          body: '<p>{{greeting}}</p>',
        },
      },
    },
  ];
}

const defaultMarker = { type: MarkerType.ArrowClosed, color: '#6366f1', width: 18, height: 18 };

function buildKnotEdge(
  partial: Pick<Edge, 'id' | 'source' | 'target'> & {
    sourceHandle?: string | null;
    targetHandle?: string | null;
    animated?: boolean;
  },
): Edge {
  const edgeType = deriveEdgeType(partial.sourceHandle ?? null);
  return {
    ...partial,
    type: 'knot',
    data: { type: edgeType },
    animated: !!partial.animated,
    markerEnd: edgeMarker(edgeType, MarkerType.ArrowClosed) as Edge['markerEnd'],
  };
}

const SAMPLE_EDGES: Edge[] = [
  buildKnotEdge({ id: 'e1', source: 'manual_1', target: 'set_1', sourceHandle: 'main' }),
  buildKnotEdge({ id: 'e2', source: 'set_1', target: 'email_1', sourceHandle: 'main' }),
];

const showCanvasLegend = ref(true);
const connectingHandle = ref<string | null>(null);
const connectionValid = ref(true);
const quickAddPending = ref<{ sourceId: string; sourceHandle: string } | null>(null);

provide(KNOT_QUICK_ADD_KEY, {
  startQuickAdd(sourceId: string, sourceHandle: string) {
    quickAddPending.value = { sourceId, sourceHandle };
    successFlash.value = t('editor.quickAddHint');
    setTimeout(() => {
      if (successFlash.value === t('editor.quickAddHint')) {
        successFlash.value = null;
      }
    }, 3500);
  },
  get pendingSource() {
    return quickAddPending.value;
  },
});



const nodes = ref<Node[]>(hasInitialWorkflow ? [] : createSampleNodes());
const edges = ref<Edge[]>(hasInitialWorkflow ? [] : SAMPLE_EDGES.slice());
const showStarterHint = ref(!hasInitialWorkflow);

const nodeTypes = { knot: KnotNode };
const edgeTypes = { knot: KnotEdge };

const search = ref('');

function isPaletteNodeAvailable(typeId: string): boolean {
  const entry = connectorCatalogById.value.get(typeId);
  if (!entry) {
    return true;
  }
  return entry.available !== false;
}

function paletteUnavailableTitle(typeId: string): string {
  return t('editor.paletteRequiresLicense', { connector: paletteNodeLabel(typeId) });
}

/** Locked Pro Pack palette entry: open activation upsell (or Pro Pack hub). */
function onPaletteLockedClick(typeId: string): void {
  if (isPaletteNodeAvailable(typeId)) {
    return;
  }
  const knotCore = (
    window as unknown as {
      KnotCore?: { openLicenseActivationModal?: (id: string, label?: string) => void };
    }
  ).KnotCore;
  if (typeof knotCore?.openLicenseActivationModal === 'function') {
    knotCore.openLicenseActivationModal(
      KNOWN_SKU_PRO_PACK,
      t('editor.paletteProPackLabel', 'Knot Pro Pack'),
    );
    return;
  }
  window.location.href = '?mode=pro-pack';
}

function onPaletteDragStart(event: DragEvent, typeId: string) {
  if (!isPaletteNodeAvailable(typeId)) {
    event.preventDefault();
    return;
  }
  if (!event.dataTransfer) return;
  event.dataTransfer.setData('application/knot-node', typeId);
  event.dataTransfer.effectAllowed = 'move';
}

function filteredIds(ids: string[]): string[] {
  if (!search.value.trim()) return ids;
  const q = search.value.toLowerCase();
  return ids.filter((id) => {
    const meta = NODE_REGISTRY[id];
    if (!meta) return false;
    return (
      paletteNodeLabel(id).toLowerCase().includes(q) ||
      paletteNodeDescription(id).toLowerCase().includes(q)
    );
  });
}

function onCanvasDragOver(event: DragEvent) {
  event.preventDefault();
  if (event.dataTransfer) event.dataTransfer.dropEffect = 'move';
}

function onCanvasDrop(event: DragEvent) {
  event.preventDefault();
  const typeId = event.dataTransfer?.getData('application/knot-node');
  if (!typeId || !isPaletteNodeAvailable(typeId)) return;
  const position = screenToFlowCoordinate({ x: event.clientX, y: event.clientY });

  const id = `${typeId.replace('.', '_')}_${Date.now()}`;
  addNodes({
    id,
    type: 'knot',
    position: { x: position.x - 130, y: position.y - 30 },
    data: { type: typeId, label: paletteNodeLabel(typeId), subtitle: paletteNodeDescription(typeId), config: {} },
  });
  if (quickAddPending.value) {
    addEdges(
      buildKnotEdge({
        id: `e_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`,
        source: quickAddPending.value.sourceId,
        target: id,
        sourceHandle: quickAddPending.value.sourceHandle,
        targetHandle: 'main',
      }),
    );
    quickAddPending.value = null;
  }
}

function isValidConnection(connection: Connection): boolean {
  if (!connection.source || !connection.target) {
    connectionValid.value = false;
    return false;
  }
  if (connection.source === connection.target) {
    connectionValid.value = false;
    return false;
  }
  const targetNode = (nodes.value as Node[]).find((n) => n.id === connection.target);
  const targetType = String((targetNode?.data as { type?: string } | undefined)?.type ?? '');
  if (targetType.startsWith('trigger.')) {
    connectionValid.value = false;
    return false;
  }
  connectionValid.value = true;
  return true;
}

onConnect((params: Connection) => {
  if (!isValidConnection(params)) return;
  addEdges(
    buildKnotEdge({
      id: `e_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`,
      source: params.source!,
      target: params.target!,
      sourceHandle: params.sourceHandle ?? 'main',
      targetHandle: params.targetHandle ?? undefined,
    }),
  );
});

const connectionLineStyle = computed(() => ({
  stroke: connectionValid.value
    ? handleColor(connectingHandle.value ?? 'main')
    : 'var(--knot-edge-error, #ef4444)',
  strokeWidth: 2,
}));

function onConnectStart(connectionEvent: { event?: MouseEvent; handleId?: string | null }) {
  connectingHandle.value = connectionEvent.handleId ?? 'main';
  connectionValid.value = true;
}

function onConnectEnd() {
  connectingHandle.value = null;
  connectionValid.value = true;
}

const selectedId = ref<string | null>(null);
const selectedNode = computed<Node | null>(() => {
  const id = selectedId.value;
  if (!id) return null;
  for (const n of nodes.value) {
    if (n.id === id) return n as Node;
  }
  return null;
});

const selectedMeta = computed(() => {
  const typeId = selectedNode.value?.data?.type;
  if (typeof typeId !== 'string' || typeId === '') return null;
  return resolveNodeMeta(typeId);
});

const showStarterBanner = computed(
  () => !hasInitialWorkflow && currentWorkflowId.value === null && showStarterHint.value,
);

function dismissStarterHint() {
  showStarterHint.value = false;
}

function clearStarterCanvas() {
  const emptyNodes: Node[] = [];
  const emptyEdges: Edge[] = [];
  nodes.value = emptyNodes;
  edges.value = emptyEdges;
  setNodes(emptyNodes);
  setEdges(emptyEdges);
  selectedId.value = null;
  showStarterHint.value = false;
  void nextTick(() => {
    dirty.value = true;
  });
}

const simulationNodeLabelMap = computed(() => {
  const m: Record<string, string> = {};
  for (const n of nodes.value as Node[]) {
    const id = String(n.id ?? '');
    const label = String((n.data as { label?: string } | undefined)?.label ?? '').trim();
    if (id !== '' && label !== '') {
      m[id] = label;
    }
  }
  return m;
});

/** HTTP / AI / SaaS nodes may exceed the 25 s sync simulate budget or queue latency. */
const workflowUsesSlowConnectors = computed(() => {
  const slowSaas = new Set([
    'action.stripe',
    'action.woocommerce',
    'action.shopify',
    'action.notion',
    'action.prestashop',
    'action.sftp',
    'action.twilio',
    'action.slack',
  ]);
  for (const n of nodes.value as Node[]) {
    const typ = String((n.data as { type?: string } | undefined)?.type ?? '').toLowerCase();
    if (typ.startsWith('http') || typ === 'action.http') {
      return true;
    }
    if (typ.startsWith('ai.') || typ === 'action.ai') {
      return true;
    }
    if (slowSaas.has(typ)) {
      return true;
    }
  }
  return false;
});

const idempotencyUi = computed(() => {
  const typ = String(selectedNode.value?.data?.type ?? '');
  if (typ.startsWith('dolibarr.')) {
    return {
      placeholder: IDEMPOTENCY_PLACEHOLDER_DOLIBARR,
      hint: t('inspector.idempotencyHintDolibarr'),
    };
  }
  if (typ.startsWith('http') || typ === 'action.http') {
    return {
      placeholder: IDEMPOTENCY_PLACEHOLDER_HTTP,
      hint: t('inspector.idempotencyHintHttp'),
    };
  }
  return {
    placeholder: IDEMPOTENCY_PLACEHOLDER_GENERIC,
    hint: t('inspector.idempotencyHintGeneric'),
  };
});

const dolibarrSmInspectorFocusTick = ref(0);
provide(KNOT_CANVAS_SM_INSPECTOR, {
  focusChangeStatusHints(): void {
    dolibarrSmInspectorFocusTick.value++;
  },
});

const connectorDescriptors = ref<ConnectorDescriptor[]>([]);
const connectorDescriptorsReady = ref(false);

async function loadConnectorDescriptors(): Promise<void> {
  connectorDescriptorsReady.value = false;
  try {
    connectorDescriptors.value = await getConnectorDescriptorsCached();
  } catch {
    connectorDescriptors.value = [];
  } finally {
    connectorDescriptorsReady.value = true;
  }
}

void loadConnectorDescriptors();

watch(connectorDescriptorsReady, (ready) => {
  if (ready && (nodes.value as Node[]).length > 0) {
    refreshCanvasGeometry();
  }
});

provide(KNOT_CONNECTORS_KEY, connectorDescriptors);

const toast = useToast();
const confirmDialog = useConfirm();
const riskSummary = computed(() => {
  const map = new Map<string, ConnectorDescriptor>();
  for (const c of connectorDescriptors.value) {
    map.set(String(c.metadata.id), c);
  }
  return buildWorkflowRiskSummary(nodes.value as Node[], map);
});

const activationSummaryText = computed(() =>
  t(
    riskSummary.value.summaryKey,
    riskSummary.value.summaryParams as Record<string, unknown>,
  ),
);

const connectorCatalogById = computed(() => {
  const map = new Map<string, ConnectorDescriptor>();
  for (const c of connectorDescriptors.value) {
    map.set(String(c.metadata.id), c);
  }
  return map;
});

function minimapNodeColor(node: Node): string {
  const typeId = String(node.data?.type ?? '');
  return categoryColorHex(resolveNodeMeta(typeId).category);
}

const savedWorkflowStatus = ref<'draft' | 'active' | 'disabled' | 'error' | 'archived'>('draft');
const activationDialogOpen = ref(false);

const scheduleActive = computed(() => {
  for (const n of nodes.value as Node[]) {
    const typ = String((n.data as { type?: string } | undefined)?.type ?? '');
    if (typ === 'trigger.cron') return true;
  }
  return false;
});

const schemaByConnectorId = computed(() => buildConnectorSchemaIndex(connectorDescriptors.value));

const resolvedInspectorSchema = computed((): Record<string, unknown> | null => {
  const nodeType = String(selectedNode.value?.data?.type ?? '');
  if (!nodeType) return null;
  return resolveConnectorConfigSchema(nodeType, schemaByConnectorId.value);
});
const upstreamDataPaths = computed(() => {
  if (!selectedNode.value) return [] as Array<{ source: string; path: string; expression: string; preview: string }>;
  const incomingSources = new Set(
    (edges.value as Edge[]).filter((edge) => edge.target === selectedNode.value?.id).map((edge) => edge.source),
  );
  const paths: Array<{ source: string; path: string; expression: string; preview: string }> = [];
  for (const node of nodes.value as Node[]) {
    if (!incomingSources.has(node.id)) continue;
    const output = (node.data?.pinnedOutput ?? null) as Record<string, unknown> | null;
    if (!output) continue;
    for (const item of flattenJsonPaths(output)) {
      paths.push({
        source: node.id,
        path: item.path,
        expression: `{{$json.${item.path}}}`,
        preview: item.preview,
      });
    }
  }
  return paths;
});

function onNodeClick(payload: NodeMouseEvent) {
  selectedId.value = payload.node.id;
  saveEditorUiState({
    workflowId: currentWorkflowId.value,
    selectedNodeId: payload.node.id,
  });
}

function onPaneClick() {
  selectedId.value = null;
  saveEditorUiState({
    workflowId: currentWorkflowId.value,
    selectedNodeId: null,
  });
}

function restoreEditorUiSelection(): void {
  const saved = loadEditorUiState();
  if (!saved || saved.workflowId !== currentWorkflowId.value || !saved.selectedNodeId) {
    return;
  }
  const exists = (nodes.value as Node[]).some((n) => n.id === saved.selectedNodeId);
  if (exists) {
    selectedId.value = saved.selectedNodeId;
  }
}

function restoreEditorViewport(): void {
  const saved = loadEditorUiState();
  if (!saved || saved.workflowId !== currentWorkflowId.value || !saved.viewport) {
    return;
  }
  const { x, y, zoom } = saved.viewport;
  if (
    typeof x !== 'number'
    || typeof y !== 'number'
    || typeof zoom !== 'number'
    || !Number.isFinite(x)
    || !Number.isFinite(y)
    || !Number.isFinite(zoom)
    || zoom <= 0
  ) {
    return;
  }
  void nextTick(() => {
    setViewport({ x, y, zoom }, { duration: 0 });
  });
}

let viewportPersistTimer: ReturnType<typeof setTimeout> | null = null;
onViewportChange((vp) => {
  if (viewportPersistTimer) clearTimeout(viewportPersistTimer);
  viewportPersistTimer = setTimeout(() => {
    saveEditorUiState({
      workflowId: currentWorkflowId.value,
      viewport: { x: vp.x, y: vp.y, zoom: vp.zoom },
    });
  }, 250);
});

function updateSelectedField(field: 'label' | 'subtitle', value: string) {
  const id = selectedId.value;
  if (!id) return;
  for (const n of nodes.value) {
    if (n.id === id) {
      (n as Node).data = { ...(n as Node).data, [field]: value };
      return;
    }
  }
}

function setSelectedConfig(value: Record<string, unknown>) {
  const id = selectedId.value;
  if (!id) return;
  const list = nodes.value as Node[];
  const idx = list.findIndex((n: Node) => n.id === id);
  if (idx < 0) return;
  const prev = list[idx];
  const next: Node = {
    ...prev,
    data: { ...(prev.data ?? {}), config: value },
  };
  const updated = [...list];
  updated[idx] = next;
  nodes.value = updated as typeof nodes.value;
  setNodes(updated);
  dirty.value = true;
}

function setSelectedNotes(value: string) {
  // Free-form comment persisted at the node level in the workflow
  // JSON (`notes` field; see docs/workflow-format.md). Mirrors
  // setSelectedConfig so undo/redo and dirty tracking stay
  // consistent with the rest of the inspector.
  const id = selectedId.value;
  if (!id) return;
  const list = nodes.value as Node[];
  const idx = list.findIndex((n: Node) => n.id === id);
  if (idx < 0) return;
  const prev = list[idx];
  const next: Node = {
    ...prev,
    data: { ...(prev.data ?? {}), notes: value },
  };
  const updated = [...list];
  updated[idx] = next;
  nodes.value = updated as typeof nodes.value;
  setNodes(updated);
  dirty.value = true;
}

function updateSelectedConfigString(field: string, value: string) {
  const id = selectedId.value;
  if (!id) return;
  const list = nodes.value as Node[];
  const idx = list.findIndex((n: Node) => n.id === id);
  if (idx < 0) return;
  const prev = list[idx];
  const data = ((prev.data ?? {}) as Record<string, unknown>);
  const config = (data.config as Record<string, unknown> | undefined) ?? {};
  const next: Node = {
    ...prev,
    data: { ...data, config: { ...config, [field]: value } },
  };
  const updated = [...list];
  updated[idx] = next;
  nodes.value = updated as typeof nodes.value;
  setNodes(updated);
  dirty.value = true;
}

function updateSelectedConfigFlag(field: string, value: boolean) {
  const id = selectedId.value;
  if (!id) return;
  const list = nodes.value as Node[];
  const idx = list.findIndex((n: Node) => n.id === id);
  if (idx < 0) return;
  const prev = list[idx];
  const data = ((prev.data ?? {}) as Record<string, unknown>);
  const config = (data.config as Record<string, unknown> | undefined) ?? {};
  const next: Node = {
    ...prev,
    data: { ...data, config: { ...config, [field]: value } },
  };
  const updated = [...list];
  updated[idx] = next;
  nodes.value = updated as typeof nodes.value;
  setNodes(updated);
  dirty.value = true;
}

function updateSelectedPinnedOutput(value: string) {
  const id = selectedId.value;
  if (!id) return;
  for (const n of nodes.value) {
    if (n.id !== id) continue;
    try {
      const parsed = value.trim() === '' ? null : JSON.parse(value);
      (n as Node).data = { ...(n as Node).data, pinnedOutput: parsed, pinnedAt: parsed ? new Date().toISOString() : null };
      dirty.value = true;
    } catch (_err) {
      // Keep editing until JSON becomes valid.
    }
    return;
  }
}

function flattenJsonPaths(value: unknown, prefix = ''): Array<{ path: string; preview: string }> {
  if (value === null || typeof value !== 'object') {
    return prefix ? [{ path: prefix, preview: String(value) }] : [];
  }
  const result: Array<{ path: string; preview: string }> = [];
  const entries = Array.isArray(value)
    ? value.map((item, index) => [String(index), item] as const)
    : Object.entries(value as Record<string, unknown>);
  for (const [key, child] of entries) {
    const path = prefix ? `${prefix}.${key}` : key;
    if (child !== null && typeof child === 'object') {
      result.push(...flattenJsonPaths(child, path));
    } else {
      result.push({ path, preview: String(child) });
    }
  }
  return result;
}

function onPathDragStart(event: DragEvent, expression: string) {
  event.dataTransfer?.setData('text/plain', expression);
  event.dataTransfer?.setData('application/knot-expression', expression);
}


function deleteSelected() {
  if (!selectedNode.value) return;
  removeNodes(selectedNode.value.id);
  selectedId.value = null;
}

const dirty = ref(false);
const saving = ref(false);
const saveError = ref<string | null>(null);
const richExecutionError = ref<Record<string, unknown> | null>(null);
const successFlash = ref<string | null>(null);
const loading = ref(false);
const versionsOpen = ref(false);
const testModalOpen = ref(false);
const testModalMode = ref<'simulate' | 'run' | 'replay'>('simulate');
const testSimulateDryRun = ref(true);
const replayFromNode = ref<string | null>(null);
const simResult = ref<{ logs: Array<Record<string, unknown>>; durationMs: number; status: 'success' | 'error'; dryRun: boolean } | null>(null);
const fullTraceOpen = ref(false);
const simulating = ref(false);
const simulateTimedOut = ref(false);
const runQueuedHint = ref<string | null>(null);
const liveIssues = ref<ValidationIssue[]>([]);
const workflowLinter = createWorkflowLinter(300);

// "Edit JSON" dialog — paste a corrected workflow (e.g. from an external
// chatbot) without leaving the editor or going through bulk import.
const jsonDialogOpen = ref(false);
const jsonDialogText = ref('');
const jsonDialogError = ref<string | null>(null);

function openJsonDialog() {
  jsonDialogText.value = JSON.stringify(buildDefinition(), null, 2);
  jsonDialogError.value = null;
  jsonDialogOpen.value = true;
}

function applyJsonDialog() {
  jsonDialogError.value = null;
  try {
    const parsed = parseWorkflowImportText(jsonDialogText.value);
    const payload = normalizeWorkflowImport(parsed, {
      label: workflowName.value || t('editor.defaultWorkflowName'),
    });
    const repairs = extractRepairs(payload.definition);
    applyWorkflow({
      id: currentWorkflowId.value,
      label: payload.label || workflowName.value,
      ref: workflowRef.value,
      status: workflowStatus.value,
      definition: payload.definition,
    } as KnotWorkflow);
    jsonDialogOpen.value = false;
    void nextTick(() => {
      dirty.value = true;
    });
    if (repairs.length > 0) {
      toast.success(t('editor.editJsonAppliedWithRepairs', { count: repairs.length }));
    } else {
      toast.success(t('editor.editJsonApplied'));
    }
  } catch (err) {
    if (err instanceof WorkflowImportLegacyStepsError) {
      jsonDialogError.value = t('editor.editJsonLegacy');
      return;
    }
    if (err instanceof SyntaxError) {
      jsonDialogError.value = t('editor.editJsonInvalidJson');
      return;
    }
    jsonDialogError.value = err instanceof Error && err.message !== ''
      ? err.message
      : t('editor.editJsonInvalid');
  }
}

async function copyProblemsFixForChatbot() {
  const installedSlugs = [...connectorCatalogById.value.keys()];
  const text = buildChatbotFixMessage(
    liveIssues.value,
    JSON.stringify(buildDefinition(), null, 2),
    { installedSlugs },
  );
  await navigator.clipboard?.writeText(text);
  toast.success(t('editor.problemsCopyFixDone'));
}

function recomputeIssues() {
  const getDef = (): WorkflowDefinition => buildDefinition();
  const getGraph = (): { nodes: KnotNodeLike[]; edges: KnotEdgeLike[] } => ({
    nodes: nodes.value.map((n) => ({
      id: n.id,
      type: n.type,
      data: n.data as KnotNodeLike['data'],
    })),
    edges: edges.value.map((e) => ({
      id: e.id,
      source: e.source,
      target: e.target,
      sourceHandle: e.sourceHandle ?? null,
    })),
  });
  workflowLinter.schedule(getDef, getGraph, (merged: ValidationIssue[]) => {
    liveIssues.value = merged;
  });
}

function onJumpToNode(nodeId: string) {
  selectedId.value = nodeId;
  const target = (nodes.value as Node[]).find((n: Node) => n.id === nodeId);
  if (target && typeof window !== 'undefined') {
    selectedId.value = nodeId;
  }
}

watch([nodes, edges], () => {
  recomputeIssues();
}, { deep: true });

watch(simResult, (sim) => {
  const list = nodes.value as Node[];
  let changed = false;
  const edgeList = edges.value as Edge[];
  const viz = sim
    ? computeExecutionVisualization(
        sim.logs as Parameters<typeof computeExecutionVisualization>[0],
        edgeList.map((e) => ({
          id: String(e.id),
          source: e.source,
          target: e.target,
          sourceHandle: e.sourceHandle ?? null,
        })),
      )
    : null;

  const next = list.map((n) => {
    const desiredStatus = sim ? (viz?.statusByNode[n.id] ?? 'idle') : 'idle';
    const desiredDimmed = sim ? !!viz?.branchDimmedByNode[n.id] : false;
    const desiredHandles = sim ? (viz?.dimmedHandlesByNode[n.id] ?? []) : [];
    const data = (n.data ?? {}) as Record<string, unknown>;
    const sameStatus = data.status === desiredStatus;
    const sameDimmed = !!data.branchDimmed === desiredDimmed;
    const prevHandles = JSON.stringify(data.dimmedHandles ?? []);
    const nextHandles = JSON.stringify(desiredHandles);
    if (sameStatus && sameDimmed && prevHandles === nextHandles) return n;
    changed = true;
    return {
      ...n,
      data: {
        ...data,
        status: desiredStatus,
        branchDimmed: desiredDimmed,
        dimmedHandles: desiredHandles,
      },
    } as Node;
  });
  if (changed) {
    nodes.value = next as typeof nodes.value;
    setNodes(next);
  }

  let edgesChanged = false;
  const nextEdges = edgeList.map((e) => {
    const shouldAnimate = sim ? (viz?.executedEdgeIds.has(String(e.id)) ?? false) : false;
    if (!!e.animated === shouldAnimate) return e;
    edgesChanged = true;
    return { ...e, animated: shouldAnimate };
  });
  if (edgesChanged) {
    edges.value = nextEdges as typeof edges.value;
    setEdges(nextEdges);
  }
});

const history = useHistory();

function snapshot() {
  history.record({
    nodes: JSON.parse(JSON.stringify(nodes.value)),
    edges: JSON.parse(JSON.stringify(edges.value)),
  });
}

function undoChange() {
  const prev = history.undo({
    nodes: JSON.parse(JSON.stringify(nodes.value)),
    edges: JSON.parse(JSON.stringify(edges.value)),
  });
  if (!prev) return;
  nodes.value = prev.nodes;
  edges.value = prev.edges;
  setNodes(prev.nodes);
  setEdges(prev.edges);
  dirty.value = true;
}

function redoChange() {
  const next = history.redo({
    nodes: JSON.parse(JSON.stringify(nodes.value)),
    edges: JSON.parse(JSON.stringify(edges.value)),
  });
  if (!next) return;
  nodes.value = next.nodes;
  edges.value = next.edges;
  setNodes(next.nodes);
  setEdges(next.edges);
  dirty.value = true;
}

function arrangeNodes(options?: { animateLayout?: boolean }) {
  const animateLayout = options?.animateLayout ?? false;
  snapshot();
  const before = nodes.value as Node[];
  const laid = autoLayout(before, edges.value as Edge[]);
  if (!animateLayout) {
    nodes.value = laid as typeof nodes.value;
    setNodes(laid);
    dirty.value = true;
    void nextTick(() => {
      fitView({ padding: 0.2, duration: 0 });
      refreshCanvasGeometry();
    });
    return;
  }

  const startPositions = new Map(
    before.map((n) => [n.id, { x: n.position.x, y: n.position.y }]),
  );
  const durationMs = 420;
  const t0 = performance.now();

  const tick = (now: number) => {
    const raw = Math.min(1, (now - t0) / durationMs);
    const eased = 1 - (1 - raw) ** 2;
    const frame = laid.map((n) => {
      const from = startPositions.get(n.id) ?? n.position;
      return {
        ...n,
        position: {
          x: from.x + (n.position.x - from.x) * eased,
          y: from.y + (n.position.y - from.y) * eased,
        },
      };
    });
    nodes.value = frame as typeof nodes.value;
    setNodes(frame);
    if (raw < 1) {
      requestAnimationFrame(tick);
      return;
    }
    nodes.value = laid as typeof nodes.value;
    setNodes(laid);
    dirty.value = true;
    void nextTick(() => {
      fitView({ padding: 0.2, duration: 400 });
      refreshCanvasGeometry();
    });
  };
  requestAnimationFrame(tick);
}

function onToolbarArrangeClick() {
  arrangeNodes();
}

function copySelection() {
  const sel = (nodes.value as Node[]).filter((n: Node) => n.id === selectedId.value);
  if (sel.length === 0) return;
  const ids = new Set(sel.map((n: Node) => n.id));
  const involvedEdges = (edges.value as Edge[]).filter((e: Edge) => ids.has(e.source) && ids.has(e.target));
  const data = JSON.stringify({ knotClipboard: '1.0', nodes: sel, edges: involvedEdges });
  navigator.clipboard?.writeText(data);
  successFlash.value = t('editor.toastNodesCopied');
  setTimeout(() => (successFlash.value = null), 2000);
}

async function pasteNodes() {
  try {
    const text = await navigator.clipboard.readText();
    const parsed = JSON.parse(text);
    if (parsed.knotClipboard !== '1.0' || !Array.isArray(parsed.nodes)) return;
    snapshot();
    const idMap: Record<string, string> = {};
    const newNodes = parsed.nodes.map((n: Node) => {
      const newId = `${n.id}_${Math.random().toString(36).slice(2, 7)}`;
      idMap[n.id] = newId;
      return { ...n, id: newId, position: { x: (n.position?.x ?? 0) + 40, y: (n.position?.y ?? 0) + 40 }, selected: false };
    });
    const newEdges = (parsed.edges || []).map((e: Edge) => ({
      ...e,
      id: `${e.id || `e_${Math.random().toString(36).slice(2, 7)}`}_paste`,
      source: idMap[e.source] || e.source,
      target: idMap[e.target] || e.target,
    }));
    nodes.value = [...nodes.value, ...newNodes];
    edges.value = [...edges.value, ...newEdges];
    addNodes(newNodes);
    addEdges(newEdges);
    dirty.value = true;
  } catch {
    // silent
  }
}

function onGlobalKey(e: KeyboardEvent) {
  const isMod = e.metaKey || e.ctrlKey;
  if (!isMod) return;
  const target = e.target as HTMLElement | null;
  const inField = target && (target.tagName === 'INPUT' || target.tagName === 'TEXTAREA' || target.isContentEditable);
  if (e.key.toLowerCase() === 'z' && !e.shiftKey && !inField) { e.preventDefault(); undoChange(); return; }
  if ((e.key.toLowerCase() === 'z' && e.shiftKey) || e.key.toLowerCase() === 'y') { if (!inField) { e.preventDefault(); redoChange(); } return; }
  if (e.key.toLowerCase() === 'c' && !inField) { copySelection(); return; }
  if (e.key.toLowerCase() === 'v' && !inField) { pasteNodes(); return; }
}

if (typeof window !== 'undefined') {
  window.addEventListener('keydown', onGlobalKey);
}

function clearTopErrors() {
  saveError.value = null;
  richExecutionError.value = null;
}

function setPlainTopError(message: string) {
  saveError.value = message;
  richExecutionError.value = null;
}

function setTopErrorFromCatch(err: unknown, fallback: string) {
  const knot = extractKnotPayloadFromUnknown(err);
  if (knot) {
    richExecutionError.value = knot as unknown as Record<string, unknown>;
    saveError.value = null;
    return;
  }
  richExecutionError.value = null;
  saveError.value = err instanceof Error ? err.message : fallback;
}

const scheduleOpen = ref(false);
const scheduleDraft = ref({ cronExpression: '0 9 * * *', timezone: 'UTC', isActive: true });

const schedulePresets = computed(() => [
  { cron: '0 * * * *', label: t('editor.schedulePresetHourly') },
  { cron: '0 9 * * *', label: t('editor.schedulePresetDailyNine') },
  { cron: '0 8 * * 1', label: t('editor.schedulePresetMondayEight') },
  { cron: '0 9 1 * *', label: t('editor.schedulePresetFirstOfMonth') },
]);

async function saveSchedule() {
  if (!currentWorkflowId.value) return;
  try {
    await editorApi.saveSchedule(currentWorkflowId.value, scheduleDraft.value);
    scheduleOpen.value = false;
    successFlash.value = t('editor.toastScheduleSaved');
    setTimeout(() => (successFlash.value = null), 2500);
  } catch (err) {
    setTopErrorFromCatch(err, t('editor.scheduleSaveFailed'));
  }
}
const versionsLoading = ref(false);
const versions = ref<WorkflowVersion[]>([]);

function flashSuccess(message: string) {
  toast.success(message);
}

function buildDefinition(): WorkflowDefinition {
  const serializedNodes: Array<Record<string, unknown>> = [];
  for (const n of nodes.value as Node[]) {
    const data = (n.data ?? {}) as Record<string, unknown>;
    serializedNodes.push({
      id: n.id,
      type: (data.type as string) ?? 'unknown',
      label: (data.label as string) ?? '',
      subtitle: (data.subtitle as string) ?? '',
      position: n.position,
      config: (data.config as Record<string, unknown>) ?? {},
      notes: (data.notes as string | undefined) ?? '',
      pinnedOutput: (data.pinnedOutput as Record<string, unknown> | undefined) ?? null,
      pinnedAt: (data.pinnedAt as string | undefined) ?? null,
    });
  }
  const serializedEdges: Array<Record<string, unknown>> = [];
  for (const e of edges.value as Edge[]) {
    serializedEdges.push({
      id: e.id,
      source: e.source,
      target: e.target,
      sourceHandle: e.sourceHandle ?? null,
      targetHandle: e.targetHandle ?? null,
      animated: !!e.animated,
    });
  }
  return {
    schemaVersion: '1.0',
    workflow: { id: currentWorkflowId.value, label: workflowName.value },
    metadata: { editor: 'knot-vue' },
    nodes: serializedNodes,
    edges: serializedEdges,
  };
}

function applyWorkflow(workflow: KnotWorkflow) {
  currentWorkflowId.value = workflow.id;
  workflowName.value = workflow.label || t('editor.defaultWorkflowName');
  workflowRef.value = workflow.ref ?? '';
  workflowStatus.value = (workflow.status as typeof workflowStatus.value) || 'draft';
  savedWorkflowStatus.value = workflowStatus.value;
  const def = workflow.definition;

  const loadedNodes: Node[] = (def.nodes ?? []).map((n) => {
    const data = n as Record<string, unknown>;
    const position = (data.position as { x: number; y: number } | undefined) ?? { x: 100, y: 100 };
    return {
      id: String(data.id ?? `n_${Date.now()}`),
      type: 'knot',
      position,
      data: {
        type: String(data.type ?? 'unknown'),
        label: String(data.label ?? ''),
        subtitle: String(data.subtitle ?? ''),
        config: (data.config as Record<string, unknown>) ?? {},
        notes: String(data.notes ?? ''),
        pinnedOutput: (data.pinnedOutput as Record<string, unknown> | undefined) ?? null,
        pinnedAt: (data.pinnedAt as string | undefined) ?? null,
      },
    };
  });
  const loadedEdges: Edge[] = (def.edges ?? []).map((e) => {
    const data = e as Record<string, unknown>;
    return buildKnotEdge({
      id: String(data.id ?? `e_${Date.now()}_${Math.random().toString(36).slice(2, 8)}`),
      source: String(data.source ?? ''),
      target: String(data.target ?? ''),
      sourceHandle: (data.sourceHandle as string | null | undefined) ?? 'main',
      targetHandle: (data.targetHandle as string | null | undefined) ?? undefined,
      animated: !!data.animated,
    });
  });

  // Replace the v-model arrays directly: setNodes/setEdges from useVueFlow
  // can race against the v-model binding when called synchronously inside
  // onMounted, and would leave the editor stuck on SAMPLE_NODES.
  // Reassigning the refs is the source of truth for the canvas.
  nodes.value = loadedNodes;
  edges.value = loadedEdges;
  // Keep VueFlow's internal store in sync (selection, zIndex, etc).
  setNodes(loadedNodes);
  setEdges(loadedEdges);
  refreshCanvasGeometry(loadedNodes.map((n) => n.id));
  // The v-model reassignment + setNodes will trigger @nodes-change /
  // @edges-change which would otherwise mark the freshly loaded
  // workflow as "Unsaved". Reset on the next tick to ignore those events.
  void nextTick(() => {
    dirty.value = false;
    restoreEditorUiSelection();
    restoreEditorViewport();
  });
}

async function saveWorkflow() {
  const activating =
    workflowStatus.value === 'active' && savedWorkflowStatus.value !== 'active';
  if (activating && riskSummary.value.hasCritical) {
    activationDialogOpen.value = true;
    return;
  }
  await persistWorkflow();
}

async function persistWorkflow(extra: Record<string, unknown> = {}) {
  saving.value = true;
  clearTopErrors();
  try {
    const result = await editorApi.saveWorkflow({
      id: currentWorkflowId.value ?? undefined,
      label: workflowName.value,
      status: workflowStatus.value,
      definition: buildDefinition(),
      ...extra,
    });
    currentWorkflowId.value = result.workflow.id;
    workflowName.value = result.workflow.label;
    workflowStatus.value = result.workflow.status as typeof workflowStatus.value;
    savedWorkflowStatus.value = workflowStatus.value;
    dirty.value = false;
    flashSuccess(t('editor.toastWorkflowSaved'));
  } catch (err) {
    setTopErrorFromCatch(err, t('editor.saveFallback'));
  } finally {
    saving.value = false;
  }
}

function onActivationDialogCancel() {
  workflowStatus.value = savedWorkflowStatus.value;
}

async function onActivationDialogConfirm() {
  await persistWorkflow({ critical_activation_acknowledged: true });
}

function focusActivationNode(nodeId: string) {
  activationDialogOpen.value = false;
  selectedId.value = nodeId;
  const list = nodes.value as Node[];
  const node = list.find((n) => n.id === nodeId);
  if (node) {
    void nextTick(() => {
      setNodes(list.map((n) => ({ ...n, selected: n.id === nodeId })));
    });
  }
}

function openSimulate(dryRun = true) {
  if (!currentWorkflowId.value) {
    setPlainTopError(t('editor.simulateBeforeSave'));
    return;
  }
  testSimulateDryRun.value = dryRun;
  testModalMode.value = 'simulate';
  replayFromNode.value = null;
  testModalOpen.value = true;
}

function onTestSplitRun(mode: 'dry' | 'real-small' | 'real-full'): void {
  if (mode === 'real-full') {
    void runWorkflow();
    return;
  }
  openSimulate(mode === 'dry');
}

async function executeSync(triggerData: Record<string, unknown>) {
  if (!currentWorkflowId.value) return;
  testModalOpen.value = false;
  simulating.value = true;
  simulateTimedOut.value = false;
  clearTopErrors();
  try {
    const result = await editorApi.simulateWorkflow({
      workflowId: currentWorkflowId.value,
      dryRun: testSimulateDryRun.value,
      fromNode: replayFromNode.value || undefined,
      inputData: triggerData,
    });
    simResult.value = {
      logs: result.logs as Array<Record<string, unknown>>,
      durationMs: result.durationMs,
      status: result.status,
      dryRun: result.dryRun,
    };
    try {
      localStorage.setItem(`knot.lastSim.${currentWorkflowId.value}`, JSON.stringify(simResult.value));
    } catch {}
  } catch (err) {
    const msg = err instanceof Error ? err.message : '';
    const isTimeout = /timeout|timed?\s*out|504|408/i.test(msg);
    if (isTimeout) {
      simulateTimedOut.value = true;
      setPlainTopError(t('editor.simulateTimedOut'));
    } else {
      setTopErrorFromCatch(err, t('editor.simulateFailedFallback'));
    }
  } finally {
    simulating.value = false;
  }
}

function dismissSimulationPanel(): void {
  simResult.value = null;
  fullTraceOpen.value = false;
  try {
    const id = currentWorkflowId.value;
    if (id != null && id > 0) {
      localStorage.removeItem(`knot.lastSim.${id}`);
    }
  } catch {
    /* ignore */
  }
}

function pinNodeOutput(nodeId: string) {
  const log = simResult.value?.logs.find((l) => (l as { nodeId?: string }).nodeId === nodeId) as { output?: unknown } | undefined;
  if (!log) return;
  const list = nodes.value as Node[];
  const idx = list.findIndex((n: Node) => n.id === nodeId);
  if (idx < 0) return;
  const next: Node = { ...list[idx] };
  next.data = { ...(next.data || {}), pinnedOutput: log.output };
  const updated = [...list];
  updated[idx] = next;
  nodes.value = updated as typeof nodes.value;
  setNodes(updated);
  dirty.value = true;
  flashSuccess(t('editor.toastOutputPinned', { nodeId }));
}

async function refreshRunQueuedCronHint(): Promise<void> {
  runQueuedHint.value = null;
  try {
    const health = await editorApi.health();
    const cron = health.doctor?.cron;
    if (!cron) {
      return;
    }
    if (!cron.registered || !cron.enabled) {
      runQueuedHint.value = t('editor.runQueuedCronDisabled');
      return;
    }
    if (cron.globalEnabled === false) {
      runQueuedHint.value = t('editor.runQueuedCronGlobalOff');
      return;
    }
    const stale = health.doctor?.cronStaleSeconds;
    if (stale === null || stale === undefined) {
      runQueuedHint.value = t('editor.runQueuedCronNever');
      return;
    }
    if (stale >= 900) {
      const minutes = Math.max(1, Math.round(stale / 60));
      runQueuedHint.value = t('editor.runQueuedCronStale', { minutes });
    }
  } catch {
    /* best-effort hint only */
  }
}

async function runWorkflow() {
  if (!currentWorkflowId.value) {
    setPlainTopError(t('editor.runBeforeSave'));
    return;
  }
  if (workflowStatus.value !== 'active') {
    setPlainTopError(t('editor.runInactive'));
    return;
  }
  saving.value = true;
  clearTopErrors();
  runQueuedHint.value = null;
  try {
    const result = await editorApi.executeWorkflow(currentWorkflowId.value);
    flashSuccess(t('editor.toastExecutionQueued', { id: result.executionId }));
    void refreshRunQueuedCronHint();
  } catch (err) {
    setTopErrorFromCatch(err, t('editor.runFallback'));
  } finally {
    saving.value = false;
  }
}

function retryAsRun() {
  simulateTimedOut.value = false;
  clearTopErrors();
  void runWorkflow();
}

async function openVersions() {
  if (!currentWorkflowId.value) {
    setPlainTopError(t('editor.versionsNeedSave'));
    return;
  }
  versionsOpen.value = true;
  versionsLoading.value = true;
  clearTopErrors();
  try {
    const result = await editorApi.listWorkflowVersions(currentWorkflowId.value);
    versions.value = result.versions;
  } catch (err) {
    setTopErrorFromCatch(err, t('editor.versionsFallback'));
  } finally {
    versionsLoading.value = false;
  }
}

async function rollbackToVersion(version: WorkflowVersion) {
  if (!currentWorkflowId.value) return;
  const ok = await confirmDialog.confirm({
    title: t('editor.rollbackConfirmTitle'),
    message: t('editor.rollbackConfirmMessage', { id: version.id }),
    danger: true,
  });
  if (!ok) return;
  versionsLoading.value = true;
  try {
    const result = await editorApi.rollbackWorkflow(currentWorkflowId.value, version.id);
    applyWorkflow(result.workflow);
    versionsOpen.value = false;
    flashSuccess(t('editor.rollbackSuccess', { id: version.id }));
  } catch (err) {
    setTopErrorFromCatch(err, t('editor.rollbackFallback'));
  } finally {
    versionsLoading.value = false;
  }
}

async function loadWorkflow(id: number) {
  loading.value = true;
  const shouldAutoLayout =
    typeof window !== 'undefined' &&
    new URLSearchParams(window.location.search).get('layout') === '1';
  try {
    const result = await editorApi.getWorkflow(id);
    applyWorkflow(result.workflow);
    if (shouldAutoLayout) {
      arrangeNodes({ animateLayout: true });
    } else {
      void nextTick(() => {
        fitView({ padding: 0.18, duration: 350 });
        refreshCanvasGeometry();
      });
    }
    try {
      const cached = localStorage.getItem(`knot.lastSim.${id}`);
      if (cached) simResult.value = JSON.parse(cached);
    } catch {
      // ignore
    }
  } catch (err) {
    const knot = extractKnotPayloadFromUnknown(err);
    const isNotFound =
      knot !== null &&
      (knot.code === 'not_found' ||
        /not found/i.test(knot.user_message) ||
        /not found/i.test(knot.technical_message ?? ''));
    if (isNotFound) {
      richExecutionError.value = null;
      saveError.value = t('editor.loadWorkflowNotFound', { id: String(id) });
    } else {
      setTopErrorFromCatch(err, t('editor.loadWorkflowFallback'));
    }
  } finally {
    loading.value = false;
  }
}

onMounted(() => {
  if (props.workflowId && props.workflowId > 0) {
    loadWorkflow(props.workflowId);
  }
});

watch(workflowName, () => {
  dirty.value = true;
});
</script>

<template>
  <div class="knot-editor-layout k-h-full k-min-w-0 k-overflow-hidden" data-knot-test="knot-editor-layout">
    <!-- Sidebar -->
    <aside
      class="knot-editor-layout__pane k-bg-knot-surface k-border-r k-border-knot-border k-flex k-flex-col k-min-h-0 k-min-w-0"
      data-knot-test="knot-editor-palette"
    >
      <div class="k-flex k-items-center k-gap-3 k-px-5 k-py-4 k-border-b k-border-knot-border">
        <div class="k-h-10 k-w-10 k-rounded-knot-sm k-bg-knot-hero k-flex k-items-center k-justify-center k-text-white k-shadow-knot-sm">
          <Workflow :size="18" />
        </div>
        <div>
          <div class="k-font-bold k-text-knot-text k-leading-tight">{{ t('app.title') }}</div>
          <div class="k-text-xs k-text-knot-text-soft">{{ t('editor.sidebarBrandSubtitle') }}</div>
        </div>
      </div>

      <div class="k-px-4 k-py-3 k-border-b k-border-knot-border">
        <div class="k-relative">
          <Search :size="14" class="k-absolute k-left-3 k-top-1/2 k--translate-y-1/2 k-text-knot-text-soft" />
          <input
            v-model="search"
            type="text"
            :placeholder="t('editor.paletteSearchPlaceholder')"
            class="k-w-full k-pl-9 k-pr-3 k-py-2 k-text-sm k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border focus:k-outline-none focus:k-border-knot-primary focus:k-ring-2 focus:k-ring-knot-primary/20 k-text-knot-text k-transition-all k-duration-knot k-ease-knot"
          />
        </div>
      </div>

      <div class="k-flex-1 k-min-h-0 k-overflow-y-auto k-px-3 k-py-3 k-space-y-5">
        <template v-for="section in PALETTE_SECTIONS" :key="section.titleKey">
        <section v-if="!search.trim() || filteredIds(section.ids).length > 0">
          <div class="k-px-2 k-text-[11px] k-uppercase k-tracking-wider k-text-knot-text-soft k-font-bold k-mb-2">
            {{ t(section.titleKey) }}
          </div>
          <div class="k-space-y-1.5">
            <template v-for="id in filteredIds(section.ids)" :key="id">
              <div
                v-if="NODE_REGISTRY[id]"
                role="button"
                :tabindex="isPaletteNodeAvailable(id) ? -1 : 0"
                :draggable="isPaletteNodeAvailable(id)"
                :data-knot-palette-node="id"
                :data-knot-palette-locked="isPaletteNodeAvailable(id) ? undefined : '1'"
                :title="isPaletteNodeAvailable(id) ? undefined : paletteUnavailableTitle(id)"
                :aria-label="isPaletteNodeAvailable(id) ? undefined : paletteUnavailableTitle(id)"
                @dragstart="onPaletteDragStart($event, id)"
                @click="onPaletteLockedClick(id)"
                @keydown.enter.prevent="onPaletteLockedClick(id)"
                @keydown.space.prevent="onPaletteLockedClick(id)"
                :class="[
                  'k-group k-flex k-items-center k-gap-2.5 k-px-2.5 k-py-2 k-rounded-knot-sm k-border k-border-transparent k-transition-all k-duration-knot k-ease-knot',
                  isPaletteNodeAvailable(id)
                    ? 'k-cursor-grab active:k-cursor-grabbing hover:k-border-knot-border-strong hover:k-bg-knot-surface-soft'
                    : 'k-opacity-60 k-cursor-pointer k-bg-knot-surface-soft hover:k-border-knot-warning/40',
                ]"
              >
                <div
                  class="k-h-8 k-w-8 k-rounded-knot-sm k-flex k-items-center k-justify-center k-text-white k-shadow-knot-xs k-shrink-0 k-relative"
                  :style="{
                    background:
                      'linear-gradient(135deg, ' +
                      NODE_REGISTRY[id].color +
                      ' 0%, ' +
                      NODE_REGISTRY[id].color +
                      'cc 100%)',
                  }"
                >
                  <component :is="NODE_REGISTRY[id].icon" :size="15" />
                  <span
                    v-if="!isPaletteNodeAvailable(id)"
                    class="k-absolute k--right-1 k--bottom-1 k-h-4 k-w-4 k-rounded-full k-bg-knot-warning k-text-white k-flex k-items-center k-justify-center"
                    aria-hidden="true"
                  >
                    <Lock :size="9" />
                  </span>
                </div>
                <div class="k-min-w-0 k-flex-1">
                  <div class="k-text-[13px] k-font-semibold k-text-knot-text k-leading-tight k-truncate">
                    {{ paletteNodeLabel(id) }}
                  </div>
                  <div class="k-text-[11px] k-text-knot-text-soft k-truncate">
                    {{
                      isPaletteNodeAvailable(id)
                        ? paletteNodeDescription(id)
                        : t('editor.paletteRequiresLicenseShort')
                    }}
                  </div>
                </div>
              </div>
            </template>
          </div>
        </section>
        </template>
      </div>

      <div class="k-px-4 k-py-3 k-border-t k-border-knot-border k-text-[11px] k-text-knot-text-soft k-flex k-items-center k-gap-2">
        <MousePointerClick :size="12" />
        {{ t('editor.dragPaletteHint') }}
      </div>
    </aside>

    <!-- Canvas + toolbar -->
    <main class="knot-editor-layout__pane k-relative k-bg-knot-bg k-flex k-flex-col k-min-h-0 k-min-w-0">
      <!-- Top toolbar — labels hide below xl so 1366×768 + side panes stay icon-first -->
      <div
        data-knot-test="knot-editor-toolbar"
        class="k-min-h-[3rem] k-min-w-0 k-max-w-full k-overflow-x-hidden k-px-3 lg:k-px-4 k-py-1.5 k-flex k-flex-wrap k-items-center k-justify-between k-gap-y-1 k-gap-x-2 k-bg-knot-surface k-border-b k-border-knot-border k-shadow-knot-xs k-z-10"
      >
        <div class="k-flex k-items-center k-gap-1.5 k-min-w-0 k-flex-1">
          <a
            :href="workflowsListUrl"
            class="k-inline-flex k-items-center k-gap-1 k-px-2 k-py-1 k-rounded-knot-sm k-border k-border-knot-border k-text-knot-text-muted hover:k-text-knot-text hover:k-border-knot-primary k-text-xs k-font-semibold k-flex-shrink-0"
            data-knot-test="editor-back-workflows"
          >
            <ArrowLeft :size="14" />
            <span class="k-hidden lg:k-inline">{{ t('editor.backToWorkflows') }}</span>
          </a>
          <input
            v-model="workflowName"
            class="k-text-base k-font-semibold k-text-knot-text k-bg-transparent k-border-0 k-outline-none focus:k-bg-knot-surface-soft k-rounded-knot-sm k-px-2 k-py-1 k-min-w-0 k-flex-1 k-truncate"
          />
          <select
            v-model="workflowStatus"
            @change="dirty = true"
            class="k-text-xs k-bg-knot-surface-soft k-border k-border-knot-border k-rounded-knot-pill k-px-2.5 k-py-1 k-text-knot-text-muted k-font-semibold focus:k-outline-none focus:k-border-knot-primary k-flex-shrink-0"
          >
            <option value="draft">{{ t('status.draft') }}</option>
            <option value="active">{{ t('status.active') }}</option>
            <option value="disabled">{{ t('status.disabled') }}</option>
            <option value="archived">{{ t('status.archived') }}</option>
          </select>
          <span
            v-if="dirty"
            :title="t('editor.unsaved')"
            class="k-inline-flex k-items-center k-gap-1 k-text-[11px] k-px-2 k-py-0.5 k-rounded-knot-pill k-bg-knot-warning-soft k-text-knot-warning k-font-semibold k-flex-shrink-0 k-max-w-[9rem] lg:k-max-w-none"
          >
            <span class="k-hidden lg:k-inline k-whitespace-nowrap">{{ t('editor.unsaved') }}</span>
            <span class="lg:k-hidden k-font-bold" aria-hidden="true">●</span>
          </span>
          <span
            v-else
            :title="t('editor.saved')"
            class="k-inline-flex k-items-center k-gap-1 k-text-[11px] k-px-2 k-py-0.5 k-rounded-knot-pill k-bg-knot-success-soft k-text-knot-success k-font-semibold k-flex-shrink-0 k-max-w-[9rem] lg:k-max-w-none"
          >
            <span class="k-hidden lg:k-inline k-whitespace-nowrap">{{ t('editor.saved') }}</span>
            <span class="lg:k-hidden k-font-bold" aria-hidden="true">✓</span>
          </span>
        </div>
        <div class="k-relative k-z-20 k-flex k-items-center k-gap-1.5 k-flex-shrink-0 k-flex-wrap k-justify-end">
          <button
            @click="undoChange"
            :disabled="!history.canUndo()"
            :title="t('editor.toolbarUndoTitle')"
            class="k-inline-flex k-items-center k-px-2.5 k-py-1.5 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border k-text-knot-text k-text-sm hover:k-border-knot-primary disabled:k-opacity-40"
          ><Undo2 :size="14" /></button>
          <button
            @click="redoChange"
            :disabled="!history.canRedo()"
            :title="t('editor.toolbarRedoTitle')"
            class="k-inline-flex k-items-center k-px-2.5 k-py-1.5 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border k-text-knot-text k-text-sm hover:k-border-knot-primary disabled:k-opacity-40"
          ><Redo2 :size="14" /></button>
          <button
            @click="onToolbarArrangeClick"
            :title="t('editor.toolbarArrangeTitle')"
            :aria-label="t('editor.toolbarArrangeAria')"
            class="k-inline-flex k-items-center k-px-2.5 k-py-1.5 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border k-text-knot-text k-text-sm hover:k-border-knot-primary"
          ><LayoutTemplate :size="14" /></button>
          <button
            data-knot-test="knot-editor-edit-json"
            @click="openJsonDialog"
            :title="t('editor.editJsonTitle')"
            :aria-label="t('editor.editJson')"
            class="k-inline-flex k-items-center k-px-2.5 k-py-1.5 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border k-text-knot-text k-text-sm hover:k-border-knot-primary"
          ><Braces :size="14" /></button>
          <button
            @click="openVersions"
            :disabled="saving || !currentWorkflowId"
            :title="t('editor.toolbarVersionsTitle')"
            class="k-inline-flex k-items-center k-gap-1.5 k-px-2.5 k-py-1.5 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border-strong k-text-knot-text k-text-sm k-font-semibold hover:k-bg-knot-surface-soft hover:k-border-knot-primary hover:k-text-knot-primary disabled:k-opacity-50 disabled:k-cursor-not-allowed k-transition-all k-duration-knot k-ease-knot k-whitespace-nowrap"
          >
            <History :size="14" />
            <span class="k-hidden xl:k-inline">{{ t('editor.versions') }}</span>
          </button>
          <TestSplitButton
            v-if="riskSummary.worstLevel === 'critical' && currentWorkflowId && !liveIssues.some((i) => i.severity === 'error')"
            :risk-level="riskSummary.worstLevel"
            :workflow-ref="workflowRef || 'YES'"
            @run="onTestSplitRun"
          />
          <button
            v-else
            @click="openSimulate(true)"
            :disabled="saving || !currentWorkflowId || liveIssues.some((i) => i.severity === 'error')"
            class="k-inline-flex k-items-center k-gap-1.5 k-px-2.5 k-py-1.5 k-rounded-knot-sm k-bg-knot-info-soft k-border k-border-knot-info k-text-knot-info k-text-sm k-font-semibold hover:k-bg-knot-info hover:k-text-white disabled:k-opacity-50 disabled:k-cursor-not-allowed k-transition-all k-duration-knot k-ease-knot k-whitespace-nowrap"
            :aria-label="t('editor.simulate')"
            :title="t('editor.simulatePartialDryRunHint')"
          >
            <FlaskConical :size="14" />
            <span class="k-hidden xl:k-inline">{{ t('editor.simulate') }}</span>
          </button>
          <p
            v-if="workflowUsesSlowConnectors && currentWorkflowId"
            class="k-hidden xl:k-block k-max-w-[12rem] k-text-xs k-text-knot-warning k-m-0 k-mr-1 k-truncate"
            role="note"
          >
            {{ t('editor.runSlowProfileWarning') }}
          </p>
          <button
            @click="runWorkflow"
            :disabled="saving || !currentWorkflowId"
            :title="workflowUsesSlowConnectors ? t('editor.runSlowProfileWarning') : t('editor.toolbarRunTitle')"
            class="k-inline-flex k-items-center k-gap-1.5 k-px-2.5 k-py-1.5 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border-strong k-text-knot-text k-text-sm k-font-semibold hover:k-bg-knot-surface-soft hover:k-border-knot-primary hover:k-text-knot-primary disabled:k-opacity-50 disabled:k-cursor-not-allowed k-transition-all k-duration-knot k-ease-knot k-whitespace-nowrap"
          >
            <Play :size="14" />
            <span class="k-hidden xl:k-inline">{{ t('editor.run') }}</span>
          </button>
          <button
            @click="saveWorkflow"
            :disabled="saving || liveIssues.some((i) => i.severity === 'error')"
            :title="t('editor.toolbarSaveTitle')"
            class="k-inline-flex k-items-center k-gap-1.5 k-px-3 k-py-1.5 k-rounded-knot-sm k-bg-knot-hero k-text-white k-text-sm k-font-semibold k-shadow-[0_8px_18px_rgba(99,102,241,0.35)] hover:k-shadow-[0_12px_24px_rgba(99,102,241,0.45)] disabled:k-opacity-60 k-transition-all k-duration-knot k-ease-knot k-whitespace-nowrap"
          >
            <Loader2 v-if="saving" :size="14" class="k-animate-spin" />
            <Save v-else :size="14" />
            <span class="k-hidden xl:k-inline">{{ saving ? t('editor.saving') : t('editor.save') }}</span>
          </button>
        </div>
      </div>

      <div
        v-if="showStarterBanner"
        class="k-px-5 k-py-2.5 k-bg-knot-info-soft k-border-b k-border-knot-border k-flex k-items-start k-gap-3 k-text-sm k-text-knot-text"
        role="status"
      >
        <AlertCircle :size="18" class="k-text-knot-info k-shrink-0 k-mt-0.5" />
        <div class="k-flex-1 k-min-w-0">
          <p class="k-leading-snug">{{ t('editor.starterHint') }}</p>
          <div class="k-mt-2 k-flex k-flex-wrap k-items-center k-gap-2">
            <button
              type="button"
              class="k-px-3 k-py-1.5 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border k-text-knot-text k-text-xs k-font-semibold hover:k-border-knot-primary"
              @click="clearStarterCanvas"
            >
              {{ t('editor.clearCanvas') }}
            </button>
            <button
              type="button"
              class="k-px-2 k-py-1 k-text-xs k-text-knot-text-muted hover:k-text-knot-text k-underline"
              @click="dismissStarterHint"
            >
              {{ t('editor.starterHintDismiss') }}
            </button>
          </div>
        </div>
        <button
          type="button"
          class="k-p-1 k-rounded-knot-sm k-text-knot-text-muted hover:k-bg-knot-surface hover:k-text-knot-text"
          :aria-label="t('editor.starterHintDismiss')"
          @click="dismissStarterHint"
        >
          <X :size="16" />
        </button>
      </div>

      <div v-if="props.workflowId" class="k-px-4 k-py-2 k-border-b k-border-knot-border">
        <MigrationBanner :workflow-id="props.workflowId" />
      </div>

      <div
        class="k-relative k-flex-1 k-min-h-0"
        @dragover="onCanvasDragOver"
        @drop="onCanvasDrop"
      >
        <div v-if="loading" class="k-absolute k-inset-0 k-flex k-items-center k-justify-center k-bg-knot-bg/80 k-z-20 k-text-knot-text-muted k-text-sm k-gap-2">
          <Loader2 :size="16" class="k-animate-spin" /> {{ t('editor.loadingWorkflow') }}
        </div>
        <VueFlow
          class="k-h-full k-w-full"
          v-model:nodes="nodes"
          v-model:edges="edges"
          :node-types="nodeTypes"
          :edge-types="edgeTypes"
          :default-edge-options="{ type: 'knot', markerEnd: defaultMarker }"
          :default-viewport="{ zoom: 0.75, x: 40, y: 40 }"
          :min-zoom="0.4"
          :max-zoom="1.6"
          :select-nodes-on-drag="false"
          :connection-mode="ConnectionMode.Loose"
          :connect-on-click="false"
          :connection-line-style="connectionLineStyle"
          :is-valid-connection="isValidConnection"
          elevate-edges-on-select
          snap-to-grid
          :snap-grid="[16, 16]"
          @connect-start="onConnectStart"
          @connect-end="onConnectEnd"
          @nodes-initialized="onCanvasNodesInitialized"
          @node-click="onNodeClick"
          @pane-click="onPaneClick"
          @nodes-change="dirty = true"
          @edges-change="dirty = true"
        >
          <Background :variant="BackgroundVariant.Dots" pattern-color="rgba(99, 102, 241, 0.18)" :gap="22" :size="1.4" />
          <Controls position="bottom-left" :show-zoom="true" :show-fit-view="true" :show-interactive="false" />
          <MiniMap
            position="bottom-right"
            pannable
            zoomable
            mask-color="rgba(15, 23, 42, 0.6)"
            :node-color="(node) => minimapNodeColor(node as Node)"
            :node-stroke-color="(node) => minimapNodeColor(node as Node)"
            :node-stroke-width="2"
          />
        </VueFlow>
        <div
          v-if="showCanvasLegend"
          class="k-absolute k-top-3 k-right-3 k-z-10 k-bg-knot-surface/95 k-border k-border-knot-border k-rounded-knot-sm k-px-3 k-py-2 k-text-[10px] k-text-knot-text-soft k-shadow-knot-sm k-space-y-1"
        >
          <div class="k-flex k-items-center k-justify-between k-gap-3 k-mb-1">
            <span class="k-font-semibold k-text-knot-text">{{ t('editor.legendTitle') }}</span>
            <button type="button" class="k-text-knot-text-muted hover:k-text-knot-text" @click="showCanvasLegend = false">
              <X :size="12" />
            </button>
          </div>
          <div class="k-flex k-items-center k-gap-2"><span class="k-w-3 k-h-0.5 k-rounded k-bg-[var(--knot-edge-main)]" /> {{ t('editor.handles.main') }}</div>
          <div class="k-flex k-items-center k-gap-2"><span class="k-w-3 k-h-0.5 k-rounded k-bg-[var(--knot-edge-true)]" /> {{ t('editor.handles.true') }}</div>
          <div class="k-flex k-items-center k-gap-2"><span class="k-w-3 k-h-0.5 k-rounded k-bg-[var(--knot-edge-false)]" /> {{ t('editor.handles.false') }}</div>
          <div class="k-flex k-items-center k-gap-2"><span class="k-w-3 k-h-0.5 k-rounded k-bg-[var(--knot-edge-error)]" /> {{ t('editor.handles.error') }}</div>
        </div>
      </div>

      <ProblemsPanel :issues="liveIssues" @jump="onJumpToNode" @copy-fix="copyProblemsFixForChatbot" />

      <transition name="k-toast">
        <div
          v-if="successFlash"
          class="k-absolute k-bottom-4 k-left-1/2 k--translate-x-1/2 k-bg-knot-success-soft k-text-knot-success k-px-3 k-py-2 k-rounded-knot-sm k-text-sm k-shadow-knot-md k-flex k-items-center k-gap-2"
        >
          <CheckCircle2 :size="14" />
          {{ successFlash }}
        </div>
      </transition>

      <transition name="k-toast">
        <div
          v-if="richExecutionError || saveError"
          class="k-absolute k-bottom-4 k-left-1/2 k--translate-x-1/2 k-max-w-lg k-min-w-[280px] k-shadow-knot-md"
        >
          <ExecutionErrorPanel
            v-if="richExecutionError"
            :payload="richExecutionError"
          />
          <div
            v-else
            class="k-rounded-knot-sm k-bg-knot-danger-soft k-text-knot-danger k-px-3 k-py-2 k-text-sm"
          >
            <div class="k-flex k-items-center k-gap-2">
              <AlertCircle :size="14" />
              {{ saveError }}
            </div>
            <div v-if="simulateTimedOut" class="k-mt-2 k-flex k-items-center k-gap-2">
              <button
                type="button"
                class="k-inline-flex k-items-center k-gap-1 k-px-2.5 k-py-1 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border k-text-knot-text k-text-xs k-font-semibold hover:k-border-knot-primary"
                @click="retryAsRun"
              >
                <Play :size="12" />
                {{ t('editor.retryAsRun') }}
              </button>
              <span class="k-text-[11px] k-text-knot-text-muted">{{ t('editor.retryAsRunHint') }}</span>
            </div>
          </div>
        </div>
      </transition>

      <transition name="k-toast">
        <div
          v-if="runQueuedHint"
          class="k-absolute k-bottom-4 k-left-1/2 k--translate-x-1/2 k-max-w-lg k-min-w-[280px] k-rounded-knot-sm k-bg-knot-warning-soft k-text-knot-warning k-px-3 k-py-2 k-text-sm k-shadow-knot-md"
          data-knot-test="editor-run-queued-cron-hint"
        >
          {{ runQueuedHint }}
        </div>
      </transition>
    </main>

    <WorkflowActivationDialog
      v-model="activationDialogOpen"
      :workflow-label="workflowName"
      :risk-level="riskSummary.worstLevel"
      :summary="activationSummaryText"
      :side-effects="riskSummary.sideEffects"
      :critical-nodes="riskSummary.criticalNodes"
      :schedule-active="scheduleActive"
      @confirm="onActivationDialogConfirm"
      @update:model-value="(v) => { if (!v) onActivationDialogCancel(); }"
      @focus-node="focusActivationNode"
    />
    <TestDataModal
      :open="testModalOpen"
      :workflow-id="currentWorkflowId"
      :mode="testModalMode"
      @close="testModalOpen = false"
      @submit="executeSync"
    />
    <FullTraceModal
      :open="fullTraceOpen"
      :logs="(simResult?.logs ?? []) as any"
      :duration-ms="simResult?.durationMs ?? 0"
      :node-labels="simulationNodeLabelMap"
      :title="t('editor.simulationTraceTitle')"
      @close="fullTraceOpen = false"
    />

    <!-- Inspector -->
    <aside
      v-if="!simResult"
      data-knot-test="knot-inspector-aside"
      class="knot-editor-layout__pane k-bg-knot-surface k-border-l k-border-knot-border k-flex k-flex-col k-min-h-0 k-min-w-0"
    >
      <div class="k-px-5 k-py-4 k-border-b k-border-knot-border k-flex k-items-center k-gap-2">
        <Settings2 :size="16" class="k-text-knot-text-muted" />
        <div>
          <div class="k-text-sm k-font-bold k-text-knot-text">{{ t('editor.inspectorTitle') }}</div>
          <div class="k-text-xs k-text-knot-text-soft">
            {{ selectedNode ? t('editor.inspectorSubtitleSelected') : t('editor.inspectorSubtitleEmpty') }}
          </div>
        </div>
      </div>

      <div v-if="!selectedNode" class="k-px-5 k-py-6 k-flex-1 k-overflow-y-auto k-space-y-3 k-text-sm">
        <div class="k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-dashed k-border-knot-border k-p-4 k-text-knot-text-soft k-text-center">
          {{ t('editor.inspectorEmptyCanvas') }}
        </div>
        <div class="k-pt-3 k-border-t k-border-knot-border k-space-y-2 k-text-knot-text-muted">
          <div class="k-flex k-justify-between">
            <span class="k-text-knot-text-soft">{{ t('editor.metaWorkflowId') }}</span>
            <span class="k-font-mono k-text-knot-text">{{ currentWorkflowId ?? t('editor.commonEmDash') }}</span>
          </div>
          <div class="k-flex k-justify-between">
            <span class="k-text-knot-text-soft">{{ t('editor.metaStatus') }}</span>
            <span class="k-font-mono k-text-knot-text">{{ workflowStatus }}</span>
          </div>
          <div class="k-flex k-justify-between">
            <span class="k-text-knot-text-soft">{{ t('editor.metaNodes') }}</span>
            <span class="k-font-mono k-text-knot-text">{{ nodes.length }}</span>
          </div>
          <div class="k-flex k-justify-between">
            <span class="k-text-knot-text-soft">{{ t('editor.metaEdges') }}</span>
            <span class="k-font-mono k-text-knot-text">{{ edges.length }}</span>
          </div>
        </div>
      </div>

      <div v-else class="k-px-5 k-py-5 k-flex-1 k-min-w-0 k-overflow-y-auto k-overflow-x-hidden k-space-y-5">
        <div class="k-flex k-items-center k-gap-3">
          <div
            class="k-h-11 k-w-11 k-rounded-knot-sm k-flex k-items-center k-justify-center k-text-white k-shadow-knot-sm"
            :style="{
              background:
                'linear-gradient(135deg, ' +
                (selectedMeta?.color ?? '#64748b') +
                ' 0%, ' +
                (selectedMeta?.color ?? '#64748b') +
                'cc 100%)',
            }"
          >
            <component :is="selectedMeta?.icon ?? Settings2" :size="18" />
          </div>
          <div class="k-min-w-0 k-flex-1">
            <div class="k-text-sm k-font-bold k-text-knot-text k-truncate">{{ selectedMeta?.label }}</div>
            <div class="k-text-xs k-font-mono k-text-knot-text-soft k-truncate">{{ selectedNode.id }}</div>
          </div>
        </div>

        <div class="k-space-y-2">
          <label class="k-block k-text-[11px] k-font-bold k-uppercase k-tracking-wider k-text-knot-text-soft">{{ t('editor.fieldLabelCaption') }}</label>
          <input
            :value="(selectedNode.data?.label as string) ?? ''"
            @input="(e) => updateSelectedField('label', (e.target as HTMLInputElement).value)"
            class="k-w-full k-px-3 k-py-2 k-text-sm k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border focus:k-outline-none focus:k-border-knot-primary focus:k-ring-2 focus:k-ring-knot-primary/20 k-text-knot-text k-transition-all k-duration-knot k-ease-knot"
          />
        </div>

        <div class="k-space-y-2">
          <label class="k-block k-text-[11px] k-font-bold k-uppercase k-tracking-wider k-text-knot-text-soft">{{ t('editor.fieldSubtitleCaption') }}</label>
          <input
            :value="(selectedNode.data?.subtitle as string) ?? ''"
            @input="(e) => updateSelectedField('subtitle', (e.target as HTMLInputElement).value)"
            class="k-w-full k-px-3 k-py-2 k-text-sm k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border focus:k-outline-none focus:k-border-knot-primary focus:k-ring-2 focus:k-ring-knot-primary/20 k-text-knot-text k-transition-all k-duration-knot k-ease-knot"
          />
        </div>

        <div class="k-space-y-2">
          <label class="k-block k-text-[11px] k-font-bold k-uppercase k-tracking-wider k-text-knot-text-soft">{{ t('editor.fieldTypeCaption') }}</label>
          <div class="k-px-3 k-py-2 k-text-sm k-font-mono k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text-muted">
            {{ selectedNode.data?.type ?? 'unknown' }}
          </div>
        </div>

        <WebhookPanel
          v-if="selectedNode.data?.type === 'trigger.webhook' && currentWorkflowId"
          :workflow-id="currentWorkflowId"
        />

        <NodeInspectorBody
          :node-type="String(selectedNode.data?.type ?? '')"
          :config="(selectedNode.data?.config as Record<string, unknown>) ?? {}"
          :notes="String(selectedNode.data?.notes ?? '')"
          :schema="resolvedInspectorSchema"
          :workflow-id="currentWorkflowId"
          :connector-catalog-ready="connectorDescriptorsReady"
          :dolibarr-sm-focus-tick="dolibarrSmInspectorFocusTick"
          @update:config="setSelectedConfig"
          @update:notes="setSelectedNotes"
        />

        <div class="k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-p-3 k-space-y-2">
          <div>
            <div class="k-text-sm k-font-semibold k-text-knot-text">{{ t('editor.mappingTitle') }}</div>
            <div class="k-text-xs k-text-knot-text-muted">
              {{ t('editor.mappingSubtitle') }}
            </div>
          </div>
          <div v-if="!upstreamDataPaths.length" class="k-text-xs k-text-knot-text-soft k-p-2 k-rounded-knot-sm k-border k-border-dashed k-border-knot-border">
            {{ t('editor.mappingUpstreamEmpty') }}
          </div>
          <div v-else class="k-space-y-1.5 k-max-h-40 k-overflow-y-auto">
            <div
              v-for="item in upstreamDataPaths"
              :key="item.source + item.path"
              draggable="true"
              @dragstart="onPathDragStart($event, item.expression)"
              class="k-cursor-grab k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border k-px-2 k-py-1.5 hover:k-border-knot-primary"
              :title="item.expression"
            >
              <div class="k-text-[11px] k-font-mono k-text-knot-primary k-truncate">{{ item.expression }}</div>
              <div class="k-text-[10px] k-text-knot-text-muted k-truncate">{{ item.source }} → {{ item.preview }}</div>
            </div>
          </div>
        </div>

        <div class="k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-p-3 k-space-y-2">
          <label class="k-flex k-items-start k-gap-2 k-cursor-pointer">
            <input
              type="checkbox"
              :checked="Boolean((selectedNode.data?.config as Record<string, unknown> | undefined)?.continueOnFail)"
              @change="(e) => updateSelectedConfigFlag('continueOnFail', (e.target as HTMLInputElement).checked)"
              class="k-mt-1"
            />
            <span>
              <span class="k-block k-text-sm k-font-semibold k-text-knot-text">{{ t('editor.continueOnFail') }}</span>
              <span class="k-block k-text-xs k-text-knot-text-muted">
                {{ t('editor.continueOnFailHint') }}
              </span>
            </span>
          </label>

          <div class="k-pt-2 k-border-t k-border-knot-border k-space-y-1">
            <label class="k-block k-text-[11px] k-font-bold k-uppercase k-tracking-wider k-text-knot-text-soft">{{
              t('inspector.idempotencyLabel')
            }}</label>
            <input
              :value="String((selectedNode.data?.config as Record<string, unknown> | undefined)?.idempotencyKey ?? '')"
              @input="(e) => updateSelectedConfigString('idempotencyKey', (e.target as HTMLInputElement).value)"
              :placeholder="idempotencyUi.placeholder"
              class="k-w-full k-px-2 k-py-1.5 k-text-xs k-font-mono k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border k-text-knot-text"
            />
            <p class="k-text-[10px] k-text-knot-text-muted">{{ idempotencyUi.hint }}</p>
          </div>

          <button
            v-if="selectedNode.data?.type === 'trigger.cron'"
            @click="scheduleOpen = true"
            class="k-w-full k-mt-2 k-px-2 k-py-1.5 k-rounded-knot-sm k-bg-knot-primary k-text-white k-text-xs k-font-semibold"
          >
            {{ t('editor.cronScheduleVisual') }}
          </button>
        </div>

        <div class="k-space-y-2">
          <div class="k-flex k-items-center k-justify-between">
            <label class="k-block k-text-[11px] k-font-bold k-uppercase k-tracking-wider k-text-knot-text-soft">{{ t('editor.pinnedOutput') }}</label>
            <button
              v-if="selectedNode.data?.pinnedOutput"
              @click="updateSelectedPinnedOutput('')"
              class="k-text-[11px] k-font-semibold k-text-violet-300 hover:k-text-violet-200"
            >
              {{ t('editor.unpinOutput') }}
            </button>
          </div>
          <textarea
            :value="selectedNode.data?.pinnedOutput ? JSON.stringify(selectedNode.data?.pinnedOutput, null, 2) : ''"
            @input="(e) => updateSelectedPinnedOutput((e.target as HTMLTextAreaElement).value)"
            rows="5"
            placeholder="{ }"
            class="k-w-full k-px-3 k-py-2 k-text-xs k-font-mono k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border focus:k-outline-none focus:k-border-violet-400 focus:k-ring-2 focus:k-ring-violet-400/20 k-text-knot-text k-transition-all"
          ></textarea>
          <p class="k-text-xs k-text-knot-text-muted">
            {{ t('editor.pinnedOutputHint') }}
          </p>
        </div>

        <div class="k-pt-3 k-border-t k-border-knot-border">
          <button
            @click="deleteSelected"
            class="k-w-full k-inline-flex k-items-center k-justify-center k-gap-2 k-px-3 k-py-2 k-rounded-knot-sm k-border k-border-knot-danger/30 k-bg-knot-danger-soft k-text-knot-danger k-text-sm k-font-semibold hover:k-bg-knot-danger hover:k-text-white k-transition-colors k-duration-knot k-ease-knot"
          >
            <Trash2 :size="14" />
            {{ t('editor.deleteNode') }}
          </button>
        </div>
      </div>
    </aside>

    <aside
      v-else
      class="knot-editor-layout__pane k-bg-knot-surface k-border-l k-border-knot-border k-flex k-flex-col k-min-h-0 k-min-w-0"
      data-knot-test="knot-simulation-aside"
    >
      <SimulationSidePanel
        :logs="(simResult?.logs ?? []) as any"
        :duration-ms="simResult?.durationMs ?? 0"
        :status="simResult?.status ?? 'success'"
        :dry-run="simResult?.dryRun ?? true"
        :node-labels="simulationNodeLabelMap"
        @close="dismissSimulationPanel"
        @open-full="fullTraceOpen = true"
        @pin="pinNodeOutput"
      />
    </aside>

    <Teleport to="body">
      <div
        v-if="versionsOpen"
        class="k-fixed k-inset-0 k-z-[9999] k-bg-black/50 k-backdrop-blur-sm k-flex k-justify-end"
        @click.self="versionsOpen = false"
      >
        <aside class="k-w-full k-max-w-md k-h-full k-bg-knot-surface k-border-l k-border-knot-border k-shadow-knot-lg k-flex k-flex-col">
          <div class="k-px-5 k-py-4 k-border-b k-border-knot-border k-flex k-items-center k-justify-between">
            <div>
              <h2 class="k-text-lg k-font-bold k-text-knot-text">{{ t('editor.versionsDrawerTitle') }}</h2>
              <p class="k-text-xs k-text-knot-text-muted">{{ t('editor.versionsDrawerLead') }}</p>
            </div>
            <button class="k-text-knot-text-muted hover:k-text-knot-text" @click="versionsOpen = false">×</button>
          </div>
          <div class="k-flex-1 k-overflow-y-auto k-p-4 k-space-y-3">
            <div v-if="versionsLoading" class="k-text-sm k-text-knot-text-muted k-flex k-items-center k-gap-2">
              <Loader2 :size="14" class="k-animate-spin" /> {{ t('editor.versionsLoading') }}
            </div>
            <div v-else-if="!versions.length" class="k-text-sm k-text-knot-text-muted k-p-4 k-rounded-knot-sm k-bg-knot-surface-soft">
              {{ t('editor.versionsEmpty') }}
            </div>
            <template v-else>
              <div
                v-for="version in versions"
                :key="version.id"
                class="k-rounded-knot-sm k-border k-border-knot-border k-bg-knot-surface-soft k-p-3 k-space-y-2"
              >
                <div class="k-flex k-items-center k-justify-between k-gap-2">
                  <div class="k-min-w-0">
                    <div class="k-text-sm k-font-semibold k-text-knot-text k-truncate">
                      {{ version.label || t('editor.snapshotLabel', { id: version.id }) }}
                    </div>
                    <div class="k-text-xs k-text-knot-text-muted">
                      {{ new Date(version.createdAt.replace(' ', 'T')).toLocaleString() }}
                    </div>
                  </div>
                  <span
                    v-if="version.named"
                    class="k-text-[10px] k-font-bold k-rounded-knot-pill k-bg-knot-primary-soft k-text-knot-primary k-px-2 k-py-0.5"
                  >
                    {{ t('editor.namedVersionBadge') }}
                  </span>
                </div>
                <div class="k-flex k-gap-2">
                  <button
                    @click="rollbackToVersion(version)"
                    class="k-flex-1 k-text-xs k-font-semibold k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border k-text-knot-text hover:k-border-knot-primary hover:k-text-knot-primary k-px-2 k-py-1.5"
                  >
                    {{ t('editor.rollback') }}
                  </button>
                  <a
                    :href="`?mode=diff&workflow_id=${currentWorkflowId}`"
                    class="k-flex-1 k-text-xs k-font-semibold k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border k-text-knot-text hover:k-border-knot-primary hover:k-text-knot-primary k-px-2 k-py-1.5 k-text-center k-no-underline"
                  >
                    {{ t('editor.compare') }}
                  </a>
                </div>
              </div>
            </template>
          </div>
        </aside>
      </div>
    </Teleport>

    <Teleport to="body">
      <div
        v-if="jsonDialogOpen"
        class="k-fixed k-inset-0 k-z-[9999] k-bg-black/50 k-backdrop-blur-sm k-flex k-items-center k-justify-center k-p-4"
        @click.self="jsonDialogOpen = false"
      >
        <div
          data-knot-test="knot-editor-json-dialog"
          class="k-w-full k-max-w-3xl k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-lg k-shadow-knot-lg k-overflow-hidden k-flex k-flex-col"
          role="dialog"
          :aria-label="t('editor.editJsonTitle')"
        >
          <div class="k-px-5 k-py-4 k-border-b k-border-knot-border k-flex k-items-center k-justify-between">
            <div>
              <h2 class="k-text-lg k-font-bold k-text-knot-text">{{ t('editor.editJsonTitle') }}</h2>
              <p class="k-text-xs k-text-knot-text-muted">{{ t('editor.editJsonLead') }}</p>
            </div>
            <button class="k-text-knot-text-muted hover:k-text-knot-text" @click="jsonDialogOpen = false">×</button>
          </div>
          <div class="k-p-4 k-flex k-flex-col k-gap-3">
            <label for="knot-editor-json-textarea" class="k-sr-only">{{ t('editor.editJsonTitle') }}</label>
            <textarea
              id="knot-editor-json-textarea"
              v-model="jsonDialogText"
              data-knot-test="knot-editor-json-textarea"
              spellcheck="false"
              class="k-w-full k-h-80 k-resize-y k-rounded-knot-sm k-border k-border-knot-border k-bg-knot-surface-soft k-p-3 k-font-mono k-text-xs k-text-knot-text focus:k-outline-none focus:k-border-knot-primary"
            ></textarea>
            <p
              v-if="jsonDialogError"
              data-knot-test="knot-editor-json-error"
              class="k-m-0 k-rounded-knot-sm k-border k-border-knot-danger k-bg-knot-danger-soft k-p-2 k-text-sm k-text-knot-danger"
            >
              {{ jsonDialogError }}
            </p>
            <div class="k-flex k-justify-end k-gap-2">
              <button
                class="k-inline-flex k-items-center k-px-3 k-py-1.5 k-rounded-knot-sm k-bg-knot-surface k-border k-border-knot-border k-text-knot-text k-text-sm hover:k-border-knot-primary"
                @click="jsonDialogOpen = false"
              >
                {{ t('editor.editJsonCancel') }}
              </button>
              <button
                data-knot-test="knot-editor-json-apply"
                class="k-inline-flex k-items-center k-gap-1.5 k-px-3 k-py-1.5 k-rounded-knot-sm k-bg-knot-hero k-text-white k-text-sm k-font-semibold hover:k-opacity-90"
                @click="applyJsonDialog"
              >
                {{ t('editor.editJsonApply') }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Teleport>

    <Teleport to="body">
      <div v-if="scheduleOpen" class="k-fixed k-inset-0 k-z-[9999] k-bg-black/50 k-backdrop-blur-sm k-flex k-items-center k-justify-center k-p-4" @click.self="scheduleOpen = false">
        <div class="k-w-full k-max-w-lg k-bg-knot-surface k-border k-border-knot-border k-rounded-knot-lg k-shadow-knot-lg k-overflow-hidden">
          <div class="k-px-5 k-py-4 k-border-b k-border-knot-border k-flex k-items-center k-justify-between">
            <h2 class="k-text-lg k-font-bold k-text-knot-text">{{ t('editor.scheduleModalTitle') }}</h2>
            <button class="k-text-knot-text-muted" @click="scheduleOpen = false">×</button>
          </div>
          <div class="k-p-5 k-space-y-3">
            <div>
              <label class="k-block k-text-[11px] k-font-bold k-uppercase k-tracking-wider k-text-knot-text-soft k-mb-1">{{ t('editor.schedulePresets') }}</label>
              <div class="k-flex k-flex-wrap k-gap-1.5">
                <button
                  v-for="p in schedulePresets" :key="p.cron"
                  @click="scheduleDraft.cronExpression = p.cron"
                  :class="['k-px-2 k-py-1 k-rounded-knot-sm k-text-[11px] k-font-semibold', scheduleDraft.cronExpression === p.cron ? 'k-bg-knot-primary k-text-white' : 'k-bg-knot-surface-soft k-text-knot-text-muted hover:k-text-knot-primary']"
                >{{ p.label }}</button>
              </div>
            </div>
            <div class="k-grid k-grid-cols-2 k-gap-2">
              <div>
                <label class="k-block k-text-[11px] k-font-bold k-text-knot-text-soft k-mb-1">{{ t('editor.scheduleCronExpression') }}</label>
                <input v-model="scheduleDraft.cronExpression" class="k-w-full k-px-2 k-py-1.5 k-text-sm k-font-mono k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text" />
              </div>
              <div>
                <label class="k-block k-text-[11px] k-font-bold k-text-knot-text-soft k-mb-1">{{ t('editor.scheduleTimezone') }}</label>
                <input v-model="scheduleDraft.timezone" class="k-w-full k-px-2 k-py-1.5 k-text-sm k-rounded-knot-sm k-bg-knot-surface-soft k-border k-border-knot-border k-text-knot-text" />
              </div>
            </div>
            <label class="k-flex k-items-center k-gap-2 k-text-sm k-text-knot-text-muted">
              <input type="checkbox" v-model="scheduleDraft.isActive" /> {{ t('editor.scheduleActive') }}
            </label>
          </div>
          <div class="k-px-5 k-py-3 k-border-t k-border-knot-border k-flex k-justify-end k-gap-2">
            <button @click="scheduleOpen = false" class="k-px-3 k-py-1.5 k-text-sm k-text-knot-text-muted">{{ t('editor.scheduleCancel') }}</button>
            <button @click="saveSchedule" class="k-px-3 k-py-1.5 k-text-sm k-rounded-knot-sm k-bg-knot-primary k-text-white">{{ t('editor.scheduleSave') }}</button>
          </div>
        </div>
      </div>
    </Teleport>
  </div>
</template>

<style>
.vue-flow__background { background: var(--knot-color-bg) !important; }

.vue-flow__controls {
  background: var(--knot-color-surface);
  border: 1px solid var(--knot-color-border);
  border-radius: var(--knot-radius-sm);
  box-shadow: var(--knot-shadow-sm);
  overflow: hidden;
  display: flex;
  flex-direction: column;
}
.vue-flow__controls-button {
  background: var(--knot-color-surface);
  border: none;
  border-bottom: 1px solid var(--knot-color-border);
  color: var(--knot-color-text-muted);
  width: 32px;
  height: 32px;
  transition: all 200ms cubic-bezier(0.22, 1, 0.36, 1);
}
.vue-flow__controls-button:last-child { border-bottom: none; }
.vue-flow__controls-button:hover {
  background: var(--knot-color-primary-soft);
  color: var(--knot-color-primary);
}
.vue-flow__controls-button svg { fill: currentColor; }

.vue-flow__minimap {
  background: var(--knot-color-surface) !important;
  border: 1px solid var(--knot-color-border);
  border-radius: var(--knot-radius-sm);
  box-shadow: var(--knot-shadow-sm);
  padding: 4px;
}

.vue-flow__attribution {
  background: transparent !important;
  font-size: 10px;
  color: var(--knot-color-text-soft);
  opacity: 0.5;
}
.vue-flow__attribution a { color: inherit; }

.vue-flow__connection-path {
  stroke-width: 2;
  stroke-dasharray: 6 6;
  transition: stroke 120ms ease;
}

.vue-flow__edge:hover {
  z-index: 999 !important;
}
.vue-flow__edge.selected {
  z-index: 1000 !important;
}
.vue-flow__edge .knot-edge-path {
  pointer-events: stroke;
}

.k-toast-enter-active, .k-toast-leave-active { transition: opacity 220ms ease, transform 220ms ease; }
.k-toast-enter-from, .k-toast-leave-to { opacity: 0; transform: translate(-50%, 8px); }
</style>
