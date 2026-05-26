/**
 * Knot REST API client.
 * Talks to the PHP endpoints under /custom/knot/api/*.
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { i18n } from '../i18n';

export interface WorkflowImportPrecheckWarning {
  severity: string;
  workflow_label?: string;
  code: string;
  /** Vue: `errors.import.<messageKey>` */
  messageKey?: string;
  messageParams?: Record<string, string | number>;
  /** @deprecated Prefer messageKey + i18n */
  message?: string;
  object_type?: string;
  module?: string;
}

export interface KnotCapabilitiesObjects {
  supported_count: number;
  descriptor_file_count: number;
  list?: unknown[];
}

export interface KnotCapabilitiesResponse {
  capabilities: Record<string, unknown> & {
    objects?: KnotCapabilitiesObjects;
  };
  cached: boolean;
}

export interface KnotApiError {
  code: string;
  error_code?: string;
  message: string;
  details?: Record<string, unknown>;
}

export function knotApiErrorMessage(err: KnotApiError): string {
  const code = err.error_code ?? err.code;
  const key = `errors.api.${code}`;
  const translated = i18n.global.t(key);
  if (translated !== key) {
    return String(translated);
  }
  return err.message;
}

export function formatWorkflowImportWarningLine(w: WorkflowImportPrecheckWarning): string {
  if (w.messageKey) {
    const key = `errors.import.${w.messageKey}`;
    const params = w.messageParams ?? {};
    const translated = i18n.global.t(key, params);
    if (translated !== key) {
      return String(translated);
    }
  }
  if (w.message) return w.message;
  return w.code;
}

export interface KnotApiEnvelope<T> {
  success: boolean;
  data: T | null;
  error: KnotApiError | null;
  meta: Record<string, unknown>;
}

declare global {
  interface Window {
    KNOT_API_BASE?: string;
    KNOT_CSRF_TOKEN?: string;
    KNOT_BASE_URL?: string;
    KNOT_MARKETPLACE_UI_ENABLED?: boolean;
  }
}

function apiBase(): string {
  if (typeof window !== 'undefined' && window.KNOT_API_BASE) {
    return window.KNOT_API_BASE.replace(/\/+$/, '');
  }
  return '/custom/knot/api';
}

function csrfToken(): string | null {
  if (typeof window === 'undefined') return null;
  return window.KNOT_CSRF_TOKEN ?? null;
}

async function request<T>(
  path: string,
  init: RequestInit = {},
): Promise<T> {
  const url = apiBase() + path;
  const headers: Record<string, string> = {
    Accept: 'application/json',
    ...((init.headers as Record<string, string>) ?? {}),
  };

  const method = (init.method ?? 'GET').toUpperCase();
  if (method !== 'GET' && method !== 'HEAD') {
    headers['Content-Type'] = headers['Content-Type'] ?? 'application/json';
    const token = csrfToken();
    if (token) headers['X-Csrf-Token'] = token;
  }

  const response = await fetch(url, {
    credentials: 'same-origin',
    ...init,
    headers,
  });

  let envelope: KnotApiEnvelope<T> | null = null;
  let rawBody = '';
  try {
    rawBody = await response.text();
    envelope = JSON.parse(rawBody) as KnotApiEnvelope<T>;
  } catch {
    // The most common cause of "valid HTTP, broken JSON" on a Knot
    // endpoint is Dolibarr returning its own login HTML because the
    // session expired in the background, or a fatal PHP error before
    // our crash handler could install. Surface a friendlier message
    // and the first 200 chars of the body so the operator can tell
    // them apart.
    const looksLikeDolibarrLogin = /<title>[^<]*Identifiant/i.test(rawBody)
      || /name=["']?username["']?/i.test(rawBody);
    const looksLikeFatal = /<b>(Fatal|Parse|Warning) error<\/b>/i.test(rawBody);
    if (looksLikeDolibarrLogin) {
      throw new Error(
        'Session Dolibarr expirée — recharge la page (Cmd/Ctrl+R) puis reconnecte-toi.',
      );
    }
    if (looksLikeFatal) {
      const snippet = rawBody.replace(/<[^>]+>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200);
      throw new Error(`Knot API erreur PHP: ${snippet}`);
    }
    if (response.status === 403 && /403|Forbidden|forbidden|Acc.{0,3}s refus/i.test(rawBody)) {
      throw new Error(
        'Accès refusé (403) — réponse HTML du serveur (droits Knot, session ou filtre WAF). Réessaie après reconnexion ou contacte l’admin.',
      );
    }
    throw new Error(`Knot API ${response.status}: invalid JSON response`);
  }

  if (!envelope.success) {
    const err = envelope.error ?? { code: 'unknown_error', message: 'Unknown error' };
    const msg = knotApiErrorMessage(err as KnotApiError);
    const error = new Error(msg) as Error & {
      code?: string;
      error_code?: string;
      details?: unknown;
    };
    error.code = err.code;
    error.error_code = err.error_code ?? err.code;
    error.details = err.details;
    throw error;
  }

  return envelope.data as T;
}

// Dolibarr schemas API --------------------------------------------------------

export interface DolibarrObjectMeta {
  slug: string;
  class: string;
  module: string;
  label: string;
  supportsLines: boolean;
  element?: string | null;
  source?: string;
  /** Curated MAP entry vs filesystem discovery (DescriptorCache excluding MAP duplicates). */
  fromMap?: boolean;
}

/** Payload from api/dolibarr_schemas.php?list=1 and refresh */
export interface DolibarrDescriptorCacheMeta {
  present: boolean;
  storedDescriptorCount: number;
  discoveryOnlyCount: number;
}

export type DolibarrAction =
  | 'create'
  | 'update'
  | 'fetch'
  | 'delete'
  | 'change_status'
  | 'add_note'
  | 'generate_pdf';

export interface DolibarrSchemaProperty {
  type?: string;
  title?: string;
  description?: string;
  default?: unknown;
  enum?: unknown[];
  enumLabels?: string[];
  format?: string;
  multiline?: boolean;
  maxLength?: number;
  minimum?: number;
  maximum?: number;
  secret?: boolean;
  items?: DolibarrSchemaProperty;
  properties?: Record<string, DolibarrSchemaProperty>;
  required?: string[];
  'x-position'?: number;
  'x-dolibarr-fk'?: { targetClass: string; targetSlug: string; targetFile: string };
  'x-knot-bulk'?: boolean;
  'x-knot-error'?: boolean;
}

export interface DolibarrSchema {
  type: 'object';
  properties?: Record<string, DolibarrSchemaProperty>;
  required?: string[];
  'x-knot-action'?: string;
  'x-knot-object'?: string;
  'x-version-hash'?: string;
  'x-dolibarr-permission'?: string;
  'x-knot-error'?: boolean;
}

export interface DolibarrVerbParameter {
  name: string;
  type: string | null;
  optional: boolean;
  default: unknown;
}

export interface DolibarrVerb {
  name: string;
  parameters: DolibarrVerbParameter[];
  maturity: 'verified' | 'experimental';
  pattern: string;
  simulateError: string | null;
}

export interface DolibarrPickResult {
  id: number;
  ref: string;
  label: string;
  extra?: Record<string, unknown>;
}

/** Matches `api/dolibarr_schemas.php` `field_view` (SchemaBuilder). */
export type DolibarrFieldView = 'standard' | 'full';

const DOLIBARR_CACHE_KEY = 'knot.dolibarr.schemas.v1';
const DOLIBARR_HASH_DEBOUNCE_MS = 30_000;

interface DolibarrStoredCache {
  hash: string;
  schemas: Record<string, DolibarrSchema>;
}

const dolibarrSchemaCache: Map<string, DolibarrSchema> = new Map();
let dolibarrCachedHash: string | null = null;
let dolibarrLastHashCheckTs = 0;
let dolibarrCacheLoaded = false;

function loadDolibarrCache(): void {
  if (dolibarrCacheLoaded) return;
  dolibarrCacheLoaded = true;
  if (typeof localStorage === 'undefined') return;
  try {
    const raw = localStorage.getItem(DOLIBARR_CACHE_KEY);
    if (!raw) return;
    const data = JSON.parse(raw) as DolibarrStoredCache | null;
    if (!data || typeof data !== 'object') return;
    if (typeof data.hash === 'string' && data.hash !== '') {
      dolibarrCachedHash = data.hash;
    }
    if (data.schemas && typeof data.schemas === 'object') {
      for (const [k, v] of Object.entries(data.schemas)) {
        dolibarrSchemaCache.set(k, v);
      }
    }
  } catch {
    // Corrupted cache — start fresh.
  }
}

function persistDolibarrCache(): void {
  if (typeof localStorage === 'undefined') return;
  try {
    const schemas: Record<string, DolibarrSchema> = {};
    for (const [k, v] of dolibarrSchemaCache.entries()) {
      schemas[k] = v;
    }
    const payload: DolibarrStoredCache = { hash: dolibarrCachedHash ?? '', schemas };
    localStorage.setItem(DOLIBARR_CACHE_KEY, JSON.stringify(payload));
  } catch {
    // Quota exhausted or storage disabled — non-fatal.
  }
}

async function fetchDolibarrHash(): Promise<string> {
  const data = await request<{ hash: string }>(`/dolibarr_schemas.php?hash=1`);
  return data.hash;
}

async function ensureFreshDolibarrCache(): Promise<void> {
  loadDolibarrCache();
  const now = Date.now();
  if (now - dolibarrLastHashCheckTs < DOLIBARR_HASH_DEBOUNCE_MS && dolibarrCachedHash !== null) {
    return;
  }
  dolibarrLastHashCheckTs = now;
  try {
    const remoteHash = await fetchDolibarrHash();
    if (dolibarrCachedHash !== null && dolibarrCachedHash !== remoteHash) {
      dolibarrSchemaCache.clear();
    }
    dolibarrCachedHash = remoteHash;
    persistDolibarrCache();
  } catch {
    // Network blip — keep working with whatever cache we have.
  }
}

// Workflow API ----------------------------------------------------------------

export interface WorkflowSummary {
  id: number;
  ref: string;
  label: string;
  description: string;
  status: string;
  schemaVersion: string;
  createdBy: number | null;
  modifiedBy: number | null;
  createdAt: string;
  updatedAt: string;
  tags?: string[];
  favorite?: boolean;
  triggerType?: string;
  successCount?: number;
  errorCount?: number;
  waitingCount?: number;
  runningCount?: number;
  queuedCount?: number;
  runsTotal?: number;
  /** Worst node risk level from server-side WorkflowRiskAnalyzer (list endpoint). */
  riskWorstLevel?: 'safe' | 'caution' | 'critical';
  /** Workflow folder id when assigned via folders API. */
  folderId?: number | null;
}

export interface WorkflowLintResponse {
  issues: Array<{
    severity: string;
    code: string;
    messageKey: string;
    messageParams?: Record<string, string | number>;
    nodeId?: string;
  }>;
  valid: boolean;
  errorCount: number;
  warningCount: number;
}

export interface BundledTemplateSummary {
  slug: string;
  title: string;
  description: string;
  category: string;
  difficulty: string;
  tier: string;
  modulesRequired: string[];
  demoInvalid: boolean;
}

export interface BundledTemplatePayload {
  slug: string;
  meta: BundledTemplateSummary & Record<string, unknown>;
  knotExport: string;
  exportedAt: string;
  workflow: {
    label: string;
    description: string;
    definition: WorkflowDefinition;
  };
}

export interface WorkflowDefinition {
  schemaVersion: string;
  workflow?: Record<string, unknown>;
  nodes: Array<Record<string, unknown>>;
  edges: Array<Record<string, unknown>>;
  metadata?: Record<string, unknown>;
}

export interface WorkflowRiskPayload {
  worstLevel: string;
  hasCritical: boolean;
  criticalNodes: Array<Record<string, unknown>>;
  sideEffects: string[];
}

export interface Workflow extends WorkflowSummary {
  definition: WorkflowDefinition;
  entity: number;
  activationWarningDismissed?: boolean;
  risk?: WorkflowRiskPayload;
}

export type WorkflowSavePayload = Partial<Workflow> & {
  definition?: WorkflowDefinition;
  critical_activation_acknowledged?: boolean;
  activation_warning_dismissed?: boolean;
};

export interface WorkflowVersion {
  id: number;
  workflowId: number;
  label: string | null;
  named: boolean;
  parentVersionId: number | null;
  createdBy: number | null;
  createdAt: string;
}

export interface WorkflowDiffPatchEntry {
  op: 'add' | 'remove' | 'replace';
  before?: unknown;
  after?: unknown;
}

export interface WorkflowDiff {
  nodes: {
    added: Array<Record<string, unknown>>;
    removed: Array<Record<string, unknown>>;
    changed: Array<{
      id: string;
      type: string;
      before: Record<string, unknown>;
      after: Record<string, unknown>;
      patch: Record<string, WorkflowDiffPatchEntry>;
    }>;
  };
  edges: {
    added: Array<Record<string, unknown>>;
    removed: Array<Record<string, unknown>>;
  };
  meta: {
    changed: Record<string, WorkflowDiffPatchEntry>;
    schemaVersion: { left: string; right: string };
  };
  summary: {
    nodesAdded: number;
    nodesRemoved: number;
    nodesChanged: number;
    edgesAdded: number;
    edgesRemoved: number;
  };
}

export const knotApi = {
  listWorkflows(params: { status?: string; limit?: number; offset?: number } = {}) {
    const query = new URLSearchParams();
    if (params.status) query.set('status', params.status);
    if (params.limit) query.set('limit', String(params.limit));
    if (params.offset) query.set('offset', String(params.offset));
    const qs = query.toString();
    return request<{ workflows: WorkflowSummary[]; counts: Record<string, number> }>(
      `/workflows.php${qs ? `?${qs}` : ''}`,
    );
  },

  bulkWorkflows(payload: { ids: number[]; operation: 'active' | 'disabled' | 'archived' | 'delete' | 'favorite' | 'tag'; tags?: string[] }) {
    return request<{ updated: number }>(`/workflows.php?action=bulk`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },

  getWorkflow(id: number) {
    return request<{ workflow: Workflow }>(`/workflows.php?id=${encodeURIComponent(id)}`);
  },

  listBundledTemplates() {
    return request<{ templates: BundledTemplateSummary[]; meta: Record<string, unknown> }>(
      `/bundled_templates.php`,
    );
  },

  getBundledTemplate(slug: string) {
    return request<BundledTemplatePayload>(
      `/bundled_templates.php?slug=${encodeURIComponent(slug)}`,
    );
  },

  lintWorkflowDefinition(definition: WorkflowDefinition): Promise<WorkflowLintResponse> {
    return request<WorkflowLintResponse>(`/workflows.php`, {
      method: 'POST',
      body: JSON.stringify({ action: 'lint', definition }),
    });
  },

  saveWorkflow(payload: WorkflowSavePayload) {
    return request<{ workflow: Workflow }>(`/workflows.php`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },

  listWorkflowVersions(workflowId: number) {
    return request<{ versions: WorkflowVersion[] }>(
      `/workflows.php?id=${encodeURIComponent(workflowId)}&action=versions`,
    );
  },

  rollbackWorkflow(workflowId: number, versionId: number) {
    return request<{ workflow: Workflow }>(
      `/workflows.php?id=${encodeURIComponent(workflowId)}&action=rollback`,
      {
        method: 'POST',
        body: JSON.stringify({ versionId }),
      },
    );
  },

  nameWorkflowVersion(workflowId: number, versionId: number, label: string) {
    return request<{ versions: WorkflowVersion[] }>(
      `/workflows.php?id=${encodeURIComponent(workflowId)}&action=name_version`,
      {
        method: 'POST',
        body: JSON.stringify({ versionId, label }),
      },
    );
  },

  diffWorkflowVersions(workflowId: number, leftId: number | null, rightId: number | null) {
    const params = new URLSearchParams();
    params.set('id', String(workflowId));
    params.set('action', 'diff');
    if (leftId) params.set('leftId', String(leftId));
    if (rightId) params.set('rightId', String(rightId));
    return request<{
      diff: WorkflowDiff;
      left: { id: number | null; definition: WorkflowDefinition };
      right: { id: number | null; definition: WorkflowDefinition };
    }>(`/workflows.php?${params.toString()}`);
  },

  executeWorkflow(workflowId: number) {
    const body = new URLSearchParams();
    body.set('workflow_id', String(workflowId));
    const token = csrfToken();
    if (token) body.set('token', token);
    return request<{ executionId: number }>(`/execute.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
      body: body.toString(),
    });
  },

  replayWorkflowFromNode(workflowId: number, fromNode: string, inputData: Record<string, unknown> = {}) {
    return request<{ executionId: number }>(`/execute.php`, {
      method: 'POST',
      body: JSON.stringify({ workflowId, fromNode, inputData }),
    });
  },

  simulateWorkflow(payload: {
    workflowId: number;
    dryRun?: boolean;
    fromNode?: string;
    inputData?: Record<string, unknown>;
  }) {
    return request<{
      mode: 'sync';
      status: 'success' | 'error';
      durationMs: number;
      logs: Array<Record<string, unknown>>;
      finalContext: Record<string, unknown>;
      dryRun: boolean;
    }>(`/execute.php`, {
      method: 'POST',
      body: JSON.stringify({
        workflowId: payload.workflowId,
        mode: 'sync',
        dryRun: payload.dryRun ?? true,
        fromNode: payload.fromNode,
        inputData: payload.inputData,
      }),
    });
  },

  listExecutions(
    params: { workflowId?: number; limit?: number; offset?: number; status?: string } = {},
  ) {
    const query = new URLSearchParams();
    if (params.workflowId) query.set('workflow_id', String(params.workflowId));
    if (params.limit) query.set('limit', String(params.limit));
    if (params.offset !== undefined) query.set('offset', String(params.offset));
    if (params.status && params.status.trim() !== '') query.set('status', params.status.trim());
    const qs = query.toString();
    return request<{ executions: ExecutionSummary[]; counts: Record<string, number> }>(
      `/executions.php${qs ? `?${qs}` : ''}`,
    );
  },

  getExecution(id: number) {
    return request<{
      execution: ExecutionSummary;
      logs: ExecutionLog[];
      totalLogs?: number;
      truncated?: boolean;
    }>(`/executions.php?id=${encodeURIComponent(id)}`);
  },

  cancelExecution(id: number) {
    return request<{ executionId: number; status: string }>(
      `/executions.php?action=cancel`,
      { method: 'POST', body: JSON.stringify({ executionId: id }) },
    );
  },

  retryExecution(id: number) {
    return request<{ originalExecutionId: number; executionId: number; status: string }>(
      `/executions.php?action=retry`,
      { method: 'POST', body: JSON.stringify({ executionId: id }) },
    );
  },

  runExecutionNow(id: number) {
    return request<{ execution: ExecutionSummary }>(
      `/executions.php?action=run_now`,
      { method: 'POST', body: JSON.stringify({ executionId: id }) },
    );
  },

  queueDashboard() {
    return request<QueueDashboardData>('/executions.php?action=queue_dashboard');
  },

  purgeFailedExecutions(olderThanDays: number) {
    return request<{ deleted: number; olderThanDays: number }>(
      '/executions.php?action=purge_failures',
      { method: 'POST', body: JSON.stringify({ olderThanDays }) },
    );
  },

  health() {
    return request<HealthSnapshot>(`/health.php`);
  },

  observability(opts?: { days?: number; limitTypes?: number }) {
    const q = new URLSearchParams();
    if (opts?.days != null) {
      q.set('days', String(opts.days));
    }
    if (opts?.limitTypes != null) {
      q.set('limit_types', String(opts.limitTypes));
    }
    const qs = q.toString();
    return request<ObservabilitySnapshot>(`/observability.php${qs ? `?${qs}` : ''}`);
  },

  capabilities(opts?: { refresh?: boolean }) {
    const qs = opts?.refresh ? '?refresh=1' : '';
    return request<KnotCapabilitiesResponse>(`/capabilities.php${qs}`);
  },

  connectors() {
    return request<{
      connectors: ConnectorDescriptor[];
      palette: Array<{ category: string; title: string; ids: string[] }>;
      extensions: ExtensionSummary[];
    }>(`/connectors.php`);
  },

  licenseStatus() {
    return request<{
      extensions: ExtensionLicenseStatus[];
      backendUrl: string;
    }>(`/license_status.php`);
  },

  licenseActivate(extensionId: string, activationCode: string) {
    return request<LicenseActivationResponse>(`/license_activate.php`, {
      method: 'POST',
      body: JSON.stringify({
        extension_id: extensionId,
        activation_code: activationCode,
      }),
    });
  },

  licenseDeactivate(extensionId: string, activationCode: string) {
    return request<LicenseDeactivationResponse>(`/license_deactivate.php`, {
      method: 'POST',
      body: JSON.stringify({
        extension_id: extensionId,
        activation_code: activationCode,
      }),
    });
  },

  updates(opts?: { force?: boolean }) {
    const qs = opts?.force ? '?force=1' : '';
    return request<UpdatesCheckResponse>(`/updates.php${qs}`);
  },

  updatesApply(payload: { slug: string; download_url?: string; zip_sha256?: string }) {
    return request<UpdatesApplyResult>(`/updates_apply.php`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },

  migrationScan(workflowId?: number) {
    const qs = workflowId ? `?workflow_id=${encodeURIComponent(workflowId)}` : '';
    return request<MigrationScanResponse>(`/migration_scan.php${qs}`);
  },

  marketplace(opts?: { refresh?: boolean }) {
    const qs = opts?.refresh ? `?action=refresh` : '';
    return request<MarketplaceResponse>(`/marketplace.php${qs}`);
  },

  marketplaceTrack(
    event: string,
    context: Record<string, string | number | boolean> = {},
  ) {
    return request<{ ok: boolean }>(`/marketplace_track.php`, {
      method: 'POST',
      body: JSON.stringify({ event, context }),
    });
  },

  listCredentials(connectorType?: string) {
    const qs = connectorType ? `?connector_type=${encodeURIComponent(connectorType)}` : '';
    return request<{ credentials: CredentialSummary[]; counts: Record<string, number> }>(
      `/credentials.php${qs}`,
    );
  },

  createCredential(payload: CredentialPayload) {
    return request<{ credential: CredentialSummary }>(`/credentials.php`, {
      method: 'POST',
      body: JSON.stringify(payload),
    });
  },

  updateCredential(id: number, payload: CredentialPayload) {
    return request<{ credential: CredentialSummary }>(`/credentials.php?id=${encodeURIComponent(id)}`, {
      method: 'PUT',
      body: JSON.stringify(payload),
    });
  },

  testCredential(payload: CredentialPayload) {
    return request<{ valid: boolean; schema: CredentialSchema | null }>(`/credentials.php`, {
      method: 'POST',
      body: JSON.stringify({ ...payload, testOnly: true }),
    });
  },

  deleteCredential(id: number) {
    return request<{ deleted: number }>(`/credentials.php?id=${encodeURIComponent(id)}`, {
      method: 'DELETE',
    });
  },

  listApprovals() {
    return request<{ approvals: ApprovalSummary[] }>(`/approvals.php`);
  },

  decideApproval(id: number, status: 'approved' | 'rejected', comment = '') {
    return request<{ approvalId: number; status: string }>(`/approvals.php`, {
      method: 'POST',
      body: JSON.stringify({ id, status, comment }),
    });
  },

  assistantPrompt(userRequest?: string) {
    const trimmed = userRequest?.trim() ?? '';
    return request<{ prompt: string; connectors: ConnectorDescriptor[]; userRequest: string }>(`/assistant.php`, {
      method: 'POST',
      body: JSON.stringify({ action: 'prompt', userRequest: trimmed }),
    });
  },

  conflictReport() {
    return request<{ report: ConflictReport }>(`/conflicts.php`);
  },

  cascadePrediction(workflowId: number) {
    return request<{ cascade: CascadePrediction }>(
      `/conflicts.php?action=cascade&workflow_id=${encodeURIComponent(workflowId)}`,
    );
  },

  listTemplates() {
    return request<{ templates: TemplateSummary[] }>(`/templates.php`);
  },

  instantiateTemplate(templateIdOrSlug: number | string, label?: string) {
    const body = typeof templateIdOrSlug === 'number'
      ? { templateId: templateIdOrSlug }
      : { slug: templateIdOrSlug };
    return request<{ workflow: Workflow; fromTemplate: string; fromTemplateSlug: string | null }>(`/templates.php`, {
      method: 'POST',
      body: JSON.stringify({ ...body, ...(label ? { label } : {}) }),
    });
  },

  listOAuthProviders() {
    return request<{ providers: OAuthProvider[] }>(`/oauth.php?action=providers`);
  },

  startOAuth(payload: { provider: string; clientId: string; redirectUri: string; scopes?: string[] }) {
    const params = new URLSearchParams({
      action: 'start',
      provider: payload.provider,
      client_id: payload.clientId,
      redirect_uri: payload.redirectUri,
    });
    if (payload.scopes && payload.scopes.length > 0) {
      params.set('scopes', payload.scopes.join(','));
    }
    return request<{ authorizationUrl: string; state: string; provider: string }>(
      `/oauth.php?${params.toString()}`,
    );
  },

  getWorkflowWebhook(workflowId: number) {
    return request<{ webhook: WebhookConfig | null; url: string | null }>(
      `/webhooks.php?workflow_id=${encodeURIComponent(workflowId)}`,
    );
  },

  provisionWorkflowWebhook(workflowId: number, payload: Partial<WebhookConfig> = {}) {
    return request<{ webhook: WebhookConfig; url: string }>(
      `/webhooks.php?workflow_id=${encodeURIComponent(workflowId)}`,
      { method: 'POST', body: JSON.stringify(payload) },
    );
  },

  rotateWorkflowWebhook(workflowId: number) {
    return request<{ webhook: WebhookConfig; url: string }>(
      `/webhooks.php?workflow_id=${encodeURIComponent(workflowId)}&action=rotate`,
      { method: 'POST', body: JSON.stringify({}) },
    );
  },

  listVariables(scope?: string) {
    const qs = scope ? `?scope=${encodeURIComponent(scope)}` : '';
    return request<{ variables: KnotVariable[] }>(`/variables.php${qs}`);
  },
  createVariable(payload: Partial<KnotVariable>) {
    return request<{ id: number }>(`/variables.php`, {
      method: 'POST', body: JSON.stringify(payload),
    });
  },
  updateVariable(id: number, payload: Partial<KnotVariable>) {
    return request<{ updated: boolean }>(`/variables.php?id=${id}`, {
      method: 'PUT', body: JSON.stringify({ id, ...payload }),
    });
  },
  deleteVariable(id: number) {
    return request<{ deleted: boolean }>(`/variables.php?id=${id}`, { method: 'DELETE' });
  },

  listAudit(filters: { actionType?: string; entityType?: string; q?: string; userId?: number; since?: string; limit?: number; offset?: number } = {}) {
    const params = new URLSearchParams();
    if (filters.actionType) params.set('action_type', filters.actionType);
    if (filters.entityType) params.set('entity_type', filters.entityType);
    if (filters.q !== undefined && filters.q.trim() !== '') params.set('q', filters.q.trim());
    if (filters.userId !== undefined) params.set('user_id', String(filters.userId));
    if (filters.since) params.set('since', filters.since);
    if (filters.limit) params.set('limit', String(filters.limit));
    if (filters.offset) params.set('offset', String(filters.offset));
    return request<{ audit: AuditEntry[]; limit: number; offset: number }>(`/audit.php?${params.toString()}`);
  },

          listFolders() {
            return request<{ folders: Array<{ id: number; label: string; color: string | null; parentId: number | null }> }>(`/folders.php`);
          },
          createFolder(payload: { label: string; color?: string | null; parentId?: number | null }) {
            return request<{ id: number }>(`/folders.php`, { method: 'POST', body: JSON.stringify(payload) });
          },
          updateFolder(id: number, payload: { label?: string; color?: string | null; parentId?: number | null }) {
            return request<{ updated: boolean }>(`/folders.php?id=${id}`, { method: 'PUT', body: JSON.stringify({ id, ...payload }) });
          },
          deleteFolder(id: number) {
            return request<{ deleted: boolean }>(`/folders.php?id=${id}`, { method: 'DELETE' });
          },
          assignFolder(workflowId: number, folderId: number | null) {
            return request<{ updated: boolean }>(`/folders.php?action=assign`, {
              method: 'POST',
              body: JSON.stringify({ workflowId, folderId }),
            });
          },
          importWorkflowsPrecheck(workflows: Array<{ workflow?: unknown } | unknown>) {
            return request<{ warnings: WorkflowImportPrecheckWarning[]; knot_version: string | null }>(
              `/workflows.php?action=import_precheck`,
              { method: 'POST', body: JSON.stringify({ workflows }) },
            );
          },
          importWorkflowsBulk(workflows: Array<{ workflow?: unknown } | unknown>) {
            return request<{ imported: number; skipped: number; ids: number[] }>(`/workflows.php?action=import_bulk`, {
              method: 'POST',
              body: JSON.stringify({ workflows }),
            });
          },

          listSchedules(workflowId: number) {
    return request<{ schedules: KnotSchedule[] }>(`/schedules.php?workflow_id=${workflowId}`);
  },
  saveSchedule(workflowId: number, payload: Partial<KnotSchedule>) {
    return request<{ id: number }>(`/schedules.php?workflow_id=${workflowId}`, {
      method: 'POST', body: JSON.stringify(payload),
    });
  },
  deleteSchedule(id: number) {
    return request<{ deleted: boolean }>(`/schedules.php?id=${id}`, { method: 'DELETE' });
  },

  async getDolibarrObjects(): Promise<{
    objects: DolibarrObjectMeta[];
    hash: string;
    descriptorCache?: DolibarrDescriptorCacheMeta;
  }> {
    loadDolibarrCache();
    const data = await request<{
      objects: DolibarrObjectMeta[];
      hash: string;
      descriptorCache?: DolibarrDescriptorCacheMeta;
    }>(`/dolibarr_schemas.php?list=1`);
    if (dolibarrCachedHash !== null && dolibarrCachedHash !== data.hash) {
      dolibarrSchemaCache.clear();
    }
    dolibarrCachedHash = data.hash;
    dolibarrLastHashCheckTs = Date.now();
    persistDolibarrCache();
    return data;
  },

  async getDolibarrSchema(
    slug: string,
    action: DolibarrAction,
    options?: { fieldView?: DolibarrFieldView },
  ): Promise<DolibarrSchema> {
    if (!slug) {
      throw new Error('Dolibarr schema requires a non-empty slug.');
    }
    await ensureFreshDolibarrCache();
    const fieldView: DolibarrFieldView = options?.fieldView === 'full' ? 'full' : 'standard';
    const key = `${slug}:${action}:${fieldView}`;
    const cached = dolibarrSchemaCache.get(key);
    if (cached) return cached;

    const data = await request<{ schema: DolibarrSchema }>(
      `/dolibarr_schemas.php?slug=${encodeURIComponent(slug)}&action=${encodeURIComponent(action)}&field_view=${encodeURIComponent(fieldView)}`,
    );
    dolibarrSchemaCache.set(key, data.schema);
    persistDolibarrCache();
    return data.schema;
  },

  async getDolibarrSchemaHash(): Promise<string> {
    const hash = await fetchDolibarrHash();
    if (dolibarrCachedHash !== null && dolibarrCachedHash !== hash) {
      dolibarrSchemaCache.clear();
    }
    dolibarrCachedHash = hash;
    dolibarrLastHashCheckTs = Date.now();
    persistDolibarrCache();
    return hash;
  },

  async getDolibarrVerbs(slug: string, simulate = true): Promise<DolibarrVerb[]> {
    if (!slug) {
      throw new Error('Dolibarr verbs require a non-empty slug.');
    }
    const data = await request<{ slug: string; verbs: DolibarrVerb[] }>(
      `/dolibarr_schemas.php?verbs=1&slug=${encodeURIComponent(slug)}&simulate=${simulate ? '1' : '0'}`,
    );
    return data.verbs ?? [];
  },

  async getStateMachineStates(slug: string): Promise<Record<string, number>> {
    if (!slug) {
      throw new Error('State machine requires a non-empty slug.');
    }
    const data = await request<{ slug: string; states: Record<string, number> }>(
      `/state_machine.php?action=states&slug=${encodeURIComponent(slug)}`,
    );
    return data.states ?? {};
  },

  async getStateMachineProbableTransitions(
    slug: string,
    id: number,
  ): Promise<{
    slug: string;
    id: number;
    currentLogicalState: string | null;
    probableTransitions: Array<{ method: string; maturity: string; probability: string; pattern: string }>;
  }> {
    if (!slug || id <= 0) {
      throw new Error('Probable transitions require slug and positive id.');
    }
    return request(
      `/state_machine.php?action=probable_transitions&slug=${encodeURIComponent(slug)}&id=${String(id)}`,
    );
  },

  async fetchCompatibilitySnapshotLive(): Promise<{ snapshot: Record<string, unknown> }> {
    return request(`/compatibility.php?action=snapshot_live`);
  },

  async fetchCompatibilitySample(): Promise<{ snapshot: Record<string, unknown> }> {
    return request(`/compatibility.php?action=sample`);
  },

  async fetchCompatibilityBundledSnapshots(): Promise<{
    snapshots: Array<{
      filename: string;
      dolibarr_version: string;
      schema_version: string;
      generated_at: string | null;
    }>;
  }> {
    return request(`/compatibility.php?action=bundled_snapshots`);
  },

  async fetchCompatibilityBundledSnapshot(filename: string): Promise<{ snapshot: Record<string, unknown> }> {
    const safe = encodeURIComponent(filename);
    return request(`/compatibility.php?action=bundled_snapshot&file=${safe}`);
  },

  async computeCompatibilityDiff(payload: {
    baseline: Record<string, unknown>;
    target: Record<string, unknown>;
    workflows?: unknown[];
  }): Promise<{
    diff: unknown[];
    breaking: unknown[];
    workflow_hints: unknown[];
    report_markdown: string;
  }> {
    return request(`/compatibility.php`, {
      method: 'POST',
      body: JSON.stringify({ action: 'diff', ...payload }),
    });
  },

  async refreshDolibarrIntrospection(): Promise<{
    hash: string;
    objects: DolibarrObjectMeta[];
    descriptors?: number;
    descriptorCache?: DolibarrDescriptorCacheMeta;
  }> {
    const data = await request<{
      hash: string;
      objects: DolibarrObjectMeta[];
      descriptors?: number;
      descriptorCache?: DolibarrDescriptorCacheMeta;
    }>(
      `/dolibarr_schemas.php?refresh=1`,
      { method: 'POST', body: '{}' },
    );
    dolibarrSchemaCache.clear();
    dolibarrCachedHash = data.hash;
    dolibarrLastHashCheckTs = Date.now();
    persistDolibarrCache();
    return data;
  },

  async pickDolibarrFk(slug: string, query: string, limit = 20): Promise<DolibarrPickResult[]> {
    const url = `/dolibarr_picker.php?slug=${encodeURIComponent(slug)}&q=${encodeURIComponent(query)}&limit=${limit}`;
    const data = await request<{ results: DolibarrPickResult[] }>(url);
    return data.results ?? [];
  },
};

export interface KnotVariable {
  id: number;
  ref: string;
  label: string;
  value: string | null;
  isSecret: boolean;
  scope: string;
  workflowId: number | null;
}

export interface AuditEntry {
  id: number;
  actionType: string;
  entityType: string;
  entityId: number | null;
  userId: number | null;
  ip: string;
  payload: Record<string, unknown>;
  createdAt: string;
}

export interface KnotSchedule {
  id: number;
  workflowId: number;
  cronExpression: string;
  timezone: string;
  isActive: boolean;
  lastRunAt: string | null;
  nextRunAt: string | null;
}

export interface OAuthProvider {
  id: string;
  label: string;
  authorizationEndpoint: string | null;
  defaultScopes: string[];
  docsUrl: string | null;
  icon: string | null;
}

export interface WebhookConfig {
  id: number;
  workflowId: number;
  token: string;
  method: string;
  isActive: boolean;
  hitCount: number;
  lastHitAt: string | null;
  rateLimitPerMinute: number;
  ipAllowlist: string;
  secretHmac: string;
  hasSecret: boolean;
}

export interface TemplateSummary {
  id: number;
  ref: string;
  label: string;
  description: string;
  category: string;
  icon: string;
  isSystem: boolean;
  definition: WorkflowDefinition;
}

export interface ConflictReport {
  nativeWorkflows: Array<Record<string, unknown>>;
  nativeConflicts: Array<{
    workflowId: number;
    workflowLabel: string;
    nativeId?: string | number;
    description?: string;
    severity?: 'info' | 'warning' | 'error';
    [key: string]: unknown;
  }>;
  triggerConflicts: Array<{
    trigger: string;
    severity: 'info' | 'warning' | 'error';
    workflows: Array<{ workflowId: number; label: string }>;
    suggestion: string;
  }>;
  generatedAt: string;
}

export interface CascadePrediction {
  steps: Array<{ nodeId: string; nodeType: string; impact: string }>;
  warnings: string[];
}

export interface ConnectorDescriptor {
  metadata: {
    id: string;
    label: string;
    labelKey?: string;
    category: string;
    description?: string;
    descriptionKey?: string;
    icon?: string;
    color?: string;
    [key: string]: unknown;
  };
  configSchema: Record<string, unknown> | null;
  credentialType: string | null;
  credentialSchema: CredentialSchema | null;
  inputs: Array<Record<string, unknown>>;
  outputs: Array<Record<string, unknown>>;
  source?: string;
  extensionInfo?: ExtensionInfo | null;
  available?: boolean;
}

export interface ExtensionInfo {
  id: string | null;
  label: string | null;
  version: string | null;
  category: string;
  license_status: string | null;
  license_expires_at: string | null;
  license_error?: string | null;
  status?: string | null;
  error?: string | null;
}

export interface ExtensionSummary {
  id: string | null;
  label: string | null;
  version: string | null;
  author: string | null;
  category: string;
  status: string | null;
  error: string | null;
  license_status: string | null;
  license_expires_at: string | null;
}

/**
 * Per-extension status returned by /api/license_status.php — richer
 * shape than ExtensionSummary, includes the cached signed verdict so
 * the UI can render the bound instance fingerprint without a roundtrip.
 */
export interface ExtensionLicenseStatus {
  id: string;
  label: string | null;
  version: string | null;
  author: string | null;
  category: string;
  status: string | null;
  error: string | null;
  licenseStatus: 'valid' | 'invalid' | 'expired' | 'not_required' | string | null;
  licenseExpiresAt: string | null;
  connectorIds: string[];
  cachedVerdict: {
    instanceId: string | null;
    plan: string | null;
    expiresAt: string | null;
    lastSuccessfulRefresh: string | null;
    lastAttempt: string | null;
    lastError: string | null;
  } | null;
}

/**
 * Reply shape for /api/license_activate.php. When `activated` is false
 * the `backend` payload contains the raw error envelope from
 * license.knot.tools (e.g. `{error: 'unknown_activation_code', ...}`).
 */
export interface LicenseActivationResponse {
  activated: boolean;
  fingerprint: string;
  extensionId?: string;
  cacheWriteError?: string;
  backendStatus?: number;
  backend?: Record<string, unknown>;
  verdict?: {
    payload: Record<string, unknown>;
    signature: { kid: string; algorithm: string; value_hex: string; canonical_payload: string };
  };
}

/** Reply shape for /api/license_deactivate.php */
export interface LicenseDeactivationResponse {
  deactivated: boolean;
  fingerprint?: string;
  extensionId?: string;
  license_id?: string | number | null;
  remaining_seats?: number | null;
  cacheWriteError?: string;
  backendStatus?: number;
  backend?: Record<string, unknown>;
}

/** Reply shape for /api/updates.php (Phase 7d notify-only badge). */
export interface UpdatesCheckResponse {
  checkedAt: number;
  hasAnyUpdate: boolean;
  entries: Array<{
    slug: string;
    installedVersion: string;
    latestVersion: string | null;
    channel: string | null;
    publishedAt: string | null;
    hasUpdate: boolean;
    source: 'live' | 'cache' | 'cache_stale' | 'unavailable' | string;
    error: string | null;
  }>;
}

/** POST /api/updates_apply.php successful payload. */
export interface UpdatesApplyResult {
  slug: string;
  path: string;
  migrations?: Array<Record<string, unknown>>;
  manual_fallback_instructions?: string[];
  release?: Record<string, unknown>;
}

/**
 * Reply shape for /api/migration_scan.php. The `migratedConnectorIds`
 * field is the canonical list shared by Core's `ConnectorMigration` and
 * Pro Pack's manifest; UI components consume it to render the deprecated
 * badge in the editor palette.
 */
export interface MigrationScanResponse {
  migratedConnectorIds: string[];
  impacted: Array<{
    workflowId: number;
    ref: string;
    label: string;
    status: string;
    updatedAt: string;
    impactedNodes: Array<{ nodeId: string; connectorId: string }>;
    distinctConnectorIds: string[];
  }>;
  summary: {
    scannedWorkflows: number | null;
    impactedWorkflows: number;
    impactedNodesTotal: number;
    connectorIdsImpacted: string[];
  };
  proPackProductSlug: string;
}

/**
 * Editorial host payload (optional `/api/marketplace.php` P1a).
 */
export interface KnotMarketplaceBlockSpecDTO {
  type: string;
  id?: string;
  props?: Record<string, unknown>;
}

export interface MarketplaceProductTabSpec {
  id: string;
  kind: 'richtext' | 'features' | 'templates' | 'changelog' | 'list' | 'screenshots' | string;
  label: string;
  visible?: boolean;
  body?: string;
  items?: Array<{ title?: string; description?: string; version?: string; date?: string; notes?: string }>;
  /** Populated for `kind: screenshots` tabs (editorial v2). */
  images?: Array<{ src: string; alt?: string }>;
}

export interface MarketplaceProductPageSpec {
  layout?: KnotMarketplaceBlockSpecDTO[];
  hero?: {
    tagline?: string;
    version?: string;
    author?: string;
    tier?: string;
  };
  pricing?: {
    highlight?: string;
    plans?: Array<{
      name: string;
      price?: string;
      href?: string;
      highlighted?: boolean;
      features?: string[];
    }>;
  };
  tabs?: MarketplaceProductTabSpec[];
  screenshots?: Array<{ src: string; alt?: string }>;
  related?: string[];
}

export interface MarketplaceTemplatePageSpec {
  layout?: KnotMarketplaceBlockSpecDTO[];
  hero?: { tagline?: string };
  tabs?: MarketplaceProductTabSpec[];
  related?: string[];
}

export interface KnotMarketplaceEditorialDTO {
  version?: string;
  meta?: {
    updatedAt?: string;
    schemaVersion?: number;
    killSwitch?: boolean;
  };
  redirects?: Record<string, string> | Array<{ from: string; to: string; view?: string }>;
  sidebar?: { badge?: { label?: string; variant?: string; ariaLabel?: string } };
  home?:
    | KnotMarketplaceBlockSpecDTO[]
    | {
        layout?: KnotMarketplaceBlockSpecDTO[];
        spotlight?: {
          title?: string;
          items?: Array<{
            kind?: 'pack' | 'template' | string;
            slug?: string;
            tagline?: string;
            accent?: string;
            ctas?: Array<{ label?: string; href?: string; kind?: string }>;
          }>;
        };
        collections?: Array<{
          slug?: string;
          label?: string;
          query?: { sort?: string; limit?: number; kind?: string };
          slugs?: string[];
        }>;
      };
  product?: KnotMarketplaceBlockSpecDTO[];
  productPages?: Record<string, MarketplaceProductPageSpec>;
  template?: KnotMarketplaceBlockSpecDTO[];
  templatePages?: Record<string, MarketplaceTemplatePageSpec>;
  news?: KnotMarketplaceBlockSpecDTO[];
  newsPages?: Record<string, { layout?: KnotMarketplaceBlockSpecDTO[] }>;
}

/**
 * Reply shape for /api/marketplace.php — the unified marketplace endpoint
 * powering MarketplaceView (Packs / Templates / Migration tabs).
 */
export type MarketplaceTier = 'free' | 'beta' | 'pro' | 'enterprise' | string;

export interface MarketplacePack {
  slug: string;
  label: string;
  description: string | null;
  kind: 'extension' | 'template' | string;
  tier: MarketplaceTier;
  category: string;
  icon: string | null;
  templateCategory: string | null;
  priceMonthlyCents: number | null;
  priceYearlyCents: number | null;
  currency: string;
  trialDays: number;
  refundWindowDays: number;
  buyUrl: string | null;
  installed: boolean;
  /**
   * `true` only when the pack is installed AND its license verdict is
   * `valid`. Drives marketplace gating: a pack with `installed=true`
   * but `licenseActive=false` does not unlock its tier (e.g. an
   * expired Pro Pack must not let Pro templates be instantiated).
   */
  licenseActive: boolean;
  status: string;
  licenseStatus: 'valid' | 'invalid' | 'expired' | 'not_required' | string | null;
  licenseExpiresAt: string | null;
  version: string | null;
  cachedVerdict: {
    instanceId: string | null;
    plan: string | null;
    expiresAt: string | null;
    lastSuccessfulRefresh: string | null;
  } | null;
  /** Aggregated install/visit signals from audit telemetry (optional). */
  installCount?: number;
  popular?: boolean;
  featured?: boolean;
}

export interface MarketplaceTemplate {
  id: number;
  ref: string;
  slug: string;
  label: string;
  description: string;
  category: string;
  tier: MarketplaceTier;
  icon: string;
  cachedAt: string | null;
  source: string;
  /**
   * Workflow JSON definition. SECURITY — set to `null` whenever the
   * instance is not entitled to instantiate this template (i.e. the
   * required tier licence is not active). The frontend MUST only
   * trust the `locked` flag below; the JSON stripping is defence in
   * depth so a copy/paste attacker cannot recover the workflow.
   */
  definition: Record<string, unknown> | null;
  /**
   * `true` when the user lacks an active licence for this template's
   * tier. The "Use template" button must be disabled and the JSON must
   * not be assumed to be present.
   */
  locked?: boolean;
  /**
   * Verbose breakdown of why a template is locked, useful to surface a
   * relevant CTA (e.g. "Pro Pack expired" vs "Pro Pack not installed").
   */
  lockedReason?: { status: string; expiresAt: string | null } | null;
  installCount?: number;
  popular?: boolean;
  featured?: boolean;
}

export interface MarketplaceResponse {
  packs: MarketplacePack[];
  packsMeta: {
    fromCache: boolean;
    stale: boolean;
    error: string | null;
  };
  templates: MarketplaceTemplate[];
  templatesMeta: {
    fromCache: boolean;
    stale: boolean;
    refreshedAt: string | null;
    error: string | null;
  };
  migration: {
    migratedConnectorIds: string[];
    impacted: MigrationScanResponse['impacted'];
    summary: {
      impactedWorkflows: number;
      impactedNodesTotal: number;
      connectorIdsImpacted: string[];
    };
    proPackProductSlug: string;
  };
  backendUrl: string;
  /**
   * Optional editorial layout from the license backend (ignored when absent).
   * Mirrors `frontend/src/views/marketplace/` block registry keys (`hero`, …).
   */
  editorial?: KnotMarketplaceEditorialDTO | null;
}

export interface CredentialSchemaProperty {
  type?: string;
  title?: string;
  description?: string;
  default?: unknown;
  secret?: boolean;
  enum?: string[];
}

export interface CredentialSchema {
  type: string;
  required?: string[];
  properties?: Record<string, CredentialSchemaProperty>;
}

export interface CredentialPayload {
  label: string;
  connectorType: string;
  type: string;
  secrets?: Record<string, unknown>;
  expiresAt?: string | null;
}

export interface CredentialSummary {
  id: number;
  ref: string;
  label: string;
  type: string;
  connectorType: string;
  encryptionVersion: string;
  expiresAt: string | null;
  createdBy: number | null;
  modifiedBy: number | null;
  createdAt: string;
  updatedAt: string;
}

export interface ExecutionSummary {
  id: number;
  workflowId: number;
  status: string;
  triggerType: string;
  triggerData: Record<string, unknown>;
  retryCount: number;
  priority?: number;
  scheduledAt?: string | null;
  nextRetryAt?: string | null;
  maxAttempts?: number;
  backoffStrategy?: string;
  workerId?: string | null;
  startedAt: string | null;
  endedAt: string | null;
  errorMessage: string | null;
  /** Knot ADR-007 payload persisted for async failures (API + CronWorker). */
  errorPayload?: Record<string, unknown> | null;
  durationMs: number | null;
  workflowLabel?: string | null;
  workflowRef?: string | null;
}

export interface QueueRetryRow {
  id: number;
  workflowId: number;
  workflowLabel?: string | null;
  workflowRef?: string | null;
  status: string;
  retryCount: number;
  errorMessage: string | null;
  endedAt: string | null;
  nextRetryAt: string | null;
}

export interface QueueWorkflowQueuedRow {
  workflowId: number;
  queuedCount: number;
  workflowLabel: string | null;
  workflowRef: string | null;
}

export interface QueueDashboardData {
  counts: Record<string, number>;
  topRetries: QueueRetryRow[];
  queuedByWorkflow: QueueWorkflowQueuedRow[];
}

export interface ExecutionLog {
  id: number;
  nodeId: string;
  nodeType: string;
  status: string;
  input: Record<string, unknown>;
  output: Record<string, unknown>;
  durationMs: number | null;
  errorMessage: string | null;
  executedAt: string;
  sequenceOrder: number;
}

export interface DoctorCheck {
  ok: boolean;
  detail: string;
  severity: 'error' | 'warning';
}

/** Session JSON aggregates (`api/observability.php`) — no node payloads. */
export interface ObservabilitySnapshot {
  entity: number;
  window_days: number;
  since_unix: number;
  queue: { waiting: number; running: number };
  executions_total: Array<{ workflow: number; status: string; count: number }>;
  duration_quantiles: Array<{ workflow: number; p50: number; p95: number; p99: number; count: number }>;
  failure_heatmap: Array<{ weekday: number; hour: number; count: number }>;
  nodes_by_type: Array<{ node_type: string; runs: number; errors: number; avg_duration_ms: number | null }>;
}

export interface HealthSnapshot {
  module: string;
  version: string;
  status: 'ok' | 'warning' | 'error' | string;
  checks: Record<string, boolean>;
  workflows: Record<string, number>;
  executions: Record<string, number>;
  failureHeatmap?: Array<{ weekday: number; hour: number; count: number }>;
  failureHeatmapSince?: number;
  setupCompleted: boolean;
  engineEnabled: boolean;
  releaseChannel?: 'beta' | 'stable' | string;
  demoMode?: boolean;
  doctor?: {
    checks: Record<string, DoctorCheck>;
    tables: Record<string, boolean>;
    tablesMissing: string[];
    cron: {
      registered: boolean;
      enabled: boolean;
      lastRun: string | null;
      nextRun: string | null;
      globalEnabled: boolean | null;
    };
    documentsRoot: string;
    introspection?: {
      cachePath: string;
      cacheReadable: boolean;
      descriptorCount: number;
      supportedSlugCount?: number;
    };
  };
}

export interface ApprovalSummary {
  id: number;
  executionId: number | null;
  nodeId: string;
  message: string;
  requesterId: number | null;
  approverRole: string | null;
  status: string;
  createdAt: string;
}
