// ProblemsPanel — copy-fix action and issue rendering.
// Copyright (C) 2026 Knot — GPL-3.0-or-later
import { describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import ProblemsPanel from '../ProblemsPanel.vue';
import en from '../../i18n/en_US.json';
import fr from '../../i18n/fr_FR.json';
import type { ValidationIssue } from '../../lib/validator';

const i18n = createI18n({ legacy: false, locale: 'en_US', fallbackLocale: 'en_US', messages: { en_US: en, fr_FR: fr } });

const issues: ValidationIssue[] = [
  { code: 'KNOT_DSL_EXPRESSION_JSON_CHAIN', severity: 'warning', message: 'Prefer $nodes', nodeId: 'n1' },
] as ValidationIssue[];

describe('ProblemsPanel', () => {
  it('renders nothing without issues', () => {
    const wrapper = mount(ProblemsPanel, {
      props: { issues: [] },
      global: { plugins: [i18n] },
    });
    expect(wrapper.find('[data-knot-test="knot-problems-panel"]').exists()).toBe(false);
  });

  it('emits copy-fix without toggling the collapse state', async () => {
    const wrapper = mount(ProblemsPanel, {
      props: { issues },
      global: { plugins: [i18n] },
    });

    expect(wrapper.find('ul').exists()).toBe(true);
    await wrapper.find('[data-knot-test="knot-problems-copy-fix"]').trigger('click');

    expect(wrapper.emitted('copy-fix')).toHaveLength(1);
    // The list stays expanded — the action button must not collapse the panel.
    expect(wrapper.find('ul').exists()).toBe(true);
  });

  it('emits jump with the node id when an issue row is clicked', async () => {
    const wrapper = mount(ProblemsPanel, {
      props: { issues },
      global: { plugins: [i18n] },
    });

    await wrapper.find('li').trigger('click');

    expect(wrapper.emitted('jump')).toEqual([['n1']]);
  });
});
