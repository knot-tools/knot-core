/**
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { defineComponent, h } from 'vue';
import { afterEach, describe, expect, it } from 'vitest';
import { mount } from '@vue/test-utils';
import { createI18n } from 'vue-i18n';
import KConfirmDialog from '../KConfirmDialog.vue';
import { provideConfirm, type ConfirmApi } from '../../../composables/useConfirm';

function mountDialog(): { wrapper: ReturnType<typeof mount>; confirmApi: ConfirmApi } {
  const i18n = createI18n({
    legacy: false,
    locale: 'en',
    messages: { en: { actions: { cancel: 'Cancel', confirm: 'Confirm' } } },
  });
  let confirmApi!: ConfirmApi;
  const Host = defineComponent({
    setup() {
      confirmApi = provideConfirm();
      return () => h(KConfirmDialog);
    },
  });
  const wrapper = mount(Host, {
    global: { plugins: [i18n] },
    attachTo: document.body,
  });
  return { wrapper, confirmApi };
}

describe('KConfirmDialog', () => {
  afterEach(() => {
    document.body.innerHTML = '';
  });

  it('renders when confirm is open', async () => {
    const { wrapper, confirmApi } = mountDialog();
    void confirmApi.confirm({ title: 'Purge logs', message: 'Older than 30 days?' });
    await wrapper.vm.$nextTick();
    const dialog = document.body.querySelector('[data-knot-test="knot-confirm-dialog"]');
    expect(dialog?.textContent).toContain('Purge logs');
    expect(dialog?.textContent).toContain('Older than 30 days?');
    expect(dialog?.querySelector('[data-knot-test="knot-confirm-details"]')).toBeNull();
    wrapper.unmount();
  });

  it('renders scrollable markdown details when provided', async () => {
    const { wrapper, confirmApi } = mountDialog();
    void confirmApi.confirm({
      title: 'Apply this update?',
      message: 'Will update to 2.1.0',
      details: '## [2.1.0]\n\n- **Bold change**',
      detailsLabel: "What's new in this version",
    });
    await wrapper.vm.$nextTick();
    const details = document.body.querySelector('[data-knot-test="knot-confirm-details"]');
    expect(details?.textContent).toContain("What's new in this version");
    expect(details?.innerHTML).toContain('<strong>Bold change</strong>');
    wrapper.unmount();
  });

  it('emits answer via confirm button', async () => {
    const { wrapper, confirmApi } = mountDialog();
    const pending = confirmApi.confirm({ title: 'Rollback', message: 'Sure?' });
    await wrapper.vm.$nextTick();
    const dialog = document.body.querySelector('[data-knot-test="knot-confirm-dialog"]');
    const buttons = dialog?.querySelectorAll('button') ?? [];
    buttons[buttons.length - 1]?.click();
    await expect(pending).resolves.toBe(true);
    wrapper.unmount();
  });
});
