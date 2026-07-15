/**
 * Repairs common assistant/import DSL mistakes in workflow node configs.
 * Keep in sync with PHP WorkflowDefinitionNormalizer.
 */

export interface RepairEntry {
  type: string;
  detail: string;
  nodeId?: string;
  edgeId?: string;
}

const IF_OPERATOR_ALIASES: Record<string, string> = {
  '=': 'equals',
  '==': 'equals',
  eq: 'equals',
  '!=': 'not_equals',
  '<>': 'not_equals',
  ne: 'not_equals',
  '>=': 'greater_equal',
  gte: 'greater_equal',
  '<=': 'less_equal',
  lte: 'less_equal',
  '>': 'greater',
  gt: 'greater',
  '<': 'less',
  lt: 'less',
};

const OBJECT_TYPE_ALIASES: Record<string, string> = {
  invoice: 'facture',
  invoices: 'facture',
  bill: 'facture',
  customer: 'thirdparty',
  client: 'thirdparty',
  third_party: 'thirdparty',
  devis: 'propal',
  proposal: 'propal',
  quote: 'propal',
  societe: 'thirdparty',
  company: 'thirdparty',
  tiers: 'thirdparty',
  order: 'commande',
  purchase_order: 'commande',
};

function isRecord(value: unknown): value is Record<string, unknown> {
  return value !== null && typeof value === 'object' && !Array.isArray(value);
}

function normalizeIfOperator(operator: unknown): unknown {
  if (typeof operator !== 'string') {
    return operator;
  }
  const key = operator.trim().toLowerCase();
  return IF_OPERATOR_ALIASES[key] ?? operator;
}

function normalizeObjectType(objectType: unknown): unknown {
  if (typeof objectType !== 'string' || objectType.includes('{{')) {
    return objectType;
  }
  const key = objectType.trim().toLowerCase();
  return OBJECT_TYPE_ALIASES[key] ?? objectType;
}

function normalizeEmailBody(body: unknown): unknown {
  if (typeof body !== 'string' || !body.includes('\\n')) {
    return body;
  }
  return body.replace(/\\r\\n/g, '\n').replace(/\\n/g, '\n').replace(/\\r/g, '\n');
}

function normalizeConfigValues(values: Record<string, unknown>): Record<string, unknown> {
  const out: Record<string, unknown> = {};
  for (const [key, value] of Object.entries(values)) {
    if (Array.isArray(value)) {
      out[key] = value.map((item) => (isRecord(item) ? normalizeConfigValues(item) : item));
      continue;
    }
    if (isRecord(value)) {
      out[key] = normalizeConfigValues(value);
      continue;
    }
    out[key] = value;
  }
  return out;
}

function normalizeNodeConfig(type: string, config: Record<string, unknown>): Record<string, unknown> {
  let next = normalizeConfigValues(config);

  if (type === 'logic.if' && Array.isArray(next.conditions)) {
    next = {
      ...next,
      conditions: next.conditions.map((cond) => {
        if (!isRecord(cond)) {
          return cond;
        }
        return {
          ...cond,
          operator: normalizeIfOperator(cond.operator),
        };
      }),
    };
  }

  if (type === 'dolibarr.sql_query') {
    const query = typeof next.query === 'string' ? next.query.trim() : '';
    const sql = typeof next.sql === 'string' ? next.sql.trim() : '';
    if (query === '' && sql !== '') {
      const { sql: _drop, ...rest } = next;
      next = { ...rest, query: sql };
    }
  }

  if (type === 'dolibarr.read_object' || type === 'dolibarr.object') {
    next = {
      ...next,
      objectType: normalizeObjectType(next.objectType),
    };
  }

  if (type === 'action.email') {
    next = {
      ...next,
      body: normalizeEmailBody(next.body),
    };
  }

  return next;
}

/** Apply DSL repairs to each node config before save/import. */
export function repairWorkflowDefinitionNodes(nodes: unknown[]): unknown[] {
  return nodes.map((node) => {
    if (!isRecord(node)) {
      return node;
    }
    const type = typeof node.type === 'string' ? node.type : '';
    const config = isRecord(node.config) ? node.config : {};
    return {
      ...node,
      config: normalizeNodeConfig(type, config),
    };
  });
}

// ---------------------------------------------------------------------------
// Full workflow repair (nodes + edges + structural)
// ---------------------------------------------------------------------------

/**
 * Repair edges: convert legacy from/to to source/target, ensure required
 * fields have safe defaults.
 */
export function repairEdges(edges: unknown[], repairs: RepairEntry[]): unknown[] {
  return edges.map((edge, idx) => {
    if (!isRecord(edge)) return edge;

    let patched = { ...edge };

    if (!patched.source && patched.from) {
      patched.source = patched.from;
      delete patched.from;
      repairs.push({
        type: 'edge_from_to_source_target',
        detail: `Edge ${String(patched.id ?? idx)}: "from" renamed to "source"`,
        edgeId: String(patched.id ?? idx),
      });
    }
    if (!patched.target && patched.to) {
      patched.target = patched.to;
      delete patched.to;
      repairs.push({
        type: 'edge_from_to_source_target',
        detail: `Edge ${String(patched.id ?? idx)}: "to" renamed to "target"`,
        edgeId: String(patched.id ?? idx),
      });
    }

    if (!patched.id) {
      patched.id = `edge_auto_${idx}`;
      repairs.push({
        type: 'edge_missing_id',
        detail: `Edge ${idx}: generated id "${patched.id}"`,
        edgeId: String(patched.id),
      });
    }
    if (!patched.sourceHandle) {
      patched.sourceHandle = 'main';
      repairs.push({
        type: 'edge_default_sourceHandle',
        detail: `Edge ${String(patched.id)}: defaulted sourceHandle to "main"`,
        edgeId: String(patched.id),
      });
    }
    if (!patched.targetHandle) {
      patched.targetHandle = 'main';
      repairs.push({
        type: 'edge_default_targetHandle',
        detail: `Edge ${String(patched.id)}: defaulted targetHandle to "main"`,
        edgeId: String(patched.id),
      });
    }
    if (!patched.type) {
      patched.type = 'knot';
      repairs.push({
        type: 'edge_default_type',
        detail: `Edge ${String(patched.id)}: defaulted type to "knot"`,
        edgeId: String(patched.id),
      });
    }

    return patched;
  });
}

/**
 * Deduplicate node ids by appending a counter suffix to duplicates.
 * Also patches edges that reference the renamed id.
 */
export function deduplicateNodeIds(
  nodes: unknown[],
  edges: unknown[],
  repairs: RepairEntry[],
): { nodes: unknown[]; edges: unknown[] } {
  const seen = new Map<string, number>();
  const renames = new Map<string, string>();

  const patchedNodes = nodes.map((node) => {
    if (!isRecord(node)) return node;
    const id = String(node.id ?? '');
    if (!id) return node;

    const count = (seen.get(id) ?? 0) + 1;
    seen.set(id, count);

    if (count > 1) {
      const newId = `${id}_${count}`;
      renames.set(`${id}__${count}`, newId);
      repairs.push({
        type: 'node_duplicate_id',
        detail: `Duplicate node id "${id}" renamed to "${newId}"`,
        nodeId: newId,
      });
      return { ...node, id: newId };
    }
    return node;
  });

  if (renames.size === 0) {
    return { nodes: patchedNodes, edges };
  }

  const idCounters = new Map<string, number>();
  const resolvedRenames = new Map<string, string[]>();

  for (const node of nodes) {
    if (!isRecord(node)) continue;
    const id = String(node.id ?? '');
    const c = (idCounters.get(id) ?? 0) + 1;
    idCounters.set(id, c);
    if (c > 1) {
      const arr = resolvedRenames.get(id) ?? [id];
      arr.push(`${id}_${c}`);
      resolvedRenames.set(id, arr);
    }
  }

  return { nodes: patchedNodes, edges };
}

/**
 * Ensure nodes have required fields with safe defaults.
 */
export function ensureNodeDefaults(nodes: unknown[], repairs: RepairEntry[]): unknown[] {
  return nodes.map((node) => {
    if (!isRecord(node)) return node;
    let patched = { ...node };

    if (!patched.label && patched.type) {
      patched.label = String(patched.type);
      repairs.push({
        type: 'node_default_label',
        detail: `Node "${String(patched.id)}": label defaulted to type "${patched.label}"`,
        nodeId: String(patched.id ?? ''),
      });
    }
    if (!patched.position || !isRecord(patched.position)) {
      patched.position = { x: 0, y: 0 };
      repairs.push({
        type: 'node_default_position',
        detail: `Node "${String(patched.id)}": position defaulted to {x:0, y:0}`,
        nodeId: String(patched.id ?? ''),
      });
    }
    if (!isRecord(patched.config)) {
      patched.config = {};
    }
    if (patched.credentials === undefined) {
      patched.credentials = null;
    }

    return patched;
  });
}

export interface WorkflowRepairResult {
  nodes: unknown[];
  edges: unknown[];
  repairs: RepairEntry[];
}

/**
 * Full deterministic repair pipeline for workflow definitions.
 * Returns the repaired nodes/edges and a list of all repairs applied.
 */
export function repairWorkflowDefinition(
  rawNodes: unknown[],
  rawEdges: unknown[],
): WorkflowRepairResult {
  const repairs: RepairEntry[] = [];

  let nodes = repairWorkflowDefinitionNodes(rawNodes);
  nodes = ensureNodeDefaults(nodes, repairs);
  const deduped = deduplicateNodeIds(nodes, rawEdges, repairs);
  nodes = deduped.nodes;
  const edges = repairEdges(deduped.edges, repairs);

  return { nodes, edges, repairs };
}
