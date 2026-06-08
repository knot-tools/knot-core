/**
 * Repairs common assistant/import DSL mistakes in workflow node configs.
 * Keep in sync with PHP WorkflowDefinitionNormalizer.
 */

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
