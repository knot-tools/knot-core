import { describe, expect, it } from 'vitest';

import { repairWorkflowDefinitionNodes } from '../workflowDefinitionRepair';

describe('workflowDefinitionRepair', () => {
  it('repairs if operator, sql key, objectType alias, and email body escapes', () => {
    const nodes = repairWorkflowDefinitionNodes([
      {
        id: 'read1',
        type: 'dolibarr.read_object',
        config: { objectType: 'invoice', objectId: '{{$json.objectId}}' },
      },
      {
        id: 'sql1',
        type: 'dolibarr.sql_query',
        config: { sql: 'SELECT 1' },
      },
      {
        id: 'if1',
        type: 'logic.if',
        config: {
          conditions: [{ left: '{{$json.amount}}', operator: '>=', right: '500' }],
        },
      },
      {
        id: 'mail1',
        type: 'action.email',
        config: { to: 'a@b.test', subject: 'S', body: 'Hi\\n\\nIBAN: {{$json.rows[0].iban}}' },
      },
    ]);

    expect(nodes[0]).toMatchObject({ config: { objectType: 'facture' } });
    expect(nodes[1]).toMatchObject({ config: { query: 'SELECT 1' } });
    expect((nodes[1] as { config: Record<string, unknown> }).config.sql).toBeUndefined();
    expect(nodes[2]).toMatchObject({
      config: { conditions: [{ operator: 'greater_equal' }] },
    });
    const body = (nodes[3] as { config: { body: string } }).config.body;
    expect(body).toContain('\n\n');
    expect(body).not.toContain('\\n');
  });
});
