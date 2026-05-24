import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

import ExecutionErrorPanel from '../ExecutionErrorPanel.vue';

const i18n = createI18n({
  legacy: false,
  locale: 'en',
  fallbackLocale: 'en',
  messages: {
    en: {
      executionError: {
        showTechnical: 'Technical',
        documentation: 'Documentation',
        codeLabel: 'Code',
      },
    },
  },
});

describe('ExecutionErrorPanel', () => {
  it('renders Knot payload title and documentation link', () => {
    const wrapper = mount(ExecutionErrorPanel, {
      props: {
        payload: {
          code: 'KNOT_EXECUTION_FAILED',
          user_message: 'Mission demo stop',
          technical_message: 'detail',
          suggestion: 'Fix upstream',
          doc_link: 'https://example.com/errors',
          severity: 'error',
        },
      },
      global: { plugins: [i18n] },
    });
    expect(wrapper.text()).toContain('Mission demo stop');
    expect(wrapper.text()).toContain('KNOT_EXECUTION_FAILED');
    const a = wrapper.find('a');
    expect(a.exists()).toBe(true);
    expect(a.attributes('href')).toBe('https://example.com/errors');
    expect(a.attributes('rel')).toContain('noopener');
  });

  it('does not emit clickable href for unsafe schemes', () => {
    const wrapper = mount(ExecutionErrorPanel, {
      props: {
        payload: {
          code: 'KNOT_X',
          user_message: 'x',
          doc_link: 'javascript:void(0)',
        },
      },
      global: { plugins: [i18n] },
    });
    expect(wrapper.find('a').exists()).toBe(false);
  });

  it('supports legacy string payload', () => {
    const wrapper = mount(ExecutionErrorPanel, {
      props: { payload: 'connection refused to db' },
      global: { plugins: [i18n] },
    });
    expect(wrapper.text().toLowerCase()).toContain('connection refused');
  });

  it('shows license activation CTA for license bucket errors', async () => {
    const openSpy = vi.fn();
    (window as unknown as { KnotCore?: { openLicenseActivationModal?: typeof openSpy } }).KnotCore = {
      openLicenseActivationModal: openSpy,
    };
    const wrapper = mount(ExecutionErrorPanel, {
      props: {
        payload: 'Extension "knot-pro-pack" license check failed',
        extensionId: 'knot-pro-pack',
      },
      global: {
        plugins: [createI18n({
          legacy: false,
          locale: 'en',
          messages: {
            en: {
              executionError: { activateLicense: 'Activate extension license' },
              errors: { execution: { license: { title: 'License', hintActivate: 'Fix license' } } },
            },
          },
        })],
      },
    });
    expect(wrapper.text()).toContain('Activate extension license');
    await wrapper.get('button').trigger('click');
    expect(openSpy).toHaveBeenCalledWith('knot-pro-pack');
  });
});
