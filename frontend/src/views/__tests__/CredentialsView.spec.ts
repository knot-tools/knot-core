import { defineComponent, h } from 'vue';
import { flushPromises, mount } from '@vue/test-utils';
import { i18n } from '../../i18n';
import { provideConfirm } from '../../composables/useConfirm';
import { provideToast } from '../../composables/useToast';
import CredentialsView from '../CredentialsView.vue';
import { knotApi } from '../../lib/api';

vi.mock('../../lib/connectorDescriptorsCache', () => ({
  getConnectorsCatalogCached: vi.fn(async () => ({
    connectors: [
      {
        metadata: { id: 'action.whatsapp_cloud', label: 'WhatsApp Cloud' },
        credentialType: 'whatsapp.cloud',
        credentialSchema: {
          type: 'whatsapp.cloud',
          label: 'WhatsApp Business Cloud API',
          fields: [
            { name: 'phoneNumberId', label: 'Phone number id', type: 'string', required: true },
            { name: 'accessToken', label: 'System user access token', type: 'string', secret: true, required: true },
          ],
        },
      },
    ],
  })),
}));

const Host = defineComponent({
  name: 'CredentialsViewHost',
  setup() {
    provideToast();
    provideConfirm();
    return () => h(CredentialsView, { workflowId: null, executionId: null });
  },
});

describe('CredentialsView', () => {
  beforeEach(() => {
    i18n.global.locale.value = 'en_US';
    vi.spyOn(knotApi, 'listCredentials').mockResolvedValue({
      credentials: [],
      counts: {},
    });
    vi.spyOn(knotApi, 'listOAuthProviders').mockResolvedValue({ providers: [] });
  });

  afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
  });

  it('renders legacy fields[] credential schema in the create modal', async () => {
    const wrapper = mount(Host, {
      global: { plugins: [i18n] },
      attachTo: document.body,
    });
    await flushPromises();

    const createButton = wrapper
      .findAll('button')
      .find((btn) => btn.text().includes(i18n.global.t('credentialsPage.newCredential')));
    expect(createButton).toBeDefined();
    await createButton!.trigger('click');
    await flushPromises();

    const bodyText = document.body.textContent ?? '';
    expect(bodyText).toContain('Phone number id');
    expect(bodyText).toContain('System user access token');
    expect(document.body.querySelector('input[type="password"]')).not.toBeNull();

    wrapper.unmount();
  });
});
