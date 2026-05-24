/**
 * Lazy canvas hints for dolibarr.object change_status — aligns with inspector verbs/simulate + probable transitions.
 */

import { onBeforeUnmount, ref, watch, type ComputedRef, type Ref } from 'vue';
import { isDiscoveryUnverifiedExpert, normalizeObjectRegistryMode } from '@/lib/objectRegistryMode';
import { getCachedDolibarrVerbsSimulated, getCachedProbableTransitions } from '@/lib/canvasSmRiskCache';
import type { DolibarrVerb } from '@/lib/api';

export type CanvasSmRiskSeverity = 'none' | 'loading' | 'warning' | 'error';

export interface CanvasSmRiskDisplay {
  severity: CanvasSmRiskSeverity;
  /** Backend simulate message or empty */
  detail: string;
}

const HOVER_DELAY_MS = 500;
const CONFIG_DEBOUNCE_MS = 350;

/** Strict positive integer only — expressions / placeholders skip probable_transitions. */
export function parseStrictPositiveIntId(raw: unknown): number | null {
  if (raw === undefined || raw === null) return null;
  const s = String(raw).trim();
  if (s === '') return null;
  if (!/^\d+$/.test(s)) return null;
  const n = parseInt(s, 10);
  return Number.isFinite(n) && n > 0 ? n : null;
}

export function resolveChangeStatusVerb(config: Record<string, unknown>): string {
  const mode = normalizeObjectRegistryMode(config.objectRegistryMode);
  const custom = String(config.statusMethodCustom ?? '').trim();
  if (isDiscoveryUnverifiedExpert(mode) && custom !== '') {
    return custom;
  }
  return String(config.statusMethod ?? 'valid');
}

/**
 * Pure merge after verbs (+ optional probable rows) are loaded — unit-tested without HTTP.
 */
export function mergeVerbAndProbabilityRisk(
  cfg: Record<string, unknown>,
  verbs: DolibarrVerb[],
  probableTransitions: Array<{ method: string; probability: string }> | null,
): CanvasSmRiskDisplay {
  const verbName = resolveChangeStatusVerb(cfg);
  const verbRow = verbs.find((v) => v.name === verbName);
  if (verbRow?.simulateError) {
    return { severity: 'error', detail: String(verbRow.simulateError) };
  }
  if (probableTransitions) {
    const match = probableTransitions.find((r) => r.method === verbName);
    if (match?.probability === 'low') {
      return { severity: 'warning', detail: '' };
    }
  }
  return { severity: 'none', detail: '' };
}

export interface UseCanvasChangeStatusRiskOptions {
  nodeType: ComputedRef<string>;
  config: ComputedRef<Record<string, unknown>>;
  selected: ComputedRef<boolean>;
}

export function useCanvasChangeStatusRisk(opts: UseCanvasChangeStatusRiskOptions): {
  risk: Ref<CanvasSmRiskDisplay>;
  onNodeMouseEnter: () => void;
  onNodeMouseLeave: () => void;
} {
  const risk = ref<CanvasSmRiskDisplay>({ severity: 'none', detail: '' });

  let generation = 0;
  let hoverTimer: ReturnType<typeof setTimeout> | null = null;
  let configTimer: ReturnType<typeof setTimeout> | null = null;

  function cancelHoverTimer(): void {
    if (hoverTimer !== null) {
      clearTimeout(hoverTimer);
      hoverTimer = null;
    }
  }

  function bumpGeneration(): void {
    generation += 1;
  }

  async function evaluate(reason: string): Promise<void> {
    void reason;
    const myGen = generation;
    const type = opts.nodeType.value;
    const cfg = opts.config.value;

    if (type !== 'dolibarr.object') {
      risk.value = { severity: 'none', detail: '' };
      return;
    }

    const op = String(cfg.operation ?? cfg.action ?? '');
    if (op !== 'change_status') {
      risk.value = { severity: 'none', detail: '' };
      return;
    }

    const slug = String(cfg.objectType ?? '').trim();
    if (!slug) {
      risk.value = { severity: 'none', detail: '' };
      return;
    }

    risk.value = { severity: 'loading', detail: '' };

    try {
      const verbs = await getCachedDolibarrVerbsSimulated(slug);
      if (myGen !== generation) return;

      let probableRows: Array<{ method: string; probability: string }> | null = null;
      const id = parseStrictPositiveIntId(cfg.id);
      if (id !== null) {
        const prob = await getCachedProbableTransitions(slug, id);
        if (myGen !== generation) return;
        probableRows = Array.isArray(prob.probableTransitions) ? prob.probableTransitions : [];
      }

      risk.value = mergeVerbAndProbabilityRisk(cfg, verbs, probableRows);
    } catch {
      if (myGen !== generation) return;
      risk.value = { severity: 'none', detail: '' };
    }
  }

  function scheduleEvaluate(reason: string): void {
    bumpGeneration();
    void evaluate(reason);
  }

  function scheduleConfigEvaluate(): void {
    if (configTimer !== null) clearTimeout(configTimer);
    configTimer = setTimeout(() => {
      configTimer = null;
      if (!opts.selected.value) return;
      scheduleEvaluate('config');
    }, CONFIG_DEBOUNCE_MS);
  }

  watch(
    () => [
      opts.nodeType.value,
      opts.config.value.operation,
      opts.config.value.action,
      opts.config.value.objectType,
      opts.config.value.objectRegistryMode,
      opts.config.value.statusMethod,
      opts.config.value.statusMethodCustom,
      opts.config.value.id,
    ],
    () => {
      if (!opts.selected.value) return;
      scheduleConfigEvaluate();
    },
  );

  watch(
    () => opts.selected.value,
    (sel) => {
      cancelHoverTimer();
      if (!sel) {
        bumpGeneration();
        risk.value = { severity: 'none', detail: '' };
        return;
      }
      scheduleEvaluate('focus');
    },
  );

  function onNodeMouseEnter(): void {
    cancelHoverTimer();
    hoverTimer = setTimeout(() => {
      hoverTimer = null;
      scheduleEvaluate('hover');
    }, HOVER_DELAY_MS);
  }

  function onNodeMouseLeave(): void {
    cancelHoverTimer();
  }

  onBeforeUnmount(() => {
    cancelHoverTimer();
    if (configTimer !== null) clearTimeout(configTimer);
    bumpGeneration();
  });

  return {
    risk,
    onNodeMouseEnter,
    onNodeMouseLeave,
  };
}
