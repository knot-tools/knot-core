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
