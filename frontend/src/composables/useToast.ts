/**
 * Global toast bus (provide/inject from App.vue).
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { inject, provide, ref, type InjectionKey, type Ref } from 'vue';

export type ToastLevel = 'success' | 'warning' | 'danger' | 'info';

export interface ToastOptions {
  body?: string;
  duration?: number;
  sticky?: boolean;
}

export interface ToastItem {
  id: string;
  level: ToastLevel;
  title: string;
  body?: string;
  duration?: number;
  sticky?: boolean;
}

const MAX_TOASTS = 5;
const DEFAULT_DURATION_MS = 5000;

export interface ToastApi {
  toasts: Ref<ToastItem[]>;
  show(item: Omit<ToastItem, 'id'>): string;
  dismiss(id: string): void;
  success(title: string, options?: ToastOptions): void;
  warning(title: string, options?: ToastOptions): void;
  error(title: string, options?: ToastOptions): void;
  info(title: string, options?: ToastOptions): void;
}

const TOAST_KEY: InjectionKey<ToastApi> = Symbol('knot-toast');

let toastSeq = 0;

export function provideToast(): ToastApi {
  const toasts = ref<ToastItem[]>([]);

  const dismiss = (id: string): void => {
    toasts.value = toasts.value.filter((t) => t.id !== id);
  };

  const show = (item: Omit<ToastItem, 'id'>): string => {
    const id = `toast-${++toastSeq}`;
    const next = [...toasts.value, { ...item, id }];
    toasts.value = next.length > MAX_TOASTS ? next.slice(next.length - MAX_TOASTS) : next;
    return id;
  };

  const levelShow =
    (level: ToastLevel, defaultDuration: number) =>
    (title: string, options?: ToastOptions): void => {
      show({
        level,
        title,
        body: options?.body,
        duration: options?.duration ?? defaultDuration,
        sticky: options?.sticky,
      });
    };

  const api: ToastApi = {
    toasts,
    show,
    dismiss,
    success: levelShow('success', DEFAULT_DURATION_MS),
    warning: levelShow('warning', DEFAULT_DURATION_MS),
    error: levelShow('danger', 6000),
    info: levelShow('info', DEFAULT_DURATION_MS),
  };

  provide(TOAST_KEY, api);
  return api;
}

export function useToast(): ToastApi {
  const api = inject(TOAST_KEY);
  if (!api) {
    throw new Error('useToast() requires provideToast() in App.vue');
  }
  return api;
}
