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
});
