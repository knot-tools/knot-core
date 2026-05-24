/**
 * Dolibarr workflow-module hook codes surfaced in the Knot palette.
 *
 * Knot `dolibarr.object` uses a separate vocabulary (fetch/create/… at the
 * connector level). Hook codes listed here dispatch when Dolibarr emits
 * business events (`interface_mod*` trigger lists).
 */

const EXTRA_CODES = [
  'BILL_MODIFY',
  'CONTRACT_CREATE',
  'CONTRACT_VALIDATE',
  'EXPEDITION_CREATE',
  'EXPEDITION_VALIDATE',
  'MEMBER_MODIFY',
  'ORDER_MODIFY',
  'PAYMENT_CUSTOMER_CREATE',
  'PROPAL_MODIFY',
  'TICKET_CREATE',
  'TICKET_MODIFY',
] as const;

const CORE_CODES = [
  'BILL_CREATE',
  'BILL_VALIDATE',
  'BILL_PAYED',
  'BILL_CANCEL',
  'ORDER_CREATE',
  'ORDER_VALIDATE',
  'ORDER_CLOSE',
  'PROPAL_CREATE',
  'PROPAL_VALIDATE',
  'PROPAL_CLOSE_SIGNED',
  'COMPANY_CREATE',
  'COMPANY_MODIFY',
  'CONTACT_CREATE',
  'CONTACT_MODIFY',
  'PRODUCT_CREATE',
  'PRODUCT_MODIFY',
  'PROJECT_CREATE',
  'TASK_CREATE',
] as const;

export const DOLIBARR_WORKFLOW_TRIGGER_EVENTS = Array.from(new Set<string>([...CORE_CODES, ...EXTRA_CODES])).sort((a, b) =>
  a.localeCompare(b),
);
