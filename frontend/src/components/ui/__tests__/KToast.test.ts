import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import KToast from '../KToast.vue';

describe('KToast', () => {
  it('renders title and body', () => {
    const wrapper = mount(KToast, {
      props: { title: 'Activated', body: 'Workflow is now live.' },
    });
    expect(wrapper.text()).toContain('Activated');
    expect(wrapper.text()).toContain('Workflow is now live.');
  });

  it('emits dismiss when the close button is clicked', async () => {
    const wrapper = mount(KToast, {
      props: { title: 'Hi', sticky: true },
    });
    await wrapper.find('button').trigger('click');
    expect(wrapper.emitted('dismiss')).toBeTruthy();
  });

  it('auto-dismisses after the configured duration', () => {
    vi.useFakeTimers();
    const wrapper = mount(KToast, {
      props: { title: 'Hi', duration: 1000 },
    });
    expect(wrapper.emitted('dismiss')).toBeFalsy();
    vi.advanceTimersByTime(1100);
    expect(wrapper.emitted('dismiss')).toBeTruthy();
    vi.useRealTimers();
  });

  it('does not auto-dismiss when sticky', () => {
    vi.useFakeTimers();
    const wrapper = mount(KToast, {
      props: { title: 'Hi', sticky: true, duration: 100 },
    });
    vi.advanceTimersByTime(500);
    expect(wrapper.emitted('dismiss')).toBeFalsy();
    vi.useRealTimers();
  });

  it('uses role=status for accessibility', () => {
    const wrapper = mount(KToast, {
      props: { title: 'Hi', sticky: true },
    });
    expect(wrapper.attributes('role')).toBe('status');
    expect(wrapper.attributes('aria-live')).toBe('polite');
  });
});
