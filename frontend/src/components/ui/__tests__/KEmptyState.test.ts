import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import KEmptyState from '../KEmptyState.vue';

describe('KEmptyState', () => {
  it('renders the title and body', () => {
    const wrapper = mount(KEmptyState, {
      props: {
        title: 'Pas encore de workflow',
        body: 'Crée ton premier workflow.',
      },
    });
    expect(wrapper.text()).toContain('Pas encore de workflow');
    expect(wrapper.text()).toContain('Crée ton premier workflow.');
  });

  it('hides the action button when no actionLabel is provided', () => {
    const wrapper = mount(KEmptyState, {
      props: { title: 'Empty' },
    });
    expect(wrapper.find('button').exists()).toBe(false);
  });

  it('emits "action" when the primary CTA is clicked', async () => {
    const wrapper = mount(KEmptyState, {
      props: { title: 'Empty', actionLabel: 'Create' },
    });
    await wrapper.find('button').trigger('click');
    expect(wrapper.emitted('action')).toBeTruthy();
  });

  it('renders both primary and secondary CTAs', () => {
    const wrapper = mount(KEmptyState, {
      props: {
        title: 'Empty',
        actionLabel: 'Create',
        secondaryLabel: 'Import',
      },
    });
    const buttons = wrapper.findAll('button');
    expect(buttons).toHaveLength(2);
    expect(buttons[0].text()).toBe('Create');
    expect(buttons[1].text()).toBe('Import');
  });

  it('emits tertiary when the tertiary CTA is clicked', async () => {
    const wrapper = mount(KEmptyState, {
      props: {
        title: 'Empty',
        actionLabel: 'Create',
        tertiaryLabel: 'Starters',
      },
    });
    await wrapper.get('[data-testid="k-empty-state-tertiary"]').trigger('click');
    expect(wrapper.emitted('tertiary')).toBeTruthy();
  });
});
