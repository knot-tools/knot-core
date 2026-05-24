/**
 * Single-flight cache for knotApi.connectors() to avoid duplicate roundtrips
 * (editor, credentials, connectors catalog in one session).
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { knotApi, type ConnectorDescriptor, type ExtensionSummary } from '@/lib/api';

export type ConnectorsCatalogPayload = {
  connectors: ConnectorDescriptor[];
  palette: Array<{ category: string; title: string; ids: string[] }>;
  extensions: ExtensionSummary[];
};

let cached: ConnectorsCatalogPayload | null = null;
let inflight: Promise<ConnectorsCatalogPayload> | null = null;

export function invalidateConnectorsCatalogCache(): void {
  cached = null;
}

export async function getConnectorsCatalogCached(): Promise<ConnectorsCatalogPayload> {
  if (cached !== null) return cached;
  if (inflight) return inflight;
  inflight = knotApi
    .connectors()
    .then((res) => {
      cached = {
        connectors: res.connectors,
        palette: res.palette,
        extensions: res.extensions ?? [],
      };
      return cached;
    })
    .finally(() => {
      inflight = null;
    });
  return inflight;
}

export async function getConnectorDescriptorsCached(): Promise<ConnectorDescriptor[]> {
  const payload = await getConnectorsCatalogCached();
  return payload.connectors;
}
