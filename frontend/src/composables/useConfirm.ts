/**
 * Async confirm dialog (provide/inject from App.vue).
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { inject, provide, ref, type InjectionKey, type Ref } from 'vue';

export interface ConfirmOptions {
  title: string;
  message?: string;
  confirmLabel?: string;
  cancelLabel?: string;
  danger?: boolean;
}

interface ConfirmState {
  open: boolean;
  options: ConfirmOptions;
  resolve: ((value: boolean) => void) | null;
}

export interface ConfirmApi {
  state: Ref<ConfirmState>;
  confirm(options: ConfirmOptions): Promise<boolean>;
  answer(value: boolean): void;
}

const CONFIRM_KEY: InjectionKey<ConfirmApi> = Symbol('knot-confirm');

const defaultState = (): ConfirmState => ({
  open: false,
  options: { title: '' },
  resolve: null,
});

export function provideConfirm(): ConfirmApi {
  const state = ref<ConfirmState>(defaultState());

  const answer = (value: boolean): void => {
    const resolve = state.value.resolve;
    state.value = defaultState();
    resolve?.(value);
  };

  const confirm = (options: ConfirmOptions): Promise<boolean> => {
    return new Promise<boolean>((resolve) => {
      state.value = { open: true, options, resolve };
    });
  };

  const api: ConfirmApi = { state, confirm, answer };
  provide(CONFIRM_KEY, api);
  return api;
}

export function useConfirm(): ConfirmApi {
  const api = inject(CONFIRM_KEY);
  if (!api) {
    throw new Error('useConfirm() requires provideConfirm() in App.vue');
  }
  return api;
}
