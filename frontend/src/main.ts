/**
 * Knot frontend entry — boots the Vue 3 app on `<div id="knot-app">`.
 * Copyright (C) 2026 Knot
 * Licensed under GPL-3.0-or-later
 */

import * as VueRuntime from 'vue';
import * as PiniaRuntime from 'pinia';
import * as VueI18nRuntime from 'vue-i18n';
import * as VueRouterRuntime from 'vue-router';
import { createApp } from 'vue';
import { createPinia } from 'pinia';
import App from './App.vue';
import { i18n } from './i18n';
import { installKnotCore } from './lib/knotCore';
import KHero from './components/ui/KHero.vue';
import KGlassCard from './components/ui/KGlassCard.vue';
import KEmptyState from './components/ui/KEmptyState.vue';
import KSkeleton from './components/ui/KSkeleton.vue';
import KAnimatedCounter from './components/ui/KAnimatedCounter.vue';
import './styles/index.css';

// ADR-20 Phase 6g §L2 — Shared Vue runtime contract.
//
// Knot Core's bundle (`knot-app.js`) is loaded by `preview.php` BEFORE
// any extension bundle (browser-`defer` keeps document order). We
// publish the Vue ecosystem libraries on a small set of globals so
// extensions can mark them `external` in their Vite config and
// import normally (`import { createApp } from 'vue'`) at source
// level while the runtime resolves to Core's Vue instance.
//
// Why this matters: when two independently bundled Vue runtimes
// share a component object (Core's `<KHero>` rendered inside an
// extension's app), the second runtime sees `currentInstance =
// null` for that component and crashes with
// `TypeError: Cannot read properties of null (reading 'ce')` from
// Vue's renderer. Sharing the runtime kills that whole class of
// bug for good.
//
// Global names: deliberately ugly so collisions with operator code
// are unlikely. We expose the matching short aliases (`KnotVue`,
// `KnotPinia`, …) for ergonomic ad-hoc debugging.
//
// Naming policy: globals are READ-ONLY snapshots. Extensions must
// NOT mutate them — doing so would propagate to Core and break the
// dashboard. The shape mirrors each module's `import * as X from
// '<module>'` so the IIFE `globals` map in extensions stays
// trivially correct.
const sharedRuntime = {
  Vue: VueRuntime,
  Pinia: PiniaRuntime,
  VueI18n: VueI18nRuntime,
  VueRouter: VueRouterRuntime,
};
type KnotSharedRuntimeWindow = typeof globalThis & {
  __KNOT_VUE__?: typeof sharedRuntime;
  KnotSharedVue?: typeof VueRuntime;
  KnotSharedPinia?: typeof PiniaRuntime;
  KnotSharedVueI18n?: typeof VueI18nRuntime;
  KnotSharedVueRouter?: typeof VueRouterRuntime;
};
const sharedTarget = globalThis as KnotSharedRuntimeWindow;
sharedTarget.__KNOT_VUE__ = sharedRuntime;
// Flat globals consumed by extension Rollup `output.globals`. The
// `KnotShared*` names match the externalization map in
// `knot-migration/frontend/vite.config.ts`. Adding a new shared
// library requires (1) a `KnotShared<Name>` export here, (2) a
// matching entry in every extension's vite config, (3) a CHANGELOG
// note so existing extension authors update their config.
sharedTarget.KnotSharedVue = VueRuntime;
sharedTarget.KnotSharedPinia = PiniaRuntime;
sharedTarget.KnotSharedVueI18n = VueI18nRuntime;
sharedTarget.KnotSharedVueRouter = VueRouterRuntime;

// ADR-20 slice 3: install window.KnotCore as early as possible so
// any extension <script src="…/dist/knot-extension.js" defer> that
// finishes parsing before Core's Vue tree is mounted can already
// call KnotCore.registerExtension(...). The surface is idempotent
// so duplicated bundle includes (browser back, hot-reload) are safe.
const knotCore = installKnotCore();

// ADR-20 §4.3 — populate `window.KnotCore.ui` with the canonical
// Vue primitives extensions are allowed to reuse. The surface stays
// open-ended (extensions read `KnotCoreUi` and downgrade gracefully
// when a primitive is missing) so growing this list later does not
// break older bundles.
knotCore.ui.KHero = KHero;
knotCore.ui.KGlassCard = KGlassCard;
knotCore.ui.KEmptyState = KEmptyState;
knotCore.ui.KSkeleton = KSkeleton;
knotCore.ui.KAnimatedCounter = KAnimatedCounter;

function boot(): void {
  const root = document.getElementById('knot-app');
  if (!root) {
    return;
  }

  const app = createApp(App, {
    mode: root.dataset.mode ?? 'editor',
    workflowId: root.dataset.workflowId ? Number(root.dataset.workflowId) : null,
    executionId: root.dataset.executionId ? Number(root.dataset.executionId) : null,
    executionTab: root.dataset.executionTab?.trim() ? root.dataset.executionTab.trim() : null,
  });

  app.use(createPinia());
  app.use(i18n);
  app.mount(root);
}

if (document.readyState === 'loading') {
  document.addEventListener('DOMContentLoaded', boot);
} else {
  boot();
}
