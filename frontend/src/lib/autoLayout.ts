/* Copyright (C) 2026 Knot — GPL-3.0-or-later */
// `@dagrejs/dagre` is the actively maintained ESM-native fork of the
// legacy `dagre` CJS package. The CJS variant breaks Vite 8 / Rollup 5
// IIFE bundling because the default export shape (`dagre.graphlib.Graph`)
// is unreachable through Rollup's CJS-to-ESM interop wrapper, producing
// a "Cannot read properties of undefined (reading 'Graph')" at runtime.
import { graphlib, layout } from '@dagrejs/dagre';
import type { Edge, Node } from '@vue-flow/core';

export function autoLayout(nodes: Node[], edges: Edge[]): Node[] {
  const g = new graphlib.Graph();
  g.setGraph({ rankdir: 'LR', nodesep: 60, ranksep: 110, marginx: 20, marginy: 20 });
  g.setDefaultEdgeLabel(() => ({}));

  for (const n of nodes) {
    g.setNode(n.id, { width: 220, height: 90 });
  }
  for (const e of edges) {
    g.setEdge(e.source, e.target);
  }

  layout(g);

  return nodes.map((n) => {
    const dn = g.node(n.id);
    if (!dn) return n;
    return { ...n, position: { x: dn.x - 110, y: dn.y - 45 } };
  });
}
