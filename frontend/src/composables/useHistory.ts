/* Copyright (C) 2026 Knot — GPL-3.0-or-later */
import { ref } from 'vue';
import type { Edge, Node } from '@vue-flow/core';

export interface EditorSnapshot {
  nodes: Node[];
  edges: Edge[];
}

const MAX_STACK = 100;

export function useHistory() {
  const past = ref<EditorSnapshot[]>([]);
  const future = ref<EditorSnapshot[]>([]);

  function clone(value: EditorSnapshot): EditorSnapshot {
    return JSON.parse(JSON.stringify(value)) as EditorSnapshot;
  }

  function record(snap: EditorSnapshot): void {
    (past.value as EditorSnapshot[]).push(clone(snap));
    if (past.value.length > MAX_STACK) (past.value as EditorSnapshot[]).shift();
    future.value = [];
  }

  function undo(current: EditorSnapshot): EditorSnapshot | null {
    const prev = (past.value as EditorSnapshot[]).pop();
    if (!prev) return null;
    (future.value as EditorSnapshot[]).push(clone(current));
    return prev;
  }

  function redo(current: EditorSnapshot): EditorSnapshot | null {
    const next = (future.value as EditorSnapshot[]).pop();
    if (!next) return null;
    (past.value as EditorSnapshot[]).push(clone(current));
    return next;
  }

  function reset() {
    past.value = [];
    future.value = [];
  }

  return {
    past,
    future,
    record,
    undo,
    redo,
    reset,
    canUndo: () => past.value.length > 0,
    canRedo: () => future.value.length > 0,
  };
}
