/**
 * Knot — DynamicForm repeater + schema (Vitest jsdom).
 */
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

import DynamicForm from '@/components/inspector/DynamicForm.vue';
import KnotExpressionInput from '@/components/risk/KnotExpressionInput.vue';
import { i18n } from '@/i18n';

const mountOptions = {
  global: {
    plugins: [i18n],
    stubs: {
      DolibarrPicker: true,
      Search: true,
      Variable: true,
    },
  },
};

describe('DynamicForm', () => {
  it('renders match mode and repeater for array-of-objects schema', () => {
    const wrapper = mount(DynamicForm, {
      attachTo: document.body,
      props: {
        schema: {
          type: 'object',
          properties: {
            mode: {
              type: 'string',
              title: 'Match mode',
              enum: ['all', 'any'],
              default: 'all',
              'x-position': 0,
            },
            conditions: {
              type: 'array',
              title: 'Conditions',
              'x-position': 1,
              items: {
                type: 'object',
                properties: {
                  left: { type: 'string', title: 'Left operand', 'x-position': 0 },
                  operator: {
                    type: 'string',
                    title: 'Operator',
                    enum: ['equals', 'contains'],
                    default: 'equals',
                    'x-position': 1,
                  },
                },
              },
            },
          },
        },
        modelValue: {
          mode: 'all',
          conditions: [{ left: '{{ $json.x }}', operator: 'equals' }],
        },
      },
      ...mountOptions,
    });

    expect(wrapper.text()).toContain('Match mode');
    expect(wrapper.text()).toContain('Conditions');
    expect(wrapper.text()).toContain('Add row');
    expect(wrapper.text()).not.toMatch(/No schema defined/i);

    wrapper.unmount();
  });

  it('uses KnotExpressionInput when field value is an expression', () => {
    const wrapper = mount(DynamicForm, {
      attachTo: document.body,
      props: {
        schema: {
          type: 'object',
          properties: {
            subject: {
              type: 'string',
              title: 'Subject',
              'x-position': 0,
            },
          },
        },
        modelValue: {
          subject: '{{ $trigger.json.id }}',
        },
      },
      ...mountOptions,
    });

    expect(wrapper.findComponent(KnotExpressionInput).exists()).toBe(true);
    wrapper.unmount();
  });

  it('shows no-fields message when schema has empty properties', () => {
    const wrapper = mount(DynamicForm, {
      attachTo: document.body,
      props: {
        schema: { type: 'object', properties: {} },
        modelValue: {},
      },
      ...mountOptions,
    });

    expect(wrapper.text()).toMatch(/No configurable fields/i);
    wrapper.unmount();
  });
});
