/**
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest';
import { buildWorkflowRiskSummary } from '../useWorkflowRiskSummary';
import type { ConnectorDescriptor } from '../../lib/api';

function dolibarrConnector(): ConnectorDescriptor {
  return {
    metadata: {
      id: 'dolibarr.object',
      label: 'Dolibarr Object',
      category: 'dolibarr',
      riskLevel: 'critical',
      riskByConfig: {
        fetch: 'safe',
        delete: 'critical',
      },
    },
    configSchema: null,
    credentialType: null,
    credentialSchema: null,
    inputs: [],
    outputs: [],
  };
}

describe('buildWorkflowRiskSummary', () => {
  it('reports critical nodes for delete operation', () => {
    const map = new Map([['dolibarr.object', dolibarrConnector()]]);
    const summary = buildWorkflowRiskSummary(
      [
        {
          id: 'n1',
          data: {
            type: 'dolibarr.object',
            label: 'Remove row',
            config: { operation: 'delete' },
          },
        },
      ],
      map,
    );
    expect(summary.hasCritical).toBe(true);
    expect(summary.criticalNodes).toHaveLength(1);
    expect(summary.criticalNodes[0].nodeId).toBe('n1');
  });
});
