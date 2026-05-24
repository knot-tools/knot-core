import { describe, expect, it, afterEach } from 'vitest';
import { isMarketplaceUiEnabled } from '../marketplaceUi';

describe('marketplaceUi', () => {
  afterEach(() => {
    delete (window as unknown as Record<string, unknown>).KNOT_MARKETPLACE_UI_ENABLED;
  });

  it('treats undefined flag as enabled', () => {
    expect(isMarketplaceUiEnabled()).toBe(true);
  });

  it('is disabled when injected false', () => {
    (window as unknown as Record<string, unknown>).KNOT_MARKETPLACE_UI_ENABLED = false;
    expect(isMarketplaceUiEnabled()).toBe(false);
  });

  it('is enabled when injected true', () => {
    (window as unknown as Record<string, unknown>).KNOT_MARKETPLACE_UI_ENABLED = true;
    expect(isMarketplaceUiEnabled()).toBe(true);
  });
});
