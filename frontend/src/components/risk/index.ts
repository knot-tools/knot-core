/**
 * Risk-aware UI components — V2.5 UX-3 sprint.
 *
 * These components are stub-grade and ship un-wired in V2.5. They are
 * imported on-demand by the editor as it migrates to the new visual risk
 * grammar (see docs/ux/risk-grammar.md).
 *
 * Public re-exports kept stable so external code (Pro Pack, tests) can
 * depend on the path `@/components/risk`.
 */

export { default as KnotNodeRiskBadge } from './KnotNodeRiskBadge.vue';
export { default as WorkflowActivationDialog } from './WorkflowActivationDialog.vue';
export { default as TestSplitButton } from './TestSplitButton.vue';
export { default as KnotExpressionInput } from './KnotExpressionInput.vue';
export { default as ExecutionErrorPanel } from './ExecutionErrorPanel.vue';
export { translateError, extractKnotPayloadFromUnknown, translateExecutionError, safeExecutionDocHref } from './ExecutionErrorTranslator';
export type {
  TranslatedError,
  ErrorBucket,
  KnotErrorPayload,
  UnifiedExecutionError,
} from './ExecutionErrorTranslator';
