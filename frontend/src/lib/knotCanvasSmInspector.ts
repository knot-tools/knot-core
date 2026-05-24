/**
 * Minimal injection for canvas → inspector focus (Dolibarr change_status hints).
 */

import type { InjectionKey } from 'vue';

export interface KnotCanvasSmInspectorApi {
  focusChangeStatusHints: () => void;
}

export const KNOT_CANVAS_SM_INSPECTOR: InjectionKey<KnotCanvasSmInspectorApi> = Symbol('knotCanvasSmInspector');
