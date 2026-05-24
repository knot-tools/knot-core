import { describe, expect, it, beforeEach } from 'vitest';
import {
  ignoreUpdateVersion,
  isUpdateBannerHidden,
  snoozeUpdateBanner,
} from '../../composables/useUpdatesSnooze';

describe('useUpdatesSnooze', () => {
  beforeEach(() => {
    localStorage.clear();
    (window as unknown as { KNOT_ENTITY?: number }).KNOT_ENTITY = 1;
  });

  it('snooze hides banner until expiry', () => {
    snoozeUpdateBanner('knot', '2.14.0');
    expect(isUpdateBannerHidden('knot', '2.14.0')).toBe(true);
    expect(isUpdateBannerHidden('knot', '2.15.0')).toBe(false);
  });

  it('ignore hides until version changes', () => {
    ignoreUpdateVersion('knot-pro-pack', '1.2.0');
    expect(isUpdateBannerHidden('knot-pro-pack', '1.2.0')).toBe(true);
    expect(isUpdateBannerHidden('knot-pro-pack', '1.3.0')).toBe(false);
  });
});
