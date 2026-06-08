/**
 * Quick-add from node handles (n8n-style).
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import type { InjectionKey } from 'vue';

export interface KnotQuickAddContext {
  startQuickAdd: (sourceId: string, sourceHandle: string) => void;
  pendingSource: { sourceId: string; sourceHandle: string } | null;
}

export const KNOT_QUICK_ADD_KEY: InjectionKey<KnotQuickAddContext> = Symbol('knotQuickAdd');
