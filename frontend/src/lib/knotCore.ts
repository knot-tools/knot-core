/**
 * `window.KnotCore` runtime surface exposed to Knot extensions
 * (ADR-20 slice 3).
 *
 * Knot Core boots first, installs this singleton on `window`, and
 * deferred extension bundles (Knot Migration, future add-ons) call
 * `window.KnotCore.registerExtension(...)` once they finish parsing.
 *
 * Responsibilities:
 *   - Provide a stable JS API for extensions independent of Vue
 *     internals (extensions ship their own Vue tree so they should
 *     not import Core's Vue/Pinia/I18n instance).
 *   - Hand back lightweight, typed metadata about the currently
 *     active extension (id, label, version, locale, permissions,
 *     license status — all populated from `window.KNOT_EXTENSIONS`
 *     injected by `workflows/preview.php`).
 *   - Provide a `persistedState(extensionId)` store that combines a
 *     synchronous localStorage cache with a best-effort HTTP mirror
 *     to `api/extension_state.php` (slice 4 — table
 *     `llx_knot_extension_state`). Extensions call `pull()` once at
 *     startup to hydrate from the server, then use the sync get/set
 *     pair; writes are flushed in the background and ordered FIFO.
 *   - Provide an `apiFetch(path, init?)` helper that joins
 *     `window.KNOT_API_BASE` with the path and adds the CSRF
 *     header.
 *
 * Design notes:
 *   - All methods are synchronous where possible to keep the
 *     extension code straightforward; the persisted-state HTTP
 *     swap (slice 4) will use the same shape with optional async
 *     methods added without removing the sync ones.
 *   - Extensions must opt-in to register: Core never auto-mounts
 *     a bundle.
 */

// ADR-20 §4.2 canonical contract. The legacy `requiredPermission`
// and `hasPermission` keys are still accepted at read-time so a Core
// build can render extension bundles built against the older shape;
// new bundles should consume `requiresPermission` and
// `userHasPermission` (mirrored by the PHP bootstrap).
export interface KnotExtensionMeta {
  id: string;
  label: string;
  version: string;
  mode: string;
  bundleJs: string;
  bundleCss: string | null;
  globalEntry: string;
  status: 'loaded' | 'incompatible' | 'license_invalid' | 'disabled';
  requiresPermission: string | null;
  /** @deprecated kept for backward compat — read `requiresPermission`. */
  requiredPermission: string | null;
  userHasPermission: boolean;
  /** @deprecated kept for backward compat — read `userHasPermission`. */
  hasPermission: boolean;
  onboarding: {
    adminSetupRequired: boolean;
    adminSetupUrl: string | null;
    ctaIfPermissionMissingForAdmin: string | null;
  };
  licenseStatus: 'valid' | 'invalid' | 'not_required' | 'unknown' | 'grace' | 'missing';
  licenseExpiresAt: string | null;
  isAdmin: boolean;
}

export interface KnotExtensionContext {
  meta: KnotExtensionMeta;
  apiBase: string;
  csrfToken: string;
  baseUrl: string;
  locale: string;
  coreVersion: string;
  persistedState: KnotPersistedStateStore;
  apiFetch: (path: string, init?: RequestInit) => Promise<Response>;
}

export interface KnotExtensionRegistration {
  /** Synchronous mount called by Core when the user lands on the
   *  extension's `?mode=...` page. The DOM element is empty and
   *  fully owned by the extension once handed over. */
  mount: (el: HTMLElement, ctx: KnotExtensionContext) => void;
  /** Optional teardown called when Core navigates away from the
   *  extension. Most reload-based extensions can leave this out. */
  unmount?: (el: HTMLElement) => void;
}

export interface KnotPersistedStateStore {
  /** Synchronous read from the local cache. */
  get<T = unknown>(key: string, fallback?: T): T | null;
  /** Synchronous write to the local cache. The change is mirrored
   *  to the server in the background; the promise returned by
   *  `flush()` resolves once the queue has drained. */
  set<T = unknown>(key: string, value: T): void;
  /** Synchronous delete from the local cache + best-effort DELETE on
   *  the server (queued, see `flush`). */
  remove(key: string): void;
  /** List of cached keys. */
  keys(): string[];
  /**
   * Hydrate the cache from the server. Extensions call this once at
   * startup if they need state that may have been written from another
   * browser. Resolves when the cache reflects the server state, or
   * silently swallows network errors and keeps the existing cache.
   */
  pull(): Promise<void>;
  /**
   * Resolve once every queued write has been persisted server-side
   * (or definitively failed). Lets onboarding flows assert "the user
   * really pressed Save" before navigating away.
   */
  flush(): Promise<void>;
}

/**
 * Payload of the `knot:extension-license-activated` window CustomEvent
 * fired by Core after a successful run through `LicenseActivationModal`.
 * Extensions interested in transitioning their UI from "license missing"
 * to "license valid" without a page reload subscribe to this event
 * (filtering on `detail.extensionId === own id`).
 */
export interface KnotLicenseActivatedDetail {
  extensionId: string;
  fingerprint: string;
  verdict?: unknown;
}

/**
 * Set of Vue component objects Core re-exports to extensions through
 * `window.KnotCore.ui` (ADR-20 §4.3 — UI primitives sharing).
 *
 * Extensions register them on their own Vue app instance to skip
 * bundling duplicates of Core's design system. The shape is typed
 * loosely as `unknown` to avoid leaking Vue's `DefineComponent`
 * generic across the extension boundary; the consumer `coreUi.ts`
 * helper (knot-migration repo) re-casts them with its own narrow
 * types so the IDE keeps prop intellisense.
 *
 * Core populates the actual components in `main.ts` after
 * `installKnotCore()` runs. Until then, extensions see an empty
 * object and must defer their `app.component(...)` registration to
 * the next microtask (Vue's `onBeforeMount` hook is a safe point).
 */
export interface KnotCoreUi {
  KHero?: unknown;
  KGlassCard?: unknown;
  KEmptyState?: unknown;
  KSkeleton?: unknown;
  KAnimatedCounter?: unknown;
}

/**
 * One entry that an extension contributes to Core's global command
 * palette (Cmd+K). Designed to be cheap to register / dispose so
 * extensions can keep their entries in sync with their own routing
 * state (e.g. "Open current workflow's run history" only when a
 * workflow is selected).
 *
 * Either `href` or `action` must be provided. When both are set
 * `action` wins (browser will not navigate). `id` MUST be unique
 * across the whole palette — extensions are expected to namespace
 * with their extension id (e.g. `knot-migration:open-discovery`).
 *
 * `source` is auto-set by Core when the registration goes through
 * `KnotCore.commandPalette.register()` to the calling extension's
 * id (when available via the registration helper). It lets the
 * palette display the owner badge and lets `unregisterAll(source)`
 * disposers stay simple.
 */
export interface KnotCommandPaletteEntry {
  id: string;
  label: string;
  description?: string;
  hint?: string;
  category?: string;
  href?: string;
  action?: () => void;
  keywords?: string[];
  source?: string;
}

/**
 * Read/write surface for the global command palette. Extensions
 * register their own actions via `register()`; the returned closure
 * disposes them in O(N) when called. `getEntries()` returns a
 * defensive copy so the consumer cannot mutate the internal store.
 *
 * Core's `CommandPalette.vue` listens for the
 * `knot:command-palette-updated` window CustomEvent to re-render
 * its result list on registration changes.
 */
export interface KnotCommandPalette {
  /**
   * Register one or more entries. Returns a dispose function that
   * removes only the entries this call added (idempotent — calling
   * the disposer twice is safe).
   */
  register(entries: KnotCommandPaletteEntry[]): () => void;
  /** Get a defensive copy of the registered entries. */
  getEntries(): KnotCommandPaletteEntry[];
  /**
   * Remove every entry whose `source` matches the argument. When
   * `source` is omitted, the palette is emptied wholesale (mainly
   * for tests).
   */
  unregisterAll(source?: string): void;
  /** Open the palette programmatically (Cmd+K equivalent). */
  open(): void;
  /** Close the palette. */
  close(): void;
}

export interface KnotCoreSurface {
  /** Knot Core semver, sourced from `window.KNOT_VERSION`. */
  coreVersion: string;
  /** Base URL of `workflows/preview.php`, used by extensions to
   *  link back to Core views. */
  baseUrl: string;
  /** Locale code (`fr_FR`, `en_US`, …) sourced from Dolibarr. */
  locale: string;
  /**
   * Shared Vue component primitives extensions can register on
   * their own app to avoid bundling duplicates of Core's design
   * system. Populated by Core's `main.ts` after the surface is
   * installed (see {@link KnotCoreUi}).
   */
  ui: KnotCoreUi;
  /** All UI-bearing extensions currently active. */
  extensions: KnotExtensionMeta[];
  /** Find one extension by id (returns undefined when missing). */
  extension(id: string): KnotExtensionMeta | undefined;
  /** Persisted-state store (slice 3: localStorage; slice 4: HTTP). */
  persistedState(extensionId: string): KnotPersistedStateStore;
  /** HTTP helper that joins KNOT_API_BASE and adds the CSRF header. */
  apiFetch(path: string, init?: RequestInit): Promise<Response>;
  /** Called by extension bundles once their global is loaded. */
  registerExtension(id: string, registration: KnotExtensionRegistration): void;
  /** Mount the extension into a DOM element (used by `KnotExtensionMount`). */
  mountExtension(id: string, el: HTMLElement): boolean;
  /** Tear down the extension currently mounted in `el`, if any. */
  unmountExtension(id: string, el: HTMLElement): void;
  /** Whether an extension already finished registering its bundle. */
  hasExtensionRegistered(id: string): boolean;
  /**
   * Global command palette (Cmd+K) registry. Extensions register
   * entries via `commandPalette.register(...)`; Core's
   * `CommandPalette.vue` reads them at render time. See
   * {@link KnotCommandPalette}.
   */
  commandPalette: KnotCommandPalette;
  /**
   * Open Core's `LicenseActivationModal` for a given extension id.
   *
   * Extensions call this when their own UI gates on a missing/invalid
   * license (e.g. Knot Migration's "Activate Knot Migration" CTA in
   * `FirstVisitGate` Layout C). Core's `App.vue` listens for the
   * dispatched `knot:open-license-activation` event and renders the
   * modal — the extension does NOT need to ship its own activation
   * form, which would duplicate the CSRF dance and the signed verdict
   * cache write that lives in `api/license_activate.php`.
   *
   * On successful activation Core dispatches
   * `knot:extension-license-activated` on `window` with payload
   * {@see KnotLicenseActivatedDetail}; extensions subscribe to it to
   * transition their UI without a page reload.
   */
  openLicenseActivationModal(extensionId: string, extensionLabel?: string): void;
}

interface KnotWindow extends Window {
  KnotCore?: KnotCoreSurface;
  KNOT_EXTENSIONS?: KnotExtensionMeta[];
  KNOT_API_BASE?: string;
  KNOT_CSRF_TOKEN?: string;
  KNOT_BASE_URL?: string;
  KNOT_LOCALE?: string;
  KNOT_VERSION?: string;
  KNOT_USER_ADMIN?: boolean;
}

/**
 * Build and install `window.KnotCore`. Idempotent: subsequent calls
 * return the same singleton so duplicated `<script>` includes (CDN
 * caches, browser-back, etc.) cannot create two diverging stores.
 */
export function installKnotCore(target: Window = window): KnotCoreSurface {
  const w = target as KnotWindow;
  if (w.KnotCore) {
    return w.KnotCore;
  }

  const extensions = Array.isArray(w.KNOT_EXTENSIONS) ? w.KNOT_EXTENSIONS : [];
  const apiBase = typeof w.KNOT_API_BASE === 'string' ? w.KNOT_API_BASE : '';
  const csrfToken = typeof w.KNOT_CSRF_TOKEN === 'string' ? w.KNOT_CSRF_TOKEN : '';
  const baseUrl = typeof w.KNOT_BASE_URL === 'string' ? w.KNOT_BASE_URL : '';
  const locale = typeof w.KNOT_LOCALE === 'string' ? w.KNOT_LOCALE : 'fr_FR';
  const coreVersion = typeof w.KNOT_VERSION === 'string' ? w.KNOT_VERSION : '0.0.0';

  const registrations = new Map<string, KnotExtensionRegistration>();
  const mountedEls = new WeakMap<HTMLElement, string>();

  const persistedStates = new Map<string, KnotPersistedStateStore>();
  const makePersistedState = (extensionId: string): KnotPersistedStateStore => {
    const cached = persistedStates.get(extensionId);
    if (cached) {
      return cached;
    }
    const store = makeHybridStore(extensionId, () => apiFetch);
    persistedStates.set(extensionId, store);
    return store;
  };

  // Command palette registry (Cmd+K). Keys are the entry `id`s so
  // a registration with the same id replaces the previous version
  // — this makes it easy for extensions to update an entry's label
  // (e.g. translated string switched) without leaking duplicates.
  const commandPaletteEntries = new Map<string, KnotCommandPaletteEntry>();
  const dispatchCommandPaletteUpdate = (): void => {
    target.dispatchEvent(new CustomEvent('knot:command-palette-updated'));
  };
  const commandPalette: KnotCommandPalette = {
    register(entries: KnotCommandPaletteEntry[]): () => void {
      const addedIds: string[] = [];
      for (const entry of entries) {
        if (typeof entry?.id !== 'string' || entry.id === '') {
          // eslint-disable-next-line no-console
          console.warn('[KnotCore.commandPalette] entry must have a non-empty id', entry);
          continue;
        }
        commandPaletteEntries.set(entry.id, entry);
        addedIds.push(entry.id);
      }
      if (addedIds.length > 0) {
        dispatchCommandPaletteUpdate();
      }
      return () => {
        let changed = false;
        for (const id of addedIds) {
          if (commandPaletteEntries.delete(id)) {
            changed = true;
          }
        }
        if (changed) {
          dispatchCommandPaletteUpdate();
        }
      };
    },
    getEntries(): KnotCommandPaletteEntry[] {
      return Array.from(commandPaletteEntries.values()).map((entry) => ({ ...entry }));
    },
    unregisterAll(source?: string): void {
      let changed = false;
      if (source === undefined) {
        if (commandPaletteEntries.size > 0) {
          commandPaletteEntries.clear();
          changed = true;
        }
      } else {
        for (const [id, entry] of commandPaletteEntries) {
          if (entry.source === source) {
            commandPaletteEntries.delete(id);
            changed = true;
          }
        }
      }
      if (changed) {
        dispatchCommandPaletteUpdate();
      }
    },
    open(): void {
      target.dispatchEvent(new CustomEvent('knot:command-palette-open'));
    },
    close(): void {
      target.dispatchEvent(new CustomEvent('knot:command-palette-close'));
    },
  };

  const apiFetch = (path: string, init?: RequestInit): Promise<Response> => {
    const url = path.startsWith('http') ? path : `${apiBase.replace(/\/$/, '')}/${path.replace(/^\//, '')}`;
    const headers = new Headers(init?.headers ?? {});
    if (csrfToken !== '' && !headers.has('X-CSRF-Token')) {
      headers.set('X-CSRF-Token', csrfToken);
    }
    if (!headers.has('Accept')) {
      headers.set('Accept', 'application/json');
    }
    return fetch(url, { credentials: 'same-origin', ...init, headers });
  };

  const surface: KnotCoreSurface = {
    coreVersion,
    baseUrl,
    locale,
    ui: {},
    extensions: [...extensions],
    extension(id: string) {
      return extensions.find((ext) => ext.id === id);
    },
    persistedState(extensionId: string) {
      return makePersistedState(extensionId);
    },
    apiFetch,
    commandPalette,
    registerExtension(id: string, registration: KnotExtensionRegistration) {
      if (typeof registration.mount !== 'function') {
        // eslint-disable-next-line no-console
        console.warn(`[KnotCore] registerExtension("${id}"): mount must be a function`);
        return;
      }
      registrations.set(id, registration);
      // Notify any in-flight mounter waiting for registration.
      target.dispatchEvent(new CustomEvent('knot:extension-registered', { detail: { id } }));
    },
    hasExtensionRegistered(id: string) {
      return registrations.has(id);
    },
    mountExtension(id: string, el: HTMLElement): boolean {
      const reg = registrations.get(id);
      if (!reg) {
        return false;
      }
      const meta = extensions.find((ext) => ext.id === id);
      if (!meta) {
        return false;
      }
      const ctx: KnotExtensionContext = {
        meta,
        apiBase,
        csrfToken,
        baseUrl,
        locale,
        coreVersion,
        persistedState: makePersistedState(id),
        apiFetch,
      };
      try {
        reg.mount(el, ctx);
        mountedEls.set(el, id);
        return true;
      } catch (err) {
        // eslint-disable-next-line no-console
        console.error(`[KnotCore] mount("${id}") failed:`, err);
        return false;
      }
    },
    unmountExtension(id: string, el: HTMLElement) {
      const reg = registrations.get(id);
      if (!reg || mountedEls.get(el) !== id) {
        return;
      }
      try {
        reg.unmount?.(el);
      } catch (err) {
        // eslint-disable-next-line no-console
        console.error(`[KnotCore] unmount("${id}") failed:`, err);
      }
      mountedEls.delete(el);
    },
    openLicenseActivationModal(extensionId: string, extensionLabel?: string) {
      if (extensionId === '') {
        // eslint-disable-next-line no-console
        console.warn('[KnotCore] openLicenseActivationModal called without extensionId');
        return;
      }
      // Resolve the label from the metadata payload when the caller
      // did not provide one — extensions usually do not know their
      // own translated label, but Core does (via KNOT_EXTENSIONS).
      const resolvedLabel = extensionLabel
        ?? extensions.find((ext) => ext.id === extensionId)?.label
        ?? extensionId;
      target.dispatchEvent(
        new CustomEvent('knot:open-license-activation', {
          detail: { extensionId, extensionLabel: resolvedLabel },
        }),
      );
    },
  };

  w.KnotCore = surface;
  return surface;
}

type ApiFetchProvider = () => (path: string, init?: RequestInit) => Promise<Response>;

/**
 * Hybrid store: synchronous localStorage cache + asynchronous mirror to
 * `api/extension_state.php`. Designed so extensions can keep using simple
 * `get`/`set` calls while the cache stays consistent across browsers.
 *
 * - Reads always hit localStorage (sync).
 * - Writes update localStorage immediately and enqueue a POST.
 * - `pull()` performs a single GET and overwrites the cache.
 * - `flush()` resolves when the write queue has drained.
 *
 * Network failures are tolerated: localStorage is the local source of
 * truth, and `pull()` is a no-op on offline boot.
 */
function makeHybridStore(extensionId: string, apiFetchProvider: ApiFetchProvider): KnotPersistedStateStore {
  const prefix = `knot.ext.${extensionId}.`;
  const safeRead = (key: string): string | null => {
    try {
      return window.localStorage.getItem(prefix + key);
    } catch (_e) {
      return null;
    }
  };
  const safeWrite = (key: string, raw: string): void => {
    try {
      window.localStorage.setItem(prefix + key, raw);
    } catch (_e) {
      // storage full / disabled: silently swallow.
    }
  };
  const safeDelete = (key: string): void => {
    try {
      window.localStorage.removeItem(prefix + key);
    } catch (_e) {
      // ignore
    }
  };

  let pendingChain: Promise<unknown> = Promise.resolve();
  const enqueue = (op: () => Promise<unknown>): void => {
    pendingChain = pendingChain
      .catch(() => undefined)
      .then(op)
      .catch((err) => {
        // eslint-disable-next-line no-console
        console.warn(`[KnotCore.persistedState:${extensionId}] background sync failed:`, err);
      });
  };

  return {
    get<T = unknown>(key: string, fallback?: T): T | null {
      const raw = safeRead(key);
      if (raw === null) {
        return fallback ?? null;
      }
      try {
        return JSON.parse(raw) as T;
      } catch (_e) {
        return fallback ?? null;
      }
    },
    set<T = unknown>(key: string, value: T): void {
      const raw = JSON.stringify(value);
      safeWrite(key, raw);
      enqueue(() => apiFetchProvider()('extension_state.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ extension_id: extensionId, key, value: raw }).toString(),
      }));
    },
    remove(key: string): void {
      safeDelete(key);
      enqueue(() => apiFetchProvider()(
        `extension_state.php?extension_id=${encodeURIComponent(extensionId)}&key=${encodeURIComponent(key)}`,
        { method: 'DELETE' }
      ));
    },
    keys(): string[] {
      const out: string[] = [];
      try {
        for (let i = 0; i < window.localStorage.length; i++) {
          const k = window.localStorage.key(i);
          if (k !== null && k.startsWith(prefix)) {
            out.push(k.slice(prefix.length));
          }
        }
      } catch (_e) {
        // ignore
      }
      return out;
    },
    async pull(): Promise<void> {
      try {
        const res = await apiFetchProvider()(
          `extension_state.php?extension_id=${encodeURIComponent(extensionId)}`
        );
        if (!res.ok) {
          return;
        }
        const payload = (await res.json()) as { data?: { state?: Record<string, string> } };
        const remoteState = payload?.data?.state ?? {};
        // Replace cache wholesale with the server state (server wins on
        // pull, by design — extensions that need a merge should pull
        // before mutating).
        for (const k of this.keys()) {
          safeDelete(k);
        }
        for (const [k, v] of Object.entries(remoteState)) {
          safeWrite(k, typeof v === 'string' ? v : JSON.stringify(v));
        }
      } catch (_e) {
        // network down / parse error → keep the local cache untouched.
      }
    },
    async flush(): Promise<void> {
      await pendingChain.catch(() => undefined);
    },
  };
}
