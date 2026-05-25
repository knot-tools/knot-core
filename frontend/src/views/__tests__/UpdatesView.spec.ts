import { defineComponent, h, ref } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { i18n } from '../../i18n';
import { provideConfirm } from '../../composables/useConfirm';
import { provideToast } from '../../composables/useToast';
import UpdatesView from '../UpdatesView.vue';

vi.mock('../../composables/useConfirm', async (importOriginal) => {
  const actual = await importOriginal<typeof import('../../composables/useConfirm')>();
  const confirm = vi.fn(async () => true);

  return {
    ...actual,
    useConfirm: () => ({
      confirm,
      state: ref({ open: false, options: { title: '' }, resolve: null }),
      answer: vi.fn(),
    }),
  };
});

const toastError = vi.fn();
const toastInfo = vi.fn();
const toastWarning = vi.fn();
vi.mock('../../composables/useToast', () => ({
  provideToast: () => ({
    toasts: ref([]),
    show: vi.fn(),
    dismiss: vi.fn(),
    success: vi.fn(),
    warning: toastWarning,
    error: toastError,
    info: toastInfo,
  }),
  useToast: () => ({
    toasts: ref([]),
    show: vi.fn(),
    dismiss: vi.fn(),
    success: vi.fn(),
    warning: toastWarning,
    error: toastError,
    info: toastInfo,
  }),
}));

import { knotApi, type UpdatesCheckResponse } from '../../lib/api';

const Host = defineComponent({
  name: 'UpdatesViewHost',
  setup() {
    provideToast();
    provideConfirm();
    return () => h(UpdatesView, { workflowId: null, executionId: null });
  },
});

describe('UpdatesView', () => {
  beforeEach(() => {
    toastError.mockClear();
    toastInfo.mockClear();
    toastWarning.mockClear();
    (window as unknown as Record<string, unknown>).KNOT_BASE_URL = '/custom/knot/workflows/preview.php';
    (window as unknown as Record<string, unknown>).KNOT_CSRF_TOKEN = 'test-csrf';
    (window as unknown as Record<string, unknown>).KNOT_MARKETPLACE_UI_ENABLED = true;

    i18n.global.locale.value = 'en_US';
  });

  afterEach(() => {
    vi.restoreAllMocks();
  });

  it('lists products from knotApi.updates()', async () => {
    const snapshot: UpdatesCheckResponse = {
      checkedAt: 1,
      hasAnyUpdate: true,
      entries: [
        {
          slug: 'knot',
          installedVersion: '2.0.0',
          latestVersion: '2.1.0',
          channel: 'stable',
          publishedAt: null,
          hasUpdate: true,
          source: 'live',
          error: null,
        },
      ],
    };
    vi.spyOn(knotApi, 'updates').mockResolvedValue(snapshot);
    vi.spyOn(knotApi, 'marketplace').mockResolvedValue({
      packs: [{ slug: 'knot', buyUrl: 'https://example.invalid/buy', label: 'Knot' }],
      templates: [],
    } as unknown as Awaited<ReturnType<(typeof knotApi)['marketplace']>>);

    const wrapper = mount(Host, {
      global: { plugins: [i18n] },
      attachTo: document.body,
    });
    await flushPromises();

    expect(wrapper.text()).toContain('knot');
    expect(wrapper.text()).toContain('2.0.0');
    wrapper.unmount();
  });

  it('calls knotApi.updatesApply when Apply is clicked', async () => {
    const snapshot: UpdatesCheckResponse = {
      checkedAt: 1,
      hasAnyUpdate: true,
      entries: [
        {
          slug: 'knot',
          installedVersion: '2.0.0',
          latestVersion: '2.1.0',
          channel: 'stable',
          publishedAt: null,
          hasUpdate: true,
          source: 'live',
          error: null,
        },
      ],
    };
    vi.spyOn(knotApi, 'updates').mockResolvedValue(snapshot);
    vi.spyOn(knotApi, 'marketplace').mockResolvedValue({
      packs: [{ slug: 'knot', buyUrl: null, label: 'Knot' }],
      templates: [],
    } as unknown as Awaited<ReturnType<(typeof knotApi)['marketplace']>>);

    const applySpy = vi.spyOn(knotApi, 'updatesApply').mockResolvedValue({
      slug: 'knot',
      path: '/tmp/knot',
    });

    const wrapper = mount(Host, {
      global: { plugins: [i18n] },
      attachTo: document.body,
    });
    await flushPromises();

    const btn = wrapper.findAll('button').find((b) => b.text().includes('Apply'));
    expect(btn).toBeTruthy();
    await btn!.trigger('click');
    await flushPromises();

    expect(applySpy).toHaveBeenCalledWith({ slug: 'knot' });
    wrapper.unmount();
  });

  it('shows signature-specific toast when apply returns release_signature_invalid', async () => {
    const snapshot: UpdatesCheckResponse = {
      checkedAt: 1,
      hasAnyUpdate: true,
      entries: [
        {
          slug: 'knot-pro-pack',
          installedVersion: '0.1.3',
          latestVersion: '0.1.4',
          channel: 'beta',
          publishedAt: null,
          hasUpdate: true,
          source: 'live',
          error: null,
        },
      ],
    };
    vi.spyOn(knotApi, 'updates').mockResolvedValue(snapshot);
    vi.spyOn(knotApi, 'marketplace').mockResolvedValue({
      packs: [],
      templates: [],
    } as unknown as Awaited<ReturnType<(typeof knotApi)['marketplace']>>);

    const err = new Error('ignored') as Error & { error_code?: string };
    err.error_code = 'release_signature_invalid';
    vi.spyOn(knotApi, 'updatesApply').mockRejectedValue(err);

    const wrapper = mount(Host, {
      global: { plugins: [i18n] },
      attachTo: document.body,
    });
    await flushPromises();

    const btn = wrapper.findAll('button').find((b) => b.text().includes('Apply'));
    await btn!.trigger('click');
    await flushPromises();

    expect(toastError).toHaveBeenCalledWith(
      'Release signature invalid',
      expect.objectContaining({
        body: expect.stringContaining('Ed25519'),
      }),
    );
    wrapper.unmount();
  });

  it('passes force=true when Check for updates now is clicked', async () => {
    const snapshot: UpdatesCheckResponse = {
      checkedAt: 1,
      hasAnyUpdate: false,
      entries: [],
    };
    const updatesSpy = vi.spyOn(knotApi, 'updates').mockResolvedValue(snapshot);
    vi.spyOn(knotApi, 'marketplace').mockResolvedValue({
      packs: [],
      templates: [],
    } as unknown as Awaited<ReturnType<(typeof knotApi)['marketplace']>>);

    const wrapper = mount(Host, {
      global: { plugins: [i18n] },
      attachTo: document.body,
    });
    await flushPromises();
    updatesSpy.mockClear();

    await wrapper.get('[data-testid="updates-check-now"]').trigger('click');
    await flushPromises();

    expect(updatesSpy).toHaveBeenCalledWith({ force: true });
    wrapper.unmount();
  });

  it('shows info toast when apply returns migrations log', async () => {
    const snapshot: UpdatesCheckResponse = {
      checkedAt: 1,
      hasAnyUpdate: true,
      entries: [
        {
          slug: 'knot-migration',
          installedVersion: '0.21.3',
          latestVersion: '0.21.4',
          channel: 'beta',
          publishedAt: null,
          hasUpdate: true,
          source: 'live',
          error: null,
        },
      ],
    };
    vi.spyOn(knotApi, 'updates').mockResolvedValue(snapshot);
    vi.spyOn(knotApi, 'marketplace').mockResolvedValue({ packs: [], templates: [] } as never);
    vi.spyOn(knotApi, 'updatesApply').mockResolvedValue({
      slug: 'knot-migration',
      path: '/tmp/knotmigration',
      migrations: [{ version: 'v0.21.0', file: '01.sql', status: 'applied', durationMs: 1 }],
    });

    const wrapper = mount(Host, { global: { plugins: [i18n] }, attachTo: document.body });
    await flushPromises();
    const btn = wrapper.findAll('button').find((b) => b.text().includes('Apply'));
    await btn!.trigger('click');
    await flushPromises();

    expect(toastInfo).toHaveBeenCalledWith('1 database migration(s) applied.');
    wrapper.unmount();
  });

  it('shows warning toast when migration_failed rollback restored', async () => {
    const snapshot: UpdatesCheckResponse = {
      checkedAt: 1,
      hasAnyUpdate: true,
      entries: [
        {
          slug: 'knot',
          installedVersion: '2.13.3',
          latestVersion: '2.13.4',
          channel: 'beta',
          publishedAt: null,
          hasUpdate: true,
          source: 'live',
          error: null,
        },
      ],
    };
    vi.spyOn(knotApi, 'updates').mockResolvedValue(snapshot);
    vi.spyOn(knotApi, 'marketplace').mockResolvedValue({ packs: [], templates: [] } as never);
    const err = new Error('SQL failed') as Error & { error_code?: string; details?: { rollback?: string } };
    err.error_code = 'migration_failed';
    err.details = { rollback: 'restored' };
    vi.spyOn(knotApi, 'updatesApply').mockRejectedValue(err);

    const wrapper = mount(Host, { global: { plugins: [i18n] }, attachTo: document.body });
    await flushPromises();
    const btn = wrapper.findAll('button').find((b) => b.text().includes('Apply'));
    await btn!.trigger('click');
    await flushPromises();

    expect(toastWarning).toHaveBeenCalledWith(
      'Database migration failed — previous files were restored.',
      expect.objectContaining({ body: 'SQL failed' }),
    );
    wrapper.unmount();
  });

  it('shows error toast when migration_failed rollback failed', async () => {
    const snapshot: UpdatesCheckResponse = {
      checkedAt: 1,
      hasAnyUpdate: true,
      entries: [
        {
          slug: 'knot-pro-pack',
          installedVersion: '0.1.3',
          latestVersion: '0.1.4',
          channel: 'beta',
          publishedAt: null,
          hasUpdate: true,
          source: 'live',
          error: null,
        },
      ],
    };
    vi.spyOn(knotApi, 'updates').mockResolvedValue(snapshot);
    vi.spyOn(knotApi, 'marketplace').mockResolvedValue({ packs: [], templates: [] } as never);
    const err = new Error('Critical rollback failure') as Error & {
      error_code?: string;
      details?: { rollback?: string };
    };
    err.error_code = 'migration_failed';
    err.details = { rollback: 'failed' };
    vi.spyOn(knotApi, 'updatesApply').mockRejectedValue(err);

    const wrapper = mount(Host, { global: { plugins: [i18n] }, attachTo: document.body });
    await flushPromises();
    const btn = wrapper.findAll('button').find((b) => b.text().includes('Apply'));
    await btn!.trigger('click');
    await flushPromises();

    expect(toastError).toHaveBeenCalledWith(
      'Database migration failed — file rollback also failed.',
      expect.objectContaining({ body: 'Critical rollback failure' }),
    );
    wrapper.unmount();
  });
});
