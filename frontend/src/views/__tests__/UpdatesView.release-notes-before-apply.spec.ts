/**
 * Inspectable proof: admins read release notes BEFORE Apply.
 * Fixture-driven (no ZIP, no GitHub Release URL as the notes UX).
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */
import { defineComponent, h } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { i18n } from '../../i18n';
import { provideConfirm } from '../../composables/useConfirm';
import { provideToast } from '../../composables/useToast';
import KConfirmDialog from '../../components/ui/KConfirmDialog.vue';
import UpdatesView from '../UpdatesView.vue';
import { knotApi, type UpdatesCheckResponse } from '../../lib/api';

const FIXTURE_PHRASE = 'UNIQUE_NOTES_BEFORE_APPLY_FIXTURE';

const Host = defineComponent({
  name: 'UpdatesViewReleaseNotesProofHost',
  setup() {
    provideToast();
    provideConfirm();
    return () =>
      h('div', [h(UpdatesView, { workflowId: null, executionId: null }), h(KConfirmDialog)]);
  },
});

function snapshotWithNotes(): UpdatesCheckResponse {
  return {
    checkedAt: 1,
    hasAnyUpdate: true,
    entries: [
      {
        slug: 'knot',
        installedVersion: '2.13.21',
        latestVersion: '2.13.22',
        channel: 'beta',
        publishedAt: '2026-09-07T00:00:00+00:00',
        notes: `## [2.13.22]\n\n### Added\n\n- **${FIXTURE_PHRASE}** from signed releases.json latest.notes.`,
        hasUpdate: true,
        source: 'live',
        error: null,
      },
    ],
  };
}

describe('UpdatesView release notes before Apply (inspectable proof)', () => {
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

  it('shows changelog notes on the update card in the DOM while hasUpdate, before Apply is clicked', async () => {
    vi.spyOn(knotApi, 'updates').mockResolvedValue(snapshotWithNotes());
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

    const card = wrapper.get('[data-testid="updates-card-knot"]');
    expect(card.attributes('data-has-update')).toBe('1');
    const notes = wrapper.get('[data-testid="updates-release-notes"]');
    expect(notes.text()).toContain("What's new in this version");
    expect(notes.text()).toContain(FIXTURE_PHRASE);
    expect(notes.html()).toContain(`<strong>${FIXTURE_PHRASE}</strong>`);
    expect(wrapper.get('[data-testid="updates-apply-knot"]').exists()).toBe(true);
    expect(wrapper.html()).not.toMatch(/github\.com\/knot-tools\/knot-core\/releases/i);
    expect(applySpy).not.toHaveBeenCalled();

    wrapper.unmount();
  });

  it('shows the same notes in the Apply confirm dialog and does not call apply until accept', async () => {
    vi.spyOn(knotApi, 'updates').mockResolvedValue(snapshotWithNotes());
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

    expect(wrapper.get('[data-testid="updates-release-notes"]').text()).toContain(FIXTURE_PHRASE);

    await wrapper.get('[data-testid="updates-apply-knot"]').trigger('click');
    await flushPromises();

    const dialog = document.body.querySelector('[data-knot-test="knot-confirm-dialog"]');
    expect(dialog).not.toBeNull();
    const details = dialog?.querySelector('[data-knot-test="knot-confirm-details"]');
    expect(details?.textContent).toContain(FIXTURE_PHRASE);
    expect(details?.innerHTML).toContain(`<strong>${FIXTURE_PHRASE}</strong>`);
    expect(applySpy).not.toHaveBeenCalled();

    (dialog?.querySelector('[data-knot-test="knot-confirm-accept"]') as HTMLButtonElement | null)?.click();
    await flushPromises();

    expect(applySpy).toHaveBeenCalledWith({
      slug: 'knot',
      version: '2.13.22',
      channel: 'beta',
    });
    wrapper.unmount();
  });
});
