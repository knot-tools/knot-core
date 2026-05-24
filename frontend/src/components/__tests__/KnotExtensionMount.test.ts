/**
 * Vitest coverage of <KnotExtensionMount /> (ADR-20 slice 3).
 *
 * The component must:
 *   - Render a placeholder while the extension bundle is still
 *     loading.
 *   - Show an inline error when window.KnotCore is missing OR the
 *     extension id is not in the active list.
 *   - Call KnotCore.mountExtension(id, el) once the extension's
 *     bundle has registered.
 *   - Retry on the `knot:extension-registered` event when the bundle
 *     finishes loading after the component mounted.
 */

import { enableAutoUnmount, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { installKnotCore, type KnotExtensionMeta } from '../../lib/knotCore';
import KnotExtensionMount from '../KnotExtensionMount.vue';

// Ensures every wrapper this file mounts is torn down between
// specs — otherwise the global `knot:extension-registered` listener
// registered while the bundle was still loading leaks into the next
// test and double-counts mount() calls.
enableAutoUnmount(afterEach);

interface KnotTestWindow extends Window {
  KnotCore?: ReturnType<typeof installKnotCore>;
  KNOT_EXTENSIONS?: KnotExtensionMeta[];
}

const META: KnotExtensionMeta = {
  id: 'knot-migration',
  label: 'Knot Migration',
  version: '0.11.0',
  mode: 'migration',
  bundleJs: 'about:blank',
  bundleCss: null,
  globalEntry: 'KnotMigrationExtension',
  status: 'loaded',
  requiresPermission: null,
  requiredPermission: null,
  userHasPermission: true,
  hasPermission: true,
  onboarding: {
    adminSetupRequired: false,
    adminSetupUrl: null,
    ctaIfPermissionMissingForAdmin: null,
  },
  licenseStatus: 'valid',
  licenseExpiresAt: null,
  isAdmin: false,
};

function resetWindow(): void {
  const w = window as unknown as KnotTestWindow;
  delete w.KnotCore;
  delete w.KNOT_EXTENSIONS;
}

beforeEach(() => {
  resetWindow();
});

afterEach(() => {
  resetWindow();
  vi.restoreAllMocks();
});

describe('KnotExtensionMount', () => {
  it('surfaces an error banner when window.KnotCore is missing', async () => {
    const wrapper = mount(KnotExtensionMount, { props: { extensionId: 'knot-migration' } });
    await wrapper.vm.$nextTick();
    expect(wrapper.attributes('data-knot-extension-id')).toBe('knot-migration');
    expect(wrapper.find('[data-knot-extension-error="missing-core"]').exists()).toBe(true);
  });

  it('surfaces an error banner when the extension is not in the active list', async () => {
    installKnotCore();
    const wrapper = mount(KnotExtensionMount, { props: { extensionId: 'knot-missing' } });
    await wrapper.vm.$nextTick();
    expect(wrapper.find('[data-knot-extension-error="missing-extension"]').exists()).toBe(true);
  });

  it('shows the loading placeholder when the extension is active but not yet registered', () => {
    (window as unknown as KnotTestWindow).KNOT_EXTENSIONS = [META];
    installKnotCore();
    const wrapper = mount(KnotExtensionMount, { props: { extensionId: 'knot-migration' } });
    expect(wrapper.find('[data-knot-extension-placeholder="loading"]').exists()).toBe(true);
  });

  it('calls KnotCore.mountExtension when the extension is already registered at mount time', async () => {
    (window as unknown as KnotTestWindow).KNOT_EXTENSIONS = [META];
    const core = installKnotCore();
    const mountFn = vi.fn();
    core.registerExtension('knot-migration', { mount: mountFn });

    const wrapper = mount(KnotExtensionMount, { props: { extensionId: 'knot-migration' } });
    await wrapper.vm.$nextTick();

    expect(mountFn).toHaveBeenCalledTimes(1);
    expect(wrapper.find('[data-knot-extension-state="mounted"]').exists()).toBe(true);
  });

  it('retries via knot:extension-registered when the bundle finishes loading late', async () => {
    (window as unknown as KnotTestWindow).KNOT_EXTENSIONS = [META];
    const core = installKnotCore();

    const wrapper = mount(KnotExtensionMount, { props: { extensionId: 'knot-migration' } });
    expect(wrapper.find('[data-knot-extension-placeholder="loading"]').exists()).toBe(true);

    const mountFn = vi.fn();
    core.registerExtension('knot-migration', { mount: mountFn });

    await wrapper.vm.$nextTick();
    expect(mountFn).toHaveBeenCalledTimes(1);
    expect(wrapper.find('[data-knot-extension-state="mounted"]').exists()).toBe(true);
  });

  it('calls KnotCore.unmountExtension on teardown when the extension was mounted', async () => {
    (window as unknown as KnotTestWindow).KNOT_EXTENSIONS = [META];
    const core = installKnotCore();
    const unmount = vi.fn();
    core.registerExtension('knot-migration', { mount: vi.fn(), unmount });

    const wrapper = mount(KnotExtensionMount, { props: { extensionId: 'knot-migration' } });
    await wrapper.vm.$nextTick();
    wrapper.unmount();

    expect(unmount).toHaveBeenCalledTimes(1);
  });

  it('surfaces a failed state when mountExtension returns false', async () => {
    (window as unknown as KnotTestWindow).KNOT_EXTENSIONS = [META];
    const core = installKnotCore();
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    core.registerExtension('knot-migration', {
      mount: () => {
        throw new Error('boom');
      },
    });

    const wrapper = mount(KnotExtensionMount, { props: { extensionId: 'knot-migration' } });
    await wrapper.vm.$nextTick();

    expect(wrapper.find('[data-knot-extension-error="failed"]').exists()).toBe(true);
    expect(consoleError).toHaveBeenCalled();
  });
});
