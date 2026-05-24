/**
 * Connector catalog injection for canvas nodes (EditorView → KnotNode).
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import type { InjectionKey, Ref } from 'vue';
import type { ConnectorDescriptor } from './api';

export const KNOT_CONNECTORS_KEY: InjectionKey<Ref<ConnectorDescriptor[]>> = Symbol(
  'knot-connectors-catalog',
);
