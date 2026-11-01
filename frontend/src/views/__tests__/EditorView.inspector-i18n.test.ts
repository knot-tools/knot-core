/**
 * Knot — inspector header uses the same translated label as the palette (fr_FR).
 */
import { defineComponent, h } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { i18n } from '../../i18n';
import { provideConfirm } from '../../composables/useConfirm';
import { provideToast } from '../../composables/useToast';

vi.mock('@vue-flow/core', () => ({
  VueFlow: defineComponent({
    name: 'VueFlow',
    emits: ['node-click'],
    setup(_, { slots }) {
      return () => h('div', { 'data-knot-test': 'vue-flow-stub' }, slots.default?.());
    },
  }),
  useVueFlow: () => ({
    addNodes: vi.fn(),
    addEdges: vi.fn(),
    removeNodes: vi.fn(),
    screenToFlowCoordinate: vi.fn(() => ({ x: 0, y: 0 })),
    onConnect: vi.fn(),
    setNodes: vi.fn(),
    setEdges: vi.fn(),
    fitView: vi.fn(),
    updateNodeInternals: vi.fn(),
    setViewport: vi.fn(),
    onViewportChange: vi.fn(() => () => undefined),
  }),
  ConnectionMode: { Loose: 'loose' },
  MarkerType: { ArrowClosed: 'arrowclosed' },
}));
vi.mock('@vue-flow/background', () => ({
  Background: defineComponent({ name: 'Background', render: () => h('div') }),
  BackgroundVariant: { Dots: 'dots' },
}));
vi.mock('@vue-flow/controls', () => ({
  Controls: defineComponent({ name: 'Controls', render: () => h('div') }),
}));
vi.mock('@vue-flow/minimap', () => ({
  MiniMap: defineComponent({ name: 'MiniMap', render: () => h('div') }),
}));
vi.mock('../../lib/connectorDescriptorsCache', () => ({
  getConnectorDescriptorsCached: vi.fn(async () => []),
}));

import EditorView from '../EditorView.vue';
import { knotApi, type Workflow } from '../../lib/api';

const EditorHost = defineComponent({
  name: 'EditorHost',
  setup() {
    provideToast();
    provideConfirm();
    return () => h(EditorView, { workflowId: 7, executionId: null });
  },
});

describe('EditorView inspector header (fr_FR)', () => {
  beforeEach(() => {
    (window as unknown as Record<string, unknown>).KNOT_LOCALE = 'fr_FR';
    i18n.global.locale.value = 'fr_FR';
  });
  afterEach(() => {
    i18n.global.locale.value = 'en_US';
    (window as unknown as Record<string, unknown>).KNOT_LOCALE = 'en_US';
    vi.restoreAllMocks();
  });

  it('shows the translated palette label for the selected node', async () => {
    const wf: Workflow = {
      id: 7, label: 'WF', ref: 'WF-7', status: 'draft', description: '', schemaVersion: '1.0',
      entity: 1, createdBy: null, modifiedBy: null,
      createdAt: '2026-11-01T00:00:00+00:00', updatedAt: '2026-11-01T00:00:00+00:00',
      definition: {
        schemaVersion: '1.0',
        nodes: [{ id: 'n1', type: 'logic.set', position: { x: 0, y: 0 }, config: {} }],
        edges: [],
      },
      risk: { worstLevel: 'safe', hasCritical: false, criticalNodes: [], sideEffects: [] },
    } as unknown as Workflow;
    vi.spyOn(knotApi, 'getWorkflow').mockResolvedValue({ workflow: wf });

    const wrapper = mount(EditorHost, { attachTo: document.body, global: { plugins: [i18n] } });
    await flushPromises();
    wrapper.findComponent({ name: 'VueFlow' }).vm.$emit('node-click', { node: { id: 'n1' } });
    await flushPromises();

    const aside = wrapper.find('[data-knot-test="knot-inspector-aside"]');
    expect(aside.text()).toContain('Définir');
    wrapper.unmount();
  });
});
