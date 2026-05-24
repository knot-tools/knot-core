import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import { describe, expect, it } from 'vitest';
import ExecutionWaterfall from '../ExecutionWaterfall.vue';
import type { ExecutionLog } from '../../../lib/api';
import en from '@/i18n/en_US.json';

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  fallbackLocale: 'en',
  messages: { en },
});

function makeLog(overrides: Partial<ExecutionLog>): ExecutionLog {
  return {
    id: 1,
    nodeId: 'n1',
    nodeType: 'demo',
    status: 'success',
    input: {},
    output: {},
    durationMs: 100,
    errorMessage: null,
    executedAt: '',
    sequenceOrder: 1,
    ...overrides,
  };
}

describe('ExecutionWaterfall', () => {
  it('renders an empty hint when no logs', () => {
    const wrapper = mount(ExecutionWaterfall, {
      props: { logs: [] },
      global: { plugins: [i18n] },
    });
    expect(wrapper.text()).toContain(en.executionWaterfall.empty);
  });

  it('shows truncation banner when truncated=true', () => {
    const logs = [makeLog({ id: 1 })];
    const wrapper = mount(ExecutionWaterfall, {
      props: { logs, truncated: true },
      global: { plugins: [i18n] },
    });
    expect(wrapper.text()).toContain('500');
    expect(wrapper.text()).toMatch(/json/i);
  });

  it('renders one row per log with formatted duration', () => {
    const logs = [
      makeLog({ id: 1, nodeId: 'a', durationMs: 250 }),
      makeLog({ id: 2, nodeId: 'b', durationMs: 1500, status: 'error' }),
    ];
    const wrapper = mount(ExecutionWaterfall, {
      props: { logs },
      global: { plugins: [i18n] },
    });
    const items = wrapper.findAll('li');
    expect(items).toHaveLength(2);
    expect(items[0].text()).toContain('250 ms');
    expect(items[1].text()).toContain('1.50 s');
  });

  it('handles missing duration gracefully', () => {
    const logs = [makeLog({ id: 1, durationMs: null })];
    const wrapper = mount(ExecutionWaterfall, {
      props: { logs },
      global: { plugins: [i18n] },
    });
    expect(wrapper.text()).toContain(en.editor.commonEmDash);
  });
});
