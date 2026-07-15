/**
 * Persist lightweight editor UI state across full-page mode reloads.
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 *
 * Knot Core still navigates with `?mode=` full reloads (no SPA router).
 * This helper keeps canvas selection / viewport hints in sessionStorage
 * so returning to the editor restores context without a full SPA rewrite.
 */

const STORAGE_KEY = 'knot.editor.uiState.v1';

export type EditorUiState = {
  workflowId: number | null;
  selectedNodeId: string | null;
  viewport: { x: number; y: number; zoom: number } | null;
  updatedAt: number;
};

export function loadEditorUiState(): EditorUiState | null {
  try {
    const raw = sessionStorage.getItem(STORAGE_KEY);
    if (!raw) return null;
    const parsed = JSON.parse(raw) as EditorUiState;
    if (typeof parsed !== 'object' || parsed === null) return null;
    return parsed;
  } catch {
    return null;
  }
}

export function saveEditorUiState(partial: Partial<EditorUiState>): void {
  try {
    const prev = loadEditorUiState() ?? {
      workflowId: null,
      selectedNodeId: null,
      viewport: null,
      updatedAt: 0,
    };
    const next: EditorUiState = {
      ...prev,
      ...partial,
      updatedAt: Date.now(),
    };
    sessionStorage.setItem(STORAGE_KEY, JSON.stringify(next));
  } catch {
    /* ignore quota / private mode */
  }
}

export function clearEditorUiState(): void {
  try {
    sessionStorage.removeItem(STORAGE_KEY);
  } catch {
    /* ignore */
  }
}
