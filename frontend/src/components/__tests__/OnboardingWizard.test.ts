import { mount, flushPromises } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { i18n } from '../../i18n';
import OnboardingWizard from '../OnboardingWizard.vue';

const baseState = {
  data: {
    completed: false,
    isAdmin: true,
    cron: {
      ok: true,
      globalEnabled: true,
      jobsEnabled: true,
      recentRun: true,
      knotJobsRegistered: 3,
      knotJobsActive: 3,
      webUrl: 'https://example.com/dolibarr/public/cron/cron_run_jobs_by_url.php?securitykey=sec&userlogin=admin',
      userLogin: 'admin',
    },
    prerequisites: {
      dolibarrCronModule: true,
      phpExtensionsOk: true,
      phpExtensionsMissing: [],
      cronKeyConfigured: true,
    },
    rights: { count: 5, expected: 5 },
    encryption: { ok: true, fingerprint: 'abc123def456' },
    smtp: { configured: false, host: null },
    starter: { available: true, count: 5 },
  },
};

describe('OnboardingWizard', () => {
  beforeEach(() => {
    (window as unknown as Record<string, unknown>).KNOT_LOCALE = 'fr_FR';
    i18n.global.locale.value = 'fr_FR';
    (window as unknown as Record<string, unknown>).KNOT_USER_ADMIN = true;
    (window as unknown as Record<string, unknown>).KNOT_FIRSTRUN_COMPLETED = false;
    (window as unknown as Record<string, unknown>).KNOT_API_BASE = '/api';
    (window as unknown as Record<string, unknown>).KNOT_CSRF_TOKEN = 'test-csrf';
    (window as unknown as Record<string, unknown>).KNOT_BASE_URL = '/dol/custom/knot/workflows/preview.php';
    (window as unknown as Record<string, unknown>).KNOT_SETUP_URL = '/dol/custom/knot/admin/setup.php?admin=1';
    globalThis.fetch = vi.fn(async () => ({
      ok: true,
      json: async () => baseState,
    })) as unknown as typeof fetch;
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('auto-opens for admins on a fresh install', async () => {
    const wrapper = mount(OnboardingWizard, {
      attachTo: document.body,
      global: { plugins: [i18n] },
    });
    await flushPromises();
    expect(document.body.textContent).toContain('Mise en route');
    expect(document.body.textContent).toContain('Prérequis');
    expect(document.body.textContent).toContain('Tâches Knot');
    expect(document.body.textContent).toContain('Cron');
    expect(document.body.textContent).toContain('Permissions');
    wrapper.unmount();
  });

  it('does not auto-open when KNOT_FIRSTRUN_COMPLETED is true', async () => {
    (window as unknown as Record<string, unknown>).KNOT_FIRSTRUN_COMPLETED = true;
    mount(OnboardingWizard, { attachTo: document.body, global: { plugins: [i18n] } });
    await flushPromises();
    // Body should not contain wizard markers when closed.
    expect(document.body.textContent ?? '').not.toContain('Étape 1 / 8');
  });

  it('does not auto-open when API reports onboarding already completed', async () => {
    (window as unknown as Record<string, unknown>).KNOT_FIRSTRUN_COMPLETED = false;
    globalThis.fetch = vi.fn(async () => ({
      ok: true,
      json: async () => ({
        data: { ...baseState.data, completed: true },
      }),
    })) as unknown as typeof fetch;
    mount(OnboardingWizard, { attachTo: document.body, global: { plugins: [i18n] } });
    await flushPromises();
    expect(document.body.textContent ?? '').not.toContain('👋 Bienvenue dans Knot');
  });

  it('renders the post-wizard checklist on step 1', async () => {
    mount(OnboardingWizard, { attachTo: document.body, global: { plugins: [i18n] } });
    await flushPromises();
    expect(document.body.textContent).toMatch(/Ce qu['\u2019]il reste après cet assistant/);
  });

  it('exposes a forceOpen prop for re-launching the wizard', async () => {
    (window as unknown as Record<string, unknown>).KNOT_FIRSTRUN_COMPLETED = true;
    mount(OnboardingWizard, {
      props: { forceOpen: true },
      attachTo: document.body,
      global: { plugins: [i18n] },
    });
    await flushPromises();
    expect(document.body.textContent).toContain('👋 Bienvenue dans Knot');
  });
});
