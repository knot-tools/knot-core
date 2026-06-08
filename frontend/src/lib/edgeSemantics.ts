/**
 * Edge / handle visual semantics for the workflow canvas.
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

export type KnotEdgeSemanticType = 'success' | 'error' | 'conditional' | 'true' | 'false' | 'iteration' | 'done';

export interface HandleLayout {
  id: string;
  position: 'left' | 'right' | 'right-top' | 'right-bottom' | 'bottom';
  color: string;
  labelKey?: string;
}

const HANDLE_COLORS: Record<string, string> = {
  main: 'var(--knot-edge-main, #6366f1)',
  true: 'var(--knot-edge-true, #22c55e)',
  false: 'var(--knot-edge-false, #94a3b8)',
  iteration: 'var(--knot-edge-iteration, #8b5cf6)',
  done: 'var(--knot-edge-done, #6366f1)',
  error: 'var(--knot-edge-error, #ef4444)',
};

export function handleColor(handleId: string | null | undefined, fallback = HANDLE_COLORS.main): string {
  if (!handleId) return fallback;
  return HANDLE_COLORS[handleId] ?? fallback;
}

export function deriveEdgeType(sourceHandle: string | null | undefined): KnotEdgeSemanticType {
  switch (sourceHandle) {
    case 'error':
      return 'error';
    case 'true':
      return 'true';
    case 'false':
      return 'false';
    case 'iteration':
      return 'iteration';
    case 'done':
      return 'done';
    case 'main':
    default:
      return 'success';
  }
}

export function edgeStrokeColor(type: KnotEdgeSemanticType | undefined): string {
  switch (type) {
    case 'error':
      return HANDLE_COLORS.error;
    case 'true':
      return HANDLE_COLORS.true;
    case 'false':
      return HANDLE_COLORS.false;
    case 'iteration':
      return HANDLE_COLORS.iteration;
    case 'done':
      return HANDLE_COLORS.done;
    case 'conditional':
      return '#f59e0b';
    case 'success':
    default:
      return HANDLE_COLORS.main;
  }
}

export function edgeMarker(
  type: KnotEdgeSemanticType | undefined,
  markerType: string,
): { type: string; color: string; width: number; height: number } {
  return {
    type: markerType,
    color: edgeStrokeColor(type),
    width: 18,
    height: 18,
  };
}

export function handleLabelKey(handleId: string): string | undefined {
  const keys: Record<string, string> = {
    main: 'editor.handles.main',
    true: 'editor.handles.true',
    false: 'editor.handles.false',
    iteration: 'editor.handles.iteration',
    done: 'editor.handles.done',
    error: 'editor.handles.error',
  };
  return keys[handleId];
}

export function layoutForOutput(
  outputId: string,
  category: string,
  defaultColor: string,
): Pick<HandleLayout, 'position' | 'color'> {
  switch (outputId) {
    case 'true':
      return { position: 'right-top', color: HANDLE_COLORS.true };
    case 'false':
      return { position: 'right-bottom', color: HANDLE_COLORS.false };
    case 'iteration':
      return { position: 'right-top', color: HANDLE_COLORS.iteration };
    case 'done':
      return { position: 'right-bottom', color: HANDLE_COLORS.done };
    case 'error':
      return { position: 'bottom', color: HANDLE_COLORS.error };
    case 'main':
    default:
      return { position: 'right', color: category === 'trigger' ? defaultColor : HANDLE_COLORS.main };
  }
}

export function defaultOutputsForCategory(category: string): Array<{ id: string; label: string }> {
  if (category === 'trigger') {
    return [{ id: 'main', label: 'Main' }];
  }
  return [
    { id: 'main', label: 'Main' },
    { id: 'error', label: 'Error' },
  ];
}

/** Fields applied when creating or loading a Knot edge (EditorView.buildKnotEdge). */
export function buildKnotEdgeFields(
  sourceHandle: string | null | undefined,
  animated = false,
  markerType = 'arrowclosed',
) {
  const edgeType = deriveEdgeType(sourceHandle ?? null);
  return {
    type: 'knot' as const,
    data: { type: edgeType },
    animated,
    markerEnd: edgeMarker(edgeType, markerType),
  };
}
