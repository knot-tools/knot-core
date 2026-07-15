/**
 * Knot — NodeInspector tabs (Vitest jsdom): no Dolibarr E2E.
 */
import { flushPromises, mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import { describe, expect, it } from 'vitest';

import NodeInspectorBody from '@/components/inspector/NodeInspectorBody.vue';
import en from '@/i18n/en_US.json';

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  fallbackLocale: 'en',
  messages: { en },
});

const panelStubs: Record<string, unknown> = {
  DynamicForm: { template: '<div class="dynamic-form-stub"><slot /></div>' },
  DolibarrObjectPanel: {
    props: ['modelValue'],
    template: '<div class="dol-object-panel-stub">{{ modelValue.operation }} {{ modelValue.objectType }}</div>',
  },
  DolibarrEventPanel: true,
  LoopPanel: true,
  ExecuteWorkflowPanel: true,
  HttpPanel: true,
  StripeShopifyPanel: true,
  AiPromptPanel: true,
  WebhookTriggerPanel: true,
};

function clickTab(wrapper: ReturnType<typeof mount>, labelPart: string) {
  const btn = wrapper.findAll('button').find((w) =>
    w.text().toLowerCase().includes(labelPart.toLowerCase()),
  );
  expect(btn).toBeTruthy();
  return btn!.trigger('click');
}

function mountInspector(overrides: {
  nodeType?: string;
  config?: Record<string, unknown>;
  schema?: Record<string, unknown> | null;
  workflowId?: number | null;
  notes?: string;
}) {
  return mount(NodeInspectorBody, {
    attachTo: document.body,
    props: {
      nodeType: overrides.nodeType ?? 'dolibarr.object',
      config: overrides.config ?? {
        operation: 'fetch',
        objectType: 'thirdparty',
        id: '1',
        retry: { maxAttempts: 1 },
      },
      schema: overrides.schema ?? null,
      workflowId: overrides.workflowId ?? 99,
      notes: overrides.notes ?? '',
    },
    global: {
      plugins: [i18n],
      stubs: {
        ...panelStubs,
        Wrench: true,
        Code: true,
        Beaker: true,
        ShieldCheck: true,
        MessageSquare: true,
      },
    },
  });
}

describe('NodeInspectorBody', () => {
  it('shows Dolibarr object panel on the form tab for dolibarr.object', async () => {
    const wrapper = mountInspector({});
    await flushPromises();
    expect(document.body.querySelector('.dol-object-panel-stub')).toBeTruthy();
    expect(wrapper.text()).toContain('fetch');
    wrapper.unmount();
  });

  it('shows dolibarr.object create operation on form tab (G-P2-05)', async () => {
    const wrapper = mountInspector({
      config: {
        operation: 'create',
        objectType: 'propal',
        fields: { fk_soc: 1, datep: 1714521600 },
        lines: [{ desc: 'Beta line', subprice: 10, qty: 1 }],
      },
    });
    await flushPromises();
    expect(wrapper.text()).toContain('create');
    expect(wrapper.text()).toContain('propal');
    wrapper.unmount();
  });

  it('shows invalid JSON error on advanced tab', async () => {
    const wrapper = mountInspector({});
    await flushPromises();

    await clickTab(wrapper, 'advanced');
    await flushPromises();

    const textarea = wrapper.find('textarea.k-font-mono');
    expect(textarea.exists()).toBeTruthy();
    await textarea.setValue('{ broken');

    expect(wrapper.text().toLowerCase()).toContain('json');
    wrapper.unmount();
  });

  it('renders the comment tab with the current note and emits update:notes on input', async () => {
    const wrapper = mountInspector({ notes: 'Beta tester ask: explain why retry=3' });
    await flushPromises();

    await clickTab(wrapper, 'comment');
    await flushPromises();

    // Two textareas exist on the comment tab (advanced JSON one is also rendered with v-show).
    // We pick the one bound to the notes prop by matching its current value.
    const noteTextarea = wrapper.findAll('textarea').find((t) => (t.element as HTMLTextAreaElement).value.includes('Beta tester'));
    expect(noteTextarea).toBeTruthy();

    await noteTextarea!.setValue('Updated comment from QA');
    const emitted = wrapper.emitted('update:notes');
    expect(emitted).toBeTruthy();
    expect(emitted![emitted!.length - 1]).toEqual(['Updated comment from QA']);
    wrapper.unmount();
  });

  it('shows test tab shell for manual trigger node', async () => {
    const wrapper = mountInspector({
      nodeType: 'trigger.manual',
      config: { label: 'Manual start' },
    });
    await flushPromises();

    await clickTab(wrapper, 'test');
    await flushPromises();

    expect(wrapper.find('[data-testid="inspector-test-empty-hint"]').exists()).toBe(true);
    expect(wrapper.text().toLowerCase()).toMatch(/simulate/);
    wrapper.unmount();
  });

  it('exposes reliability retry inputs', async () => {
    const wrapper = mountInspector({
      config: { operation: 'fetch', retry: { maxAttempts: 1, backoffMs: 1000 } },
    });
    await flushPromises();

    await clickTab(wrapper, 'reliability');
    await flushPromises();

    expect(wrapper.find('label').exists()).toBeTruthy();
    expect(wrapper.text().toLowerCase()).toContain('backoff');
    wrapper.unmount();
  });
});
