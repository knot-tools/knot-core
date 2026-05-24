/**
 * Risk parity: resolveNodeRisk vs tests/fixtures/risk-parity-workflows.json
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */
import { readFileSync } from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { describe, expect, it } from 'vitest';
import { resolveNodeRisk, type ConnectorRiskMetadata, type RiskLevel } from '../useNodeRisk';

const _dirname = path.dirname(fileURLToPath(import.meta.url));
const fixturePath = path.join(_dirname, '../../../../tests/fixtures/risk-parity-workflows.json');

interface ParityRow {
  id: string;
  type: string;
  config: Record<string, unknown>;
  expectedRiskLevel: RiskLevel;
  metadata: ConnectorRiskMetadata | null;
}

interface ParityFile {
  cases: ParityRow[];
}

function loadFixture(): ParityFile {
  const raw = readFileSync(fixturePath, 'utf8');
  return JSON.parse(raw) as ParityFile;
}

describe('workflow risk parity (fixture JSON)', () => {
  it('runs resolveNodeRisk for every fixture row (>= 20)', () => {
    const data = loadFixture();
    expect(data.cases.length).toBeGreaterThanOrEqual(20);

    for (const row of data.cases) {
      const r = resolveNodeRisk({
        config: row.config,
        metadata: row.metadata,
      });
      expect(r.riskLevel, `case ${row.id}`).toBe(row.expectedRiskLevel);
    }
  });
});
