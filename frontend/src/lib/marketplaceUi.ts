/**
 * Host page flags for Marketplace chrome (set in workflows/preview.php).
 */
export function isMarketplaceUiEnabled(): boolean {
  if (typeof window === 'undefined') {
    return true;
  }
  const w = window as unknown as { KNOT_MARKETPLACE_UI_ENABLED?: boolean };

  return w.KNOT_MARKETPLACE_UI_ENABLED !== false;
}
