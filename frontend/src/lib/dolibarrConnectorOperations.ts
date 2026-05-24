/**
 * Order matches `ObjectAction::getConfigSchema()` enum (`dolibarr.object` connector).
 * UI labels mirror {@link DOLIBARR_OBJECT_OPERATION_LABELS_FR}.
 */
export const DOLIBARR_OBJECT_CONNECTOR_OPERATION_ORDER = [
  'fetch',
  'create',
  'update',
  'delete',
  'change_status',
  'add_note',
  'generate_pdf',
] as const;

export type DolibarrConnectorOperation = (typeof DOLIBARR_OBJECT_CONNECTOR_OPERATION_ORDER)[number];

/** French labels used in inspector panels */
export const DOLIBARR_OBJECT_OPERATION_LABELS_FR: Record<DolibarrConnectorOperation, string> = {
  fetch: 'Récupérer',
  create: 'Créer',
  update: 'Mettre à jour',
  delete: 'Supprimer',
  change_status: 'Changer statut',
  add_note: 'Ajouter note',
  generate_pdf: 'Générer PDF',
};
