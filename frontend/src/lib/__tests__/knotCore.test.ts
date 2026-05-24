/**
 * Vitest coverage of the `window.KnotCore` runtime surface (ADR-20 slice 3).
 */

import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { installKnotCore, type KnotExtensionMeta } from '../knotCore';

interface KnotTestWindow extends Window {
  KnotCore?: ReturnType<typeof installKnotCore>;
  KNOT_EXTENSIONS?: KnotExtensionMeta[];
  KNOT_API_BASE?: string;
  KNOT_CSRF_TOKEN?: string;
  KNOT_BASE_URL?: string;
  KNOT_LOCALE?: string;
  KNOT_VERSION?: string;
}

const MIGRATION_META: KnotExtensionMeta = {
  id: 'knot-migration',
  label: 'Knot Migration',
  version: '0.11.0',
  mode: 'migration',
  bundleJs: 'https://example.com/custom/knotmigration/dist/knot-extension.js',
  bundleCss: 'https://example.com/custom/knotmigration/dist/knot-extension.css',
  globalEntry: 'KnotMigrationExtension',
  status: 'loaded',
  requiresPermission: 'knotmigration.use',
  requiredPermission: 'knotmigration.use',
  userHasPermission: true,
  hasPermission: true,
  onboarding: {
    adminSetupRequired: true,
    adminSetupUrl: 'custom/knotmigration/admin/setup.php',
    ctaIfPermissionMissingForAdmin: null,
  },
  licenseStatus: 'valid',
  licenseExpiresAt: null,
  isAdmin: false,
};

function resetKnotCore(): void {
  const w = window as unknown as KnotTestWindow;
  delete w.KnotCore;
  delete w.KNOT_EXTENSIONS;
  delete w.KNOT_API_BASE;
  delete w.KNOT_CSRF_TOKEN;
  delete w.KNOT_BASE_URL;
  delete w.KNOT_LOCALE;
  delete w.KNOT_VERSION;
  try {
    window.localStorage.clear();
  } catch (_e) {
    // ignore
  }
}

beforeEach(() => {
  resetKnotCore();
  // Mute background HTTP mirroring for the localStorage-focused suite.
  // The dedicated HTTP suite re-mocks fetch with its own assertions.
  vi.stubGlobal('fetch', vi.fn().mockResolvedValue(new Response('{"data":{"state":{}}}', { status: 200 })));
});

afterEach(() => {
  resetKnotCore();
  vi.restoreAllMocks();
  vi.unstubAllGlobals();
});

describe('installKnotCore', () => {
  it('exposes the active extension list and a single extension lookup', () => {
    const w = window as unknown as KnotTestWindow;
    w.KNOT_EXTENSIONS = [MIGRATION_META];
    w.KNOT_VERSION = '2.10.0';
    w.KNOT_LOCALE = 'fr_FR';

    const core = installKnotCore();

    expect(core.extensions).toHaveLength(1);
    expect(core.extension('knot-migration')?.label).toBe('Knot Migration');
    expect(core.extension('unknown')).toBeUndefined();
    expect(core.coreVersion).toBe('2.10.0');
    expect(core.locale).toBe('fr_FR');
  });

  it('returns the same singleton on subsequent installs', () => {
    const first = installKnotCore();
    const second = installKnotCore();
    expect(second).toBe(first);
  });

  it('exposes an empty ui primitives bag extensions can populate later', () => {
    const core = installKnotCore();
    expect(core.ui).toEqual({});

    const fakeHero = { name: 'KHero' };
    core.ui.KHero = fakeHero;

    const w = window as unknown as KnotTestWindow;
    expect(w.KnotCore?.ui.KHero).toBe(fakeHero);
  });

  it('registers an extension and lets mountExtension call its mount handler', () => {
    const w = window as unknown as KnotTestWindow;
    w.KNOT_EXTENSIONS = [MIGRATION_META];
    const core = installKnotCore();
    const mount = vi.fn();

    core.registerExtension('knot-migration', { mount });

    expect(core.hasExtensionRegistered('knot-migration')).toBe(true);
    const el = document.createElement('div');
    expect(core.mountExtension('knot-migration', el)).toBe(true);
    expect(mount).toHaveBeenCalledTimes(1);
    const ctx = mount.mock.calls[0][1];
    expect(ctx.meta.id).toBe('knot-migration');
    expect(typeof ctx.apiFetch).toBe('function');
    expect(typeof ctx.persistedState.set).toBe('function');
  });

  it('mountExtension returns false when no extension matches the id', () => {
    installKnotCore();
    const el = document.createElement('div');
    expect((window as unknown as KnotTestWindow).KnotCore!.mountExtension('missing', el)).toBe(false);
  });

  it('dispatches knot:extension-registered when an extension registers', () => {
    installKnotCore();
    const listener = vi.fn();
    window.addEventListener('knot:extension-registered', listener as EventListener);
    (window as unknown as KnotTestWindow).KnotCore!.registerExtension('knot-migration', { mount: vi.fn() });
    expect(listener).toHaveBeenCalledTimes(1);
    const event = listener.mock.calls[0][0] as CustomEvent<{ id: string }>;
    expect(event.detail.id).toBe('knot-migration');
    window.removeEventListener('knot:extension-registered', listener as EventListener);
  });

  it('openLicenseActivationModal dispatches knot:open-license-activation with the explicit label', () => {
    installKnotCore();
    const listener = vi.fn();
    window.addEventListener('knot:open-license-activation', listener as EventListener);
    (window as unknown as KnotTestWindow).KnotCore!.openLicenseActivationModal('knot-migration', 'Knot Migration');
    expect(listener).toHaveBeenCalledTimes(1);
    const event = listener.mock.calls[0][0] as CustomEvent<{ extensionId: string; extensionLabel: string }>;
    expect(event.detail.extensionId).toBe('knot-migration');
    expect(event.detail.extensionLabel).toBe('Knot Migration');
    window.removeEventListener('knot:open-license-activation', listener as EventListener);
  });

  it('openLicenseActivationModal falls back to the metadata label when none is provided', () => {
    const w = window as unknown as KnotTestWindow;
    w.KNOT_EXTENSIONS = [MIGRATION_META];
    installKnotCore();
    const listener = vi.fn();
    window.addEventListener('knot:open-license-activation', listener as EventListener);
    w.KnotCore!.openLicenseActivationModal('knot-migration');
    const event = listener.mock.calls[0][0] as CustomEvent<{ extensionId: string; extensionLabel: string }>;
    expect(event.detail.extensionLabel).toBe('Knot Migration');
    window.removeEventListener('knot:open-license-activation', listener as EventListener);
  });

  it('openLicenseActivationModal ignores empty extensionId and warns', () => {
    installKnotCore();
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    const listener = vi.fn();
    window.addEventListener('knot:open-license-activation', listener as EventListener);
    (window as unknown as KnotTestWindow).KnotCore!.openLicenseActivationModal('');
    expect(listener).not.toHaveBeenCalled();
    expect(warn).toHaveBeenCalledTimes(1);
    window.removeEventListener('knot:open-license-activation', listener as EventListener);
    warn.mockRestore();
  });

  it('unmountExtension is a no-op when the element was not mounted by this extension', () => {
    const w = window as unknown as KnotTestWindow;
    w.KNOT_EXTENSIONS = [MIGRATION_META];
    const core = installKnotCore();
    const unmount = vi.fn();
    core.registerExtension('knot-migration', { mount: vi.fn(), unmount });

    const el = document.createElement('div');
    core.unmountExtension('knot-migration', el);
    expect(unmount).not.toHaveBeenCalled();
  });

  it('unmountExtension calls the registered unmount when the element matches', () => {
    const w = window as unknown as KnotTestWindow;
    w.KNOT_EXTENSIONS = [MIGRATION_META];
    const core = installKnotCore();
    const mount = vi.fn();
    const unmount = vi.fn();
    core.registerExtension('knot-migration', { mount, unmount });

    const el = document.createElement('div');
    core.mountExtension('knot-migration', el);
    core.unmountExtension('knot-migration', el);
    expect(unmount).toHaveBeenCalledWith(el);
  });

  it('mountExtension swallows handler exceptions and returns false', () => {
    const w = window as unknown as KnotTestWindow;
    w.KNOT_EXTENSIONS = [MIGRATION_META];
    const core = installKnotCore();
    const consoleError = vi.spyOn(console, 'error').mockImplementation(() => undefined);
    core.registerExtension('knot-migration', {
      mount: () => {
        throw new Error('boom');
      },
    });

    const el = document.createElement('div');
    expect(core.mountExtension('knot-migration', el)).toBe(false);
    expect(consoleError).toHaveBeenCalled();
  });
});

describe('KnotCore.persistedState (localStorage backing for slice 3)', () => {
  it('stores and retrieves JSON-encodable values scoped by extension id', () => {
    const core = installKnotCore();
    const store = core.persistedState('knot-migration');

    store.set('lastSnapshotId', 42);
    store.set('flags', { enabled: true });

    expect(store.get<number>('lastSnapshotId')).toBe(42);
    expect(store.get<{ enabled: boolean }>('flags')).toEqual({ enabled: true });
    expect(store.get('missing', 'fallback')).toBe('fallback');
    expect(store.keys().sort()).toEqual(['flags', 'lastSnapshotId']);
  });

  it('isolates keys between extensions', () => {
    const core = installKnotCore();
    core.persistedState('a').set('shared', 'a-value');
    core.persistedState('b').set('shared', 'b-value');
    expect(core.persistedState('a').get<string>('shared')).toBe('a-value');
    expect(core.persistedState('b').get<string>('shared')).toBe('b-value');
  });

  it('returns null when a key was never set and no fallback was provided', () => {
    const core = installKnotCore();
    expect(core.persistedState('knot-migration').get('unknown')).toBeNull();
  });

  it('remove drops a single key without affecting the others', () => {
    const core = installKnotCore();
    const store = core.persistedState('knot-migration');
    store.set('a', 1);
    store.set('b', 2);
    store.remove('a');
    expect(store.get('a')).toBeNull();
    expect(store.get('b')).toBe(2);
  });

  it('returns fallback when stored payload is corrupt JSON', () => {
    const core = installKnotCore();
    window.localStorage.setItem('knot.ext.knot-migration.broken', 'not-json');
    expect(core.persistedState('knot-migration').get<string>('broken', 'safe')).toBe('safe');
  });
});

describe('KnotCore.persistedState (HTTP mirroring for slice 4)', () => {
  it('pull() hydrates the cache from the server, overwriting local entries', async () => {
    const fetchMock = vi.fn().mockResolvedValue(
      new Response(JSON.stringify({ data: { state: { onboardingStep: '4', flags: '{"enabled":true}' } } }), { status: 200 })
    );
    vi.stubGlobal('fetch', fetchMock);

    const core = installKnotCore();
    const store = core.persistedState('knot-migration');
    window.localStorage.setItem('knot.ext.knot-migration.stale', '"will-be-wiped"');

    await store.pull();

    expect(fetchMock).toHaveBeenCalled();
    const url = fetchMock.mock.calls[0][0] as string;
    expect(url).toContain('extension_state.php?extension_id=knot-migration');
    expect(store.get<number>('onboardingStep')).toBe(4);
    expect(store.get<{ enabled: boolean }>('flags')).toEqual({ enabled: true });
    expect(store.get('stale')).toBeNull();
  });

  it('pull() leaves the cache untouched on network error', async () => {
    const fetchMock = vi.fn().mockRejectedValue(new Error('offline'));
    vi.stubGlobal('fetch', fetchMock);

    const core = installKnotCore();
    const store = core.persistedState('knot-migration');
    window.localStorage.setItem('knot.ext.knot-migration.local', '"keep-me"');

    await store.pull();
    expect(store.get<string>('local')).toBe('keep-me');
  });

  it('set() mirrors to a POST request after flush()', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response('{}', { status: 200 }));
    vi.stubGlobal('fetch', fetchMock);
    const w = window as unknown as KnotTestWindow;
    w.KNOT_API_BASE = 'https://example.com/api';

    const core = installKnotCore();
    const store = core.persistedState('knot-migration');
    store.set('lastSnapshotId', 'snap-1');

    await store.flush();

    const postCall = fetchMock.mock.calls.find((c) => (c[1] as RequestInit)?.method === 'POST');
    expect(postCall).toBeDefined();
    const [url, init] = postCall as [string, RequestInit];
    expect(url).toBe('https://example.com/api/extension_state.php');
    expect(init.body).toContain('extension_id=knot-migration');
    expect(init.body).toContain('key=lastSnapshotId');
    expect(init.body).toContain('value=%22snap-1%22');
  });

  it('remove() mirrors to a DELETE request after flush()', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response('{}', { status: 200 }));
    vi.stubGlobal('fetch', fetchMock);
    const w = window as unknown as KnotTestWindow;
    w.KNOT_API_BASE = '/api';

    const core = installKnotCore();
    const store = core.persistedState('knot-migration');
    store.set('foo', 1);
    store.remove('foo');
    await store.flush();

    const deleteCall = fetchMock.mock.calls.find((c) => (c[1] as RequestInit)?.method === 'DELETE');
    expect(deleteCall).toBeDefined();
    expect(deleteCall![0]).toContain('extension_state.php?extension_id=knot-migration&key=foo');
  });

  it('flush() resolves even when a background mirror request fails', async () => {
    const fetchMock = vi.fn().mockRejectedValue(new Error('boom'));
    vi.stubGlobal('fetch', fetchMock);
    const consoleWarn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);

    const core = installKnotCore();
    const store = core.persistedState('knot-migration');
    store.set('foo', 1);

    await expect(store.flush()).resolves.toBeUndefined();
    expect(consoleWarn).toHaveBeenCalled();
  });
});

describe('KnotCore.apiFetch', () => {
  it('joins KNOT_API_BASE with the path and forwards CSRF + Accept headers', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response('{}', { status: 200 }));
    vi.stubGlobal('fetch', fetchMock);

    const w = window as unknown as KnotTestWindow;
    w.KNOT_API_BASE = 'https://example.com/api';
    w.KNOT_CSRF_TOKEN = 'csrf-token';
    const core = installKnotCore();

    await core.apiFetch('snapshot/last.php');

    expect(fetchMock).toHaveBeenCalledTimes(1);
    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe('https://example.com/api/snapshot/last.php');
    const headers = init.headers as Headers;
    expect(headers.get('X-CSRF-Token')).toBe('csrf-token');
    expect(headers.get('Accept')).toBe('application/json');
    expect(init.credentials).toBe('same-origin');
  });

  it('passes through absolute URLs untouched', async () => {
    const fetchMock = vi.fn().mockResolvedValue(new Response('{}'));
    vi.stubGlobal('fetch', fetchMock);
    const core = installKnotCore();
    await core.apiFetch('https://upstream.example/foo');
    expect(fetchMock.mock.calls[0][0]).toBe('https://upstream.example/foo');
  });
});

describe('KnotCore.commandPalette', () => {
  it('register() adds entries and getEntries() returns a defensive copy', () => {
    const core = installKnotCore();
    core.commandPalette.register([
      { id: 'ext:foo', label: 'Foo', category: 'Migration' },
      { id: 'ext:bar', label: 'Bar', category: 'Migration' },
    ]);

    const entries = core.commandPalette.getEntries();
    expect(entries).toHaveLength(2);
    // Defensive copy: mutating the returned array does not leak back.
    entries.push({ id: 'rogue', label: 'rogue' });
    expect(core.commandPalette.getEntries()).toHaveLength(2);
  });

  it('register() dispatches knot:command-palette-updated', () => {
    const core = installKnotCore();
    const listener = vi.fn();
    window.addEventListener('knot:command-palette-updated', listener);
    core.commandPalette.register([{ id: 'ext:foo', label: 'Foo' }]);
    expect(listener).toHaveBeenCalled();
    window.removeEventListener('knot:command-palette-updated', listener);
  });

  it('the disposer returned by register() removes only the entries it added', () => {
    const core = installKnotCore();
    const dispose1 = core.commandPalette.register([{ id: 'a', label: 'A' }]);
    core.commandPalette.register([{ id: 'b', label: 'B' }]);
    expect(core.commandPalette.getEntries()).toHaveLength(2);

    dispose1();
    const remaining = core.commandPalette.getEntries().map((e) => e.id);
    expect(remaining).toEqual(['b']);

    // Disposer is idempotent: a second call must not blow up.
    dispose1();
    expect(core.commandPalette.getEntries()).toHaveLength(1);
  });

  it('register() with same id replaces the previous entry (no duplicates)', () => {
    const core = installKnotCore();
    core.commandPalette.register([{ id: 'ext:foo', label: 'Foo v1' }]);
    core.commandPalette.register([{ id: 'ext:foo', label: 'Foo v2' }]);
    const entries = core.commandPalette.getEntries();
    expect(entries).toHaveLength(1);
    expect(entries[0].label).toBe('Foo v2');
  });

  it('register() rejects entries with empty/missing id and warns', () => {
    const warn = vi.spyOn(console, 'warn').mockImplementation(() => undefined);
    const core = installKnotCore();
    core.commandPalette.register([
      { id: '', label: 'Anonymous' } as never,
      { label: 'No id' } as never,
      { id: 'ok', label: 'OK' },
    ]);
    const entries = core.commandPalette.getEntries();
    expect(entries.map((e) => e.id)).toEqual(['ok']);
    expect(warn).toHaveBeenCalled();
  });

  it('unregisterAll() with a source removes only matching entries', () => {
    const core = installKnotCore();
    core.commandPalette.register([
      { id: 'a', label: 'A', source: 'knot-migration' },
      { id: 'b', label: 'B', source: 'knot-migration' },
      { id: 'c', label: 'C', source: 'pro-pack' },
    ]);
    core.commandPalette.unregisterAll('knot-migration');
    expect(core.commandPalette.getEntries().map((e) => e.id)).toEqual(['c']);
  });

  it('unregisterAll() without arg empties the palette', () => {
    const core = installKnotCore();
    core.commandPalette.register([{ id: 'a', label: 'A' }]);
    core.commandPalette.unregisterAll();
    expect(core.commandPalette.getEntries()).toHaveLength(0);
  });

  it('open() and close() dispatch their CustomEvents', () => {
    const core = installKnotCore();
    const openListener = vi.fn();
    const closeListener = vi.fn();
    window.addEventListener('knot:command-palette-open', openListener);
    window.addEventListener('knot:command-palette-close', closeListener);

    core.commandPalette.open();
    core.commandPalette.close();

    expect(openListener).toHaveBeenCalledTimes(1);
    expect(closeListener).toHaveBeenCalledTimes(1);

    window.removeEventListener('knot:command-palette-open', openListener);
    window.removeEventListener('knot:command-palette-close', closeListener);
  });
});
