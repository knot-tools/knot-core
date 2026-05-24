import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import KProBadge from '../KProBadge.vue';

describe('KProBadge', () => {
  it('renders the default Pro pill', () => {
    const wrapper = mount(KProBadge);
    expect(wrapper.text()).toContain('Pro');
    expect(wrapper.attributes('aria-label')).toBe('Pro');
  });

  it('uses the tooltip as aria-label when provided', () => {
    const wrapper = mount(KProBadge, {
      props: { label: 'Pro', tooltip: 'Activate your Pro Pack license' },
    });
    expect(wrapper.attributes('aria-label')).toBe(
      'Activate your Pro Pack license',
    );
  });

  it('renders the lock variant when variant=lock', () => {
    const wrapper = mount(KProBadge, {
      props: { variant: 'lock', label: 'Pro' },
    });
    expect(wrapper.attributes('aria-label')).toContain('Locked');
    expect(wrapper.element.tagName.toLowerCase()).toBe('div');
  });
});
