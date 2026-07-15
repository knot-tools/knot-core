// chatbotFix — shared "copy fix for chatbot" message builder.
// Copyright (C) 2026 Knot — GPL-3.0-or-later
import { describe, expect, it } from 'vitest';
import { buildChatbotFixMessage } from '../chatbotFix';
import type { ValidationIssue } from '../validator';

describe('buildChatbotFixMessage', () => {
  it('lists issues with severity and embeds the JSON payload', () => {
    const issues: ValidationIssue[] = [
      { code: 'KNOT_DSL_EXPRESSION_JSON_CHAIN', severity: 'warning', message: 'Prefer $nodes.upstream.json.x', nodeId: 'n1' },
      { code: 'KNOT_EDGE_TARGET_MISSING', severity: 'error', message: 'Edge target missing' },
    ] as ValidationIssue[];

    const text = buildChatbotFixMessage(issues, '{\n  "schemaVersion": "1.0"\n}\n');

    expect(text).toContain('- [warning]');
    expect(text).toContain('- [error]');
    expect(text).toContain('"schemaVersion": "1.0"');
    expect(text).toContain('```json```');
  });

  it('includes the DSL contract with schema hints', () => {
    const issues: ValidationIssue[] = [
      { code: 'http_url_missing', severity: 'error', message: 'URL missing', nodeId: 'http_1' },
    ] as ValidationIssue[];

    const text = buildChatbotFixMessage(issues, '{}');

    expect(text).toContain('Knot workflow DSL contract');
    expect(text).toContain('schemaVersion MUST be "1.0"');
    expect(text).toContain('Edges MUST use "source" and "target"');
    expect(text).toContain('trigger.manual');
    expect(text).toContain('Minimal valid example');
    expect(text).toContain('(node: http_1)');
    expect(text).toContain('Nodes with findings: http_1');
    expect(text).toContain('expression_json_chain');
    expect(text).toContain('$nodes.<producerId>.json');
  });

  it('produces a prompt without issue lines when the list is empty', () => {
    const text = buildChatbotFixMessage([], '{}');
    expect(text).toContain('Current workflow JSON');
    expect(text).toContain('{}');
    expect(text).toContain('(none)');
  });

  it('prompt text is in English', () => {
    const text = buildChatbotFixMessage([], '{}');
    expect(text).toContain('Fix this Knot workflow JSON');
    expect(text).not.toContain('Corrige ce workflow');
  });

  it('incremental mode omits DSL contract and focuses on remaining findings', () => {
    const issues: ValidationIssue[] = [
      { code: 'KNOT_EDGE_TARGET_MISSING', severity: 'error', message: 'Edge target missing', nodeId: 'n2' },
    ] as ValidationIssue[];

    const text = buildChatbotFixMessage(issues, '{"schemaVersion":"1.0"}', { incremental: true });

    expect(text).toContain('Continue fixing');
    expect(text).toContain('Remaining validation findings');
    expect(text).toContain('Edge target missing');
    expect(text).not.toContain('Knot workflow DSL contract');
    expect(text).not.toContain('Minimal valid example');
  });

  it('embeds installed connector slugs from capabilities when provided', () => {
    const text = buildChatbotFixMessage([], '{}', {
      installedSlugs: ['trigger.manual', 'action.stripe', 'logic.if'],
    });
    expect(text).toContain('capabilities API');
    expect(text).toContain('- action.stripe');
    expect(text).toContain('- logic.if');
    expect(text).toContain('- trigger.manual');
  });
});
