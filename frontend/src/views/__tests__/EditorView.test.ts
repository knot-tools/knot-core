import { defineComponent, h, type PropType } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { i18n } from '../../i18n';
import { provideConfirm } from '../../composables/useConfirm';
import { provideToast } from '../../composables/useToast';

vi.mock('@vue-flow/core', () => ({
  VueFlow: defineComponent({
    name: 'VueFlow',
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
  props: {
    workflowId: { type: Number as PropType<number | null>, default: null },
    executionId: { type: Number as PropType<number | null>, default: null },
  },
  setup(props) {
    provideToast();
    provideConfirm();
    return () =>
      h(EditorView, {
        workflowId: props.workflowId,
        executionId: props.executionId,
      });
  },
});

describe('EditorView', () => {
  beforeEach(() => {
    (window as unknown as Record<string, unknown>).KNOT_LOCALE = 'en_US';
    i18n.global.locale.value = 'en_US';
    vi.spyOn(knotApi, 'getWorkflow').mockRejectedValue(new Error('not used in blank editor'));
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('renders palette and inspector shell for a blank workflow', async () => {
    const wrapper = mount(EditorHost, {
      props: { workflowId: null, executionId: null },
      attachTo: document.body,
      global: { plugins: [i18n] },
    });
    await flushPromises();

    const layout = wrapper.find('[data-knot-test="knot-editor-layout"]');
    expect(layout.exists()).toBe(true);
    expect(layout.classes()).toContain('knot-editor-layout');
    expect(layout.classes()).not.toContain('k-grid-cols-1');
    expect(wrapper.find('[data-knot-test="knot-editor-palette"]').exists()).toBe(true);
    expect(wrapper.find('[data-knot-test="knot-inspector-aside"]').exists()).toBe(true);
    expect(wrapper.find('main').classes()).toContain('k-min-h-0');
    expect(wrapper.find('[data-knot-test="vue-flow-stub"]').classes()).toContain('k-h-full');
    expect(wrapper.find('[data-knot-palette-node="trigger.manual"]').exists()).toBe(true);
    expect(document.body.textContent).toContain('Knot');

    wrapper.unmount();
  });

  it('loads an existing workflow on mount', async () => {
    const mockWorkflow: Workflow = {
      id: 42,
      label: 'Loaded workflow',
      ref: 'WF-42',
      status: 'draft',
      description: '',
      schemaVersion: '1.0',
      entity: 1,
      createdBy: null,
      modifiedBy: null,
      createdAt: '2026-05-18T00:00:00+00:00',
      updatedAt: '2026-05-18T00:00:00+00:00',
      definition: { schemaVersion: '1.0', nodes: [], edges: [] },
      risk: {
        worstLevel: 'safe',
        hasCritical: false,
        criticalNodes: [],
        sideEffects: [],
      },
    };
    vi.spyOn(knotApi, 'getWorkflow').mockResolvedValue({ workflow: mockWorkflow });

    const wrapper = mount(EditorHost, {
      props: { workflowId: 42, executionId: null },
      attachTo: document.body,
      global: { plugins: [i18n] },
    });
    await flushPromises();

    expect(knotApi.getWorkflow).toHaveBeenCalledWith(42);
    const titleInput = wrapper.find('main input');
    expect((titleInput.element as HTMLInputElement).value).toBe('Loaded workflow');
    expect(document.body.textContent).toContain('Workflow ID');

    wrapper.unmount();
  });
});
