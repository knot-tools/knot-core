/**
 * Pilot slugs for bundled schema snapshots / compatibility tooling (ADR-010).
 * Align with PHP `PilotDocuments::SCHEMA_SNAPSHOT_SLUGS`.
 *
 * State-machine UX does not gate on this list — any Dolibarr object slug may fetch verbs.
 */

export const PILOT_DOCUMENT_SLUGS = ['facture', 'commande', 'propal'] as const;

export type PilotDocumentSlug = (typeof PILOT_DOCUMENT_SLUGS)[number];

export function isPilotDocumentSlug(slug: string): slug is PilotDocumentSlug {
  return (PILOT_DOCUMENT_SLUGS as readonly string[]).includes(slug);
}
