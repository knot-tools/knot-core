import { defineComponent, h, provide, ref } from 'vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { i18n } from '@/i18n';
import { KNOT_CONNECTORS_KEY } from '@/lib/knotConnectorContext';
import KnotNode from '../KnotNode.vue';

vi.mock('@vue-flow/core', () => ({
  Handle: defineComponent({
    name: 'Handle',
    setup(_, { attrs }) {
      const extraClass = attrs.class;
      return () =>
        h('div', {
          ...attrs,
          class: ['vue-flow__handle', extraClass].filter(Boolean),
        });
    },
  }),
  Position: { Left: 'left', Right: 'right', Top: 'top', Bottom: 'bottom' },
  useVueFlow: () => ({ edges: { value: [] } }),
}));

vi.mock('@/composables/useCanvasChangeStatusRisk', () => ({
  useCanvasChangeStatusRisk: () => ({
    risk: { riskLevel: 'safe' },
    onNodeMouseEnter: vi.fn(),
    onNodeMouseLeave: vi.fn(),
  }),
}));

vi.mock('@/composables/useNodeRisk', () => ({
  useNodeRisk: () => ({
    riskLevel: 'safe',
    iconName: '',
    badgeLabel: '',
  }),
}));

const Host = defineComponent({
  components: { KnotNode },
  props: {
    status: { type: String, default: 'idle' },
    branchDimmed: { type: Boolean, default: false },
  },
  setup(props) {
    provide(KNOT_CONNECTORS_KEY, ref([
      {
        metadata: { id: 'logic.if', labelKey: 'connectors.logic.if.label' },
        outputs: [
          { id: 'true', label: 'True' },
          { id: 'false', label: 'False' },
        ],
      },
    ]));
    return () =>
      h(KnotNode, {
        id: 'if1',
        selected: false,
        data: {
          type: 'logic.if',
          label: 'If',
          status: props.status,
          branchDimmed: props.branchDimmed,
          dimmedHandles: props.status === 'success' ? ['false'] : [],
        },
      });
  },
});

describe('KnotNode execution visuals', () => {
  it('applies running halo class', () => {
    const wrapper = mount(Host, {
      props: { status: 'running' },
      global: { plugins: [i18n] },
    });
    expect(wrapper.find('.knot-node--running').exists()).toBe(true);
  });

  it('dims skipped branch nodes', () => {
    const wrapper = mount(Host, {
      props: { branchDimmed: true },
      global: { plugins: [i18n] },
    });
    expect(wrapper.find('.knot-node--dimmed').exists()).toBe(true);
  });

  it('renders branch handles from connector outputs', () => {
    const wrapper = mount(Host, {
      global: { plugins: [i18n] },
    });
    expect(wrapper.findAll('.knot-node__handle-anchor').length).toBe(2);
    expect(wrapper.findAll('.vue-flow__handle').length).toBeGreaterThanOrEqual(2);
  });

  it('applies hex icon gradient from registry color', () => {
    const wrapper = mount(Host, {
      global: { plugins: [i18n] },
    });
    const icon = wrapper.find('.knot-node__icon');
    expect(icon.attributes('style')).toMatch(/245,\s*158,\s*11|#f59e0b/i);
    expect(icon.attributes('style')).toContain('linear-gradient');
  });
});
