/*
 * chatbotFix — shared "copy fix for chatbot" message builder.
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 *
 * Used by AssistantView (import flow) and the editor ProblemsPanel so
 * beta testers can hand the current lint findings + JSON to an external
 * chatbot and paste the corrected workflow back (assistant-prompt-spec).
 */
import type { ValidationIssue } from './validator';
import { formatValidationIssueMessage } from './validator';

const DSL_CONTRACT_BASE = `\
## Knot workflow DSL contract

- schemaVersion MUST be "1.0".
- Every node needs: id (string, unique), type (string, a valid installed connector slug),
  label (string), position ({x,y}), config (object), credentials (null or {credentialRef}).
- Edges MUST use "source" and "target" (never "from"/"to").
  Required edge fields: id, source, target, sourceHandle, targetHandle, type ("knot").
- sourceHandle values depend on the connector: "main", "true"/"false" (logic.if),
  "iteration"/"done" (logic.loop), "error" (error branch).
- targetHandle is usually "main".
- Expressions use {{ }} delimiters: {{$nodes.nodeId.json.field}} (preferred),
  {{$loop.item.field}} inside loop iteration, {{$json.field}} only for trigger
  payload fields (e.g. objectId right after trigger.dolibarr_event),
  {{$workflow.ref}}, {{$execution.id}}, {{$now}}, {{$env.VAR}}, {{$vars.ref}}.
- Prefer {{$nodes.<producerId>.json.<field>}} for logic.if left, logic.loop
  itemsPath, and email to/body. Using {{$json.X}} after a non-trigger node
  triggers lint expression_json_chain.
- Credentials are NEVER inline secrets — only a credentialRef string.`;

const FALLBACK_SLUGS = [
  'trigger.manual',
  'trigger.cron',
  'trigger.webhook',
  'trigger.dolibarr',
  'dolibarr.read_object',
  'dolibarr.object',
  'dolibarr.sql_query',
  'logic.if',
  'logic.loop',
  'logic.set',
  'logic.switch',
  'logic.merge',
  'logic.delay',
  'logic.noop',
  'action.email',
  'notification.alert',
];

const MINIMAL_EXAMPLE = `\
{
  "schemaVersion": "1.0",
  "nodes": [
    {"id":"trigger_1","type":"trigger.manual","label":"Start","position":{"x":80,"y":120},"config":{},"credentials":null,"notes":""},
    {"id":"set_1","type":"logic.set","label":"Set data","position":{"x":360,"y":120},"config":{"values":{"msg":"Hello {{$workflow.ref}}"}},"credentials":null,"notes":""}
  ],
  "edges": [
    {"id":"e1","source":"trigger_1","target":"set_1","sourceHandle":"main","targetHandle":"main","type":"knot"}
  ],
  "metadata":{"createdWith":"Knot"}
}`;

function buildSchemaHints(issues: ValidationIssue[]): string {
  const nodeIds = new Set<string>();
  for (const issue of issues) {
    if (issue.nodeId) {
      nodeIds.add(issue.nodeId);
    }
  }
  if (nodeIds.size === 0) return '';
  return `\nNodes with findings: ${[...nodeIds].join(', ')}. Pay special attention to their config.`;
}

function buildInstalledSlugsSection(installedSlugs?: string[]): string {
  const slugs = (installedSlugs ?? [])
    .map((s) => s.trim())
    .filter((s) => s.length > 0);
  const list = slugs.length > 0 ? [...new Set(slugs)].sort() : FALLBACK_SLUGS;
  const source = slugs.length > 0
    ? 'Only use connector slugs from this instance catalogue (capabilities API):'
    : 'Only use connector slugs that are actually installed on the instance. Core fallback catalogue:';
  return `${source}\n${list.map((s) => `- ${s}`).join('\n')}`;
}

function buildDslContract(installedSlugs?: string[]): string {
  return `${DSL_CONTRACT_BASE}\n\n${buildInstalledSlugsSection(installedSlugs)}`;
}

export type ChatbotFixOptions = {
  /** When true, omit the full DSL contract and example — remaining findings only. */
  incremental?: boolean;
  /** Installed connector slugs from capabilities / connectors API. */
  installedSlugs?: string[];
};

export function buildChatbotFixMessage(
  issues: ValidationIssue[],
  json: string,
  options: ChatbotFixOptions = {},
): string {
  const lines = issues.map(
    (issue) => `- [${issue.severity}] ${formatValidationIssueMessage(issue)}${issue.nodeId ? ` (node: ${issue.nodeId})` : ''}`,
  );

  const schemaHints = buildSchemaHints(issues);
  const incremental = options.incremental === true;
  const slugHint = buildInstalledSlugsSection(options.installedSlugs);

  if (incremental) {
    return [
      'Continue fixing this Knot workflow JSON. Focus ONLY on the remaining validation findings below.',
      'Return a single valid ```json``` block (schemaVersion "1.0", nodes[], edges[]).',
      'Do NOT invent connector slugs. Keep structure and intent unchanged.',
      '',
      slugHint,
      '',
      '## Remaining validation findings',
      '',
      ...(lines.length > 0 ? lines : ['(none)']),
      schemaHints,
      '',
      '## Current workflow JSON',
      '',
      json.trim(),
    ].join('\n');
  }

  return [
    'Fix this Knot workflow JSON according to the validation findings below.',
    'Return a single valid ```json``` block (schemaVersion "1.0", nodes[], edges[]).',
    'Do NOT invent connector slugs that are not in the installed catalogue below.',
    'Keep the overall structure and intent unchanged — only fix the reported issues.',
    '',
    buildDslContract(options.installedSlugs),
    '',
    '## Minimal valid example',
    '',
    MINIMAL_EXAMPLE,
    '',
    '## Validation findings',
    '',
    ...(lines.length > 0 ? lines : ['(none)']),
    schemaHints,
    '',
    '## Current workflow JSON',
    '',
    json.trim(),
  ].join('\n');
}
