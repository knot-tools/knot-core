/**
 * Injectable block context (`provide`/`inject`) for Marketplace blocks.
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import type { InjectionKey } from 'vue';
import type { BlockContext } from './types';

export const knotMarketplaceBlockContextKey: InjectionKey<BlockContext> = Symbol(
  'knotMarketplaceBlockContext',
);
