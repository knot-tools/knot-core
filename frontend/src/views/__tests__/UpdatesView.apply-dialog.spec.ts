import { defineComponent, h } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { i18n } from '../../i18n';
import { provideConfirm } from '../../composables/useConfirm';
import { provideToast } from '../../composables/useToast';
import KConfirmDialog from '../../components/ui/KConfirmDialog.vue';
import UpdatesView from '../UpdatesView.vue';
import { knotApi, type UpdatesCheckResponse } from '../../lib/api';

const Host = defineComponent({
  name: 'UpdatesViewApplyDialogHost',
  setup() {
    provideToast();
    provideConfirm();
    return () =>
      h('div', [h(UpdatesView, { workflowId: null, executionId: null }), h(KConfirmDialog)]);
  },
});

function coreUpdateSnapshot(): UpdatesCheckResponse {
  return {
    checkedAt: 1,
    hasAnyUpdate: true,
    entries: [
      {
        slug: 'knot',
        installedVersion: '2.13.17',
        latestVersion: '2.13.18',
        channel: 'stable',
        publishedAt: null,
        hasUpdate: true,
        source: 'live',
        error: null,
      },
    ],
  };
}

describe('UpdatesView apply confirm (real KConfirmDialog)', () => {
  beforeEach(() => {
    (window as unknown as Record<string, unknown>).KNOT_BASE_URL = '/custom/knot/workflows/preview.php';
    (window as unknown as Record<string, unknown>).KNOT_CSRF_TOKEN = 'test-csrf';
    (window as unknown as Record<string, unknown>).KNOT_MARKETPLACE_UI_ENABLED = true;
    i18n.global.locale.value = 'en_US';
  });

  afterEach(() => {
    document.body.innerHTML = '';
    vi.restoreAllMocks();
  });

  it('opens confirm then calls updatesApply with slug knot', async () => {
    vi.spyOn(knotApi, 'updates').mockResolvedValue(coreUpdateSnapshot());
    vi.spyOn(knotApi, 'marketplace').mockResolvedValue({
      packs: [],
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

    await wrapper.get('[data-testid="updates-apply-knot"]').trigger('click');
    await flushPromises();

    const dialog = document.body.querySelector('[data-knot-test="knot-confirm-dialog"]');
    expect(dialog).not.toBeNull();
    expect(dialog?.getAttribute('role')).toBe('dialog');
    expect(applySpy).not.toHaveBeenCalled();

    const accept = dialog?.querySelector('[data-knot-test="knot-confirm-accept"]') as HTMLButtonElement | null;
    expect(accept).not.toBeNull();
    accept?.click();
    await flushPromises();

    expect(applySpy).toHaveBeenCalledWith({
      slug: 'knot',
      version: '2.13.18',
      channel: 'stable',
    });
    wrapper.unmount();
  });
});
