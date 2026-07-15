import { defineComponent, h } from 'vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { i18n } from '@/i18n';
import KnotEdge from '../KnotEdge.vue';

vi.mock('@vue-flow/core', async () => {
  const vue = await import('vue');
  return {
    BaseEdge: vue.defineComponent({
      name: 'BaseEdge',
      props: ['path', 'style'],
      emits: ['mouseenter', 'mouseleave'],
      setup(props, { emit }) {
        return () =>
          h('path', {
            class: 'base-edge-stub',
            'data-path': props.path,
            onMouseenter: () => emit('mouseenter'),
            onMouseleave: () => emit('mouseleave'),
          });
      },
    }),
    EdgeLabelRenderer: vue.defineComponent({
      name: 'EdgeLabelRenderer',
      setup(_, { slots }) {
        return () => h('div', { class: 'edge-label-renderer' }, slots.default?.());
      },
    }),
    getSmoothStepPath: () => ['M0,0 L100,0', 50, 0],
    useVueFlow: () => ({ removeEdges: vi.fn() }),
    Position: { Left: 'left', Right: 'right', Top: 'top', Bottom: 'bottom' },
  };
});

const Host = defineComponent({
  components: { KnotEdge },
  setup() {
    return () =>
      h(KnotEdge, {
        id: 'e1',
        source: 'a',
        target: 'b',
        sourceX: 0,
        sourceY: 0,
        targetX: 100,
        targetY: 0,
        sourcePosition: 'right',
        targetPosition: 'left',
        sourceHandleId: 'true',
        selected: false,
        animated: true,
        data: { type: 'true' },
        markerEnd: 'url(#arrow)',
      });
  },
});

describe('KnotEdge', () => {
  it('renders solid stroke path and flow dot when animated', () => {
    const wrapper = mount(Host, {
      global: { plugins: [i18n] },
    });
    expect(wrapper.find('linearGradient').exists()).toBe(false);
    expect(wrapper.find('.base-edge-stub').exists()).toBe(true);
    expect(wrapper.find('.knot-edge-flow-dot').exists()).toBe(true);
  });

  it('shows delete control on hover', async () => {
    const wrapper = mount(Host, {
      global: { plugins: [i18n] },
    });
    expect(wrapper.find('.knot-edge-delete').exists()).toBe(false);
    await wrapper.find('.knot-edge-hit').trigger('mouseenter');
    expect(wrapper.find('.knot-edge-delete').exists()).toBe(true);
  });
});
