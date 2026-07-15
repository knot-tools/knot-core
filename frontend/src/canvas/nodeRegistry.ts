/**
 * Knot node registry — icon, color, category for each node type.
 * Mirrors the backend `Knot\Connectors\ConnectorRegistry`.
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import {
  Play,
  Webhook,
  Clock,
  Mail,
  Database,
  Sparkles,
  Globe,
  GitBranch,
  Layers,
  Brain,
  MessageSquare,
  Hash,
  CalendarDays,
  Type,
  Braces,
  GitMerge,
  Hourglass,
  Bot,
  Send,
  BellRing,
  Workflow,
  OctagonX,
  Inbox,
  CreditCard,
  ShoppingCart,
  Phone,
  HardDrive,
  FileText,
  Github,
  Gitlab,
  Table2,
  Calendar,
  type LucideIcon,
} from 'lucide-vue-next';

export type NodeCategory = 'trigger' | 'logic' | 'communication' | 'notification' | 'ai' | 'dolibarr' | 'saas' | 'universal';

/** Category palette tokens for CSS-only contexts (legend, minimap stroke). */
export const CATEGORY_COLORS: Record<NodeCategory, string> = {
  trigger: 'var(--knot-cat-trigger, #6366f1)',
  logic: 'var(--knot-cat-logic, #f59e0b)',
  communication: 'var(--knot-cat-communication, #0ea5e9)',
  notification: 'var(--knot-cat-notification, #ec4899)',
  ai: 'var(--knot-cat-ai, #a855f7)',
  dolibarr: 'var(--knot-cat-dolibarr, #0d9488)',
  saas: 'var(--knot-cat-saas, #8b5cf6)',
  universal: 'var(--knot-cat-universal, #64748b)',
};

/** Hex fallbacks aligned with css/knot-tokens.css — required for inline styles (gradients). */
export const CATEGORY_COLORS_HEX: Record<NodeCategory, string> = {
  trigger: '#6366f1',
  logic: '#f59e0b',
  communication: '#0ea5e9',
  notification: '#ec4899',
  ai: '#a855f7',
  dolibarr: '#0d9488',
  saas: '#8b5cf6',
  universal: '#64748b',
};

export function categoryColor(category: NodeCategory): string {
  return CATEGORY_COLORS[category] ?? CATEGORY_COLORS.universal;
}

export function categoryColorHex(category: NodeCategory): string {
  return CATEGORY_COLORS_HEX[category] ?? CATEGORY_COLORS_HEX.universal;
}

/** Build icon band gradient stops from a registry hex color. */
export function iconGradientStops(hex: string): { start: string; mid: string; end: string } {
  if (hex.startsWith('#') && hex.length === 7) {
    return { start: hex, mid: `${hex}99`, end: `${hex}66` };
  }
  return { start: hex, mid: hex, end: hex };
}

export interface NodeMeta {
  /** Stable identifier matching backend connector id */
  id: string;
  /** Display name */
  label: string;
  /** Category — drives left/right handle color */
  category: NodeCategory;
  /** Lucide icon component */
  icon: LucideIcon;
  /** Hex/CSS variable for the icon background */
  color: string;
  /** Short helper text */
  description: string;
}

export const NODE_REGISTRY: Record<string, NodeMeta> = {
  // Triggers
  'trigger.manual': {
    id: 'trigger.manual', label: 'Manual', category: 'trigger',
    icon: Play, color: '#6366f1',
    description: 'Run on demand from the editor or API',
  },
  'trigger.webhook': {
    id: 'trigger.webhook', label: 'Webhook', category: 'trigger',
    icon: Webhook, color: '#8b5cf6',
    description: 'Listen to incoming HTTP events',
  },
  'trigger.cron': {
    id: 'trigger.cron', label: 'Schedule', category: 'trigger',
    icon: Clock, color: '#0ea5e9',
    description: 'Run on a cron schedule',
  },
  'trigger.dolibarr_event': {
    id: 'trigger.dolibarr_event', label: 'Dolibarr Event', category: 'trigger',
    icon: Database, color: '#0d9488',
    description: 'Run when Dolibarr emits a native event',
  },
  'trigger.stripe_webhook': {
    id: 'trigger.stripe_webhook', label: 'Stripe Webhook', category: 'trigger',
    icon: CreditCard, color: '#635bff',
    description: 'Listen to Stripe events with HMAC verification',
  },
  'trigger.shopify_webhook': {
    id: 'trigger.shopify_webhook', label: 'Shopify Webhook', category: 'trigger',
    icon: ShoppingCart, color: '#95bf47',
    description: 'Listen to Shopify topics with X-Shopify-Hmac-Sha256 verification',
  },

  // Logic
  'logic.set': {
    id: 'logic.set', label: 'Set', category: 'logic',
    icon: Layers, color: '#10b981',
    description: 'Define or override JSON fields',
  },
  'logic.filter': {
    id: 'logic.filter', label: 'Filter', category: 'logic',
    icon: GitBranch, color: '#22c55e',
    description: 'Pass or block items based on one condition',
  },
  'logic.if': {
    id: 'logic.if', label: 'If / Else', category: 'logic',
    icon: GitBranch, color: '#f59e0b',
    description: 'Route on boolean conditions',
  },
  'logic.switch': {
    id: 'logic.switch', label: 'Switch', category: 'logic',
    icon: GitBranch, color: '#f59e0b',
    description: 'Pick a branch from N cases',
  },
  'logic.merge': {
    id: 'logic.merge', label: 'Merge', category: 'logic',
    icon: GitMerge, color: '#14b8a6',
    description: 'Combine multiple branches into one',
  },
  'logic.wait': {
    id: 'logic.wait', label: 'Wait', category: 'logic',
    icon: Hourglass, color: '#94a3b8',
    description: 'Pause execution for N seconds (≤ 60s)',
  },
  'logic.execute_workflow': {
    id: 'logic.execute_workflow', label: 'Execute Workflow', category: 'logic',
    icon: Workflow, color: '#7c3aed',
    description: 'Run another active workflow and reuse its output',
  },
  'logic.stop_error': {
    id: 'logic.stop_error', label: 'Stop & Error', category: 'logic',
    icon: OctagonX, color: '#ef4444',
    description: 'Stop execution with a clear custom error',
  },
  'logic.respond_webhook': {
    id: 'logic.respond_webhook', label: 'Respond to Webhook', category: 'logic',
    icon: Webhook, color: '#8b5cf6',
    description: 'Define a custom HTTP response for webhook flows',
  },
  'logic.approval_wait': {
    id: 'logic.approval_wait', label: 'Approval', category: 'logic',
    icon: Inbox, color: '#f59e0b',
    description: 'Pause for a human approval decision',
  },
  'logic.json': {
    id: 'logic.json', label: 'JSON', category: 'logic',
    icon: Braces, color: '#22c55e',
    description: 'Parse, stringify or extract from JSON',
  },
  'logic.loop': {
    id: 'logic.loop', label: 'Loop', category: 'logic',
    icon: Layers, color: '#14b8a6',
    description: 'Iterate over item batches',
  },
  'logic.while': {
    id: 'logic.while', label: 'While', category: 'logic',
    icon: GitBranch, color: '#f97316',
    description: 'Guarded loop marker with max iterations',
  },
  'logic.split': {
    id: 'logic.split', label: 'Split', category: 'logic',
    icon: GitBranch, color: '#06b6d4',
    description: 'Split arrays into chunks',
  },
  'logic.array': {
    id: 'logic.array', label: 'Array', category: 'logic',
    icon: Braces, color: '#22c55e',
    description: 'Array length, reverse, unique, first and last',
  },
  'logic.html': {
    id: 'logic.html', label: 'HTML', category: 'logic',
    icon: Braces, color: '#ec4899',
    description: 'Extract values from HTML with XPath',
  },
  'logic.xml': {
    id: 'logic.xml', label: 'XML', category: 'logic',
    icon: Braces, color: '#8b5cf6',
    description: 'Extract values from XML with XPath',
  },
  'logic.crypto': {
    id: 'logic.crypto', label: 'Crypto', category: 'logic',
    icon: Hash, color: '#64748b',
    description: 'Hash, HMAC, base64, URL encode and UUID',
  },
  'logic.string': {
    id: 'logic.string', label: 'String', category: 'logic',
    icon: Type, color: '#3b82f6',
    description: 'Transform strings (case, replace, slice…)',
  },
  'logic.number': {
    id: 'logic.number', label: 'Number', category: 'logic',
    icon: Hash, color: '#0ea5e9',
    description: 'Arithmetic, rounding and formatting',
  },
  'logic.date': {
    id: 'logic.date', label: 'Date', category: 'logic',
    icon: CalendarDays, color: '#6366f1',
    description: 'Parse, format and shift datetimes',
  },

  // Communication
  'action.email': {
    id: 'action.email', label: 'Send Email', category: 'communication',
    icon: Mail, color: '#ec4899',
    description: 'Send email via Dolibarr SMTP (included in Knot Core — no Pro Pack)',
  },
  'action.slack': {
    id: 'action.slack', label: 'Slack', category: 'communication',
    icon: MessageSquare, color: '#a855f7',
    description: 'Post a message to a Slack webhook',
  },
  'action.discord': {
    id: 'action.discord', label: 'Discord', category: 'communication',
    icon: MessageSquare, color: '#5865f2',
    description: 'Post a message to a Discord webhook',
  },
  'action.telegram': {
    id: 'action.telegram', label: 'Telegram', category: 'communication',
    icon: Send, color: '#0ea5e9',
    description: 'Send a message via Telegram bot',
  },
  'action.twilio_sms': {
    id: 'action.twilio_sms', label: 'Twilio SMS', category: 'communication',
    icon: Phone, color: '#f22f46',
    description: 'Send a text message via Twilio Programmable SMS',
  },
  'action.ovh_sms': {
    id: 'action.ovh_sms', label: 'OVH SMS', category: 'communication',
    icon: Phone, color: '#123f6d',
    description: 'Send a text message via OVHcloud SMS API',
  },
  'action.whatsapp_cloud': {
    id: 'action.whatsapp_cloud', label: 'WhatsApp Cloud', category: 'communication',
    icon: MessageSquare, color: '#25d366',
    description: 'Send a WhatsApp message via Meta Cloud API (Business)',
  },
  'action.whatsapp_twilio': {
    id: 'action.whatsapp_twilio', label: 'WhatsApp (Twilio)', category: 'communication',
    icon: MessageSquare, color: '#075e54',
    description: 'Send a WhatsApp message through a Twilio sender',
  },

  'notification.alert': {
    id: 'notification.alert', label: 'Audit log entry (does not send)', category: 'notification',
    icon: BellRing, color: '#ea580c',
    description: 'Audit-only: writes to llx_knot_audit_log. For SMTP use action.email (Core); for Slack/Discord/webhooks use notification.alert_fanout (Pro Pack).',
  },

  // SaaS
  'action.stripe': {
    id: 'action.stripe', label: 'Stripe', category: 'saas',
    icon: CreditCard, color: '#635bff',
    description: 'Customers, charges, invoices and subscriptions on Stripe',
  },
  'action.woocommerce': {
    id: 'action.woocommerce', label: 'WooCommerce', category: 'saas',
    icon: ShoppingCart, color: '#7f54b3',
    description: 'Read & write WooCommerce orders, products and customers',
  },
  'action.sftp': {
    id: 'action.sftp', label: 'SFTP', category: 'saas',
    icon: HardDrive, color: '#0f766e',
    description: 'Upload, download or list files on an SFTP server',
  },
  'action.shopify': {
    id: 'action.shopify', label: 'Shopify', category: 'saas',
    icon: ShoppingCart, color: '#95bf47',
    description: 'Read & write Shopify orders, products and customers',
  },
  'action.prestashop': {
    id: 'action.prestashop', label: 'PrestaShop', category: 'saas',
    icon: ShoppingCart, color: '#df0067',
    description: 'PrestaShop Webservice (orders, products, customers, ...)',
  },
  'action.notion': {
    id: 'action.notion', label: 'Notion', category: 'saas',
    icon: FileText, color: '#000000',
    description: 'Pages, databases, blocks and search via Notion API',
  },
  'action.airtable': {
    id: 'action.airtable', label: 'Airtable', category: 'saas',
    icon: Table2, color: '#fcb400',
    description: 'List, create or update Airtable records',
  },
  'action.github': {
    id: 'action.github', label: 'GitHub', category: 'saas',
    icon: Github, color: '#181717',
    description: 'Issues, pull requests, releases and Actions dispatches',
  },
  'action.gitlab': {
    id: 'action.gitlab', label: 'GitLab', category: 'saas',
    icon: Gitlab, color: '#fc6d26',
    description: 'Issues, MRs, pipelines and releases (cloud or self-hosted)',
  },
  'action.google_sheets': {
    id: 'action.google_sheets', label: 'Google Sheets', category: 'saas',
    icon: Table2, color: '#0f9d58',
    description: 'Read, append or update spreadsheet ranges',
  },
  'action.google_drive': {
    id: 'action.google_drive', label: 'Google Drive', category: 'saas',
    icon: HardDrive, color: '#4285f4',
    description: 'List, upload, copy, share or delete Drive files',
  },
  'action.gmail': {
    id: 'action.gmail', label: 'Gmail', category: 'saas',
    icon: Mail, color: '#ea4335',
    description: 'Send messages and read mailbox via Gmail API',
  },
  'action.google_calendar': {
    id: 'action.google_calendar', label: 'Google Calendar', category: 'saas',
    icon: Calendar, color: '#4285f4',
    description: 'Create, update or query Calendar events and freebusy',
  },

  // AI
  'action.ai_openai': {
    id: 'action.ai_openai', label: 'OpenAI Chat', category: 'ai',
    icon: Brain, color: '#10b981',
    description: 'Call OpenAI / OpenAI-compatible LLMs',
  },
  'action.ai_anthropic': {
    id: 'action.ai_anthropic', label: 'Anthropic Chat', category: 'ai',
    icon: Brain, color: '#f97316',
    description: 'Call Anthropic Messages API',
  },
  'action.ai_ollama': {
    id: 'action.ai_ollama', label: 'Ollama (local)', category: 'ai',
    icon: Bot, color: '#0d9488',
    description: 'Local LLM via Ollama (no cloud)',
  },
  'action.ai_mistral': {
    id: 'action.ai_mistral', label: 'Mistral Chat', category: 'ai',
    icon: Brain, color: '#f97316',
    description: 'Call Mistral chat completions',
  },
  'action.ai_gemini': {
    id: 'action.ai_gemini', label: 'Gemini Chat', category: 'ai',
    icon: Brain, color: '#4285f4',
    description: 'Call Google Gemini generateContent',
  },

  // Universal
  'action.http': {
    id: 'action.http', label: 'HTTP Request', category: 'universal',
    icon: Globe, color: '#3b82f6',
    description: 'Call any REST/JSON endpoint',
  },

  // Dolibarr
  'dolibarr.object': {
    id: 'dolibarr.object', label: 'Dolibarr Object', category: 'dolibarr',
    icon: Database, color: '#0d9488',
    description: 'Create, fetch, update or manage Dolibarr objects',
  },
  'dolibarr.read_object': {
    id: 'dolibarr.read_object', label: 'Read Dolibarr Object', category: 'dolibarr',
    icon: Database, color: '#0d9488',
    description: 'Fetch a Dolibarr object by id',
  },
  'dolibarr.sql_query': {
    id: 'dolibarr.sql_query', label: 'SQL Query', category: 'dolibarr',
    icon: Database, color: '#dc2626',
    description: 'Admin-only sandboxed SELECT query',
  },
  'dolibarr.specialized': {
    id: 'dolibarr.specialized', label: 'Dolibarr Specialized', category: 'dolibarr',
    icon: Database, color: '#f59e0b',
    description: 'High-value invoice/order/stock operations',
  },
};

const FALLBACK: NodeMeta = {
  id: 'unknown',
  label: 'Unknown',
  category: 'universal',
  icon: Sparkles,
  color: '#64748b',
  description: 'Unknown node type',
};

export function resolveNodeMeta(type?: string): NodeMeta {
  if (!type) return FALLBACK;
  return NODE_REGISTRY[type] ?? { ...FALLBACK, id: type, label: type };
}

export const PALETTE_SECTIONS: { titleKey: string; category: NodeCategory; ids: string[] }[] = [
  {
    titleKey: 'editor.paletteSections.trigger', category: 'trigger',
    ids: ['trigger.manual', 'trigger.webhook', 'trigger.cron', 'trigger.dolibarr_event', 'trigger.stripe_webhook', 'trigger.shopify_webhook'],
  },
  {
    titleKey: 'editor.paletteSections.logic', category: 'logic',
    ids: [
      'logic.set', 'logic.filter', 'logic.if', 'logic.switch', 'logic.merge', 'logic.wait',
      'logic.execute_workflow', 'logic.stop_error', 'logic.respond_webhook', 'logic.approval_wait',
      'logic.loop', 'logic.while', 'logic.split', 'logic.array', 'logic.html', 'logic.xml', 'logic.crypto',
      'logic.json', 'logic.string', 'logic.number', 'logic.date',
    ],
  },
  {
    titleKey: 'editor.paletteSections.communication', category: 'communication',
    ids: ['action.email', 'action.slack', 'action.discord', 'action.telegram', 'action.twilio_sms', 'action.ovh_sms', 'action.whatsapp_cloud', 'action.whatsapp_twilio'],
  },
  {
    titleKey: 'editor.paletteSections.notification', category: 'notification',
    ids: ['notification.alert'],
  },
  {
    titleKey: 'editor.paletteSections.saas', category: 'saas',
    ids: [
      'action.stripe', 'action.woocommerce', 'action.shopify', 'action.prestashop', 'action.sftp',
      'action.notion', 'action.airtable', 'action.github', 'action.gitlab',
      'action.google_sheets', 'action.google_drive', 'action.gmail', 'action.google_calendar',
    ],
  },
  {
    titleKey: 'editor.paletteSections.ai', category: 'ai',
    ids: ['action.ai_openai', 'action.ai_anthropic', 'action.ai_mistral', 'action.ai_gemini', 'action.ai_ollama'],
  },
  {
    titleKey: 'editor.paletteSections.dolibarr', category: 'dolibarr',
    ids: ['dolibarr.object', 'dolibarr.specialized', 'dolibarr.read_object', 'dolibarr.sql_query'],
  },
  {
    titleKey: 'editor.paletteSections.universal', category: 'universal',
    ids: ['action.http'],
  },
];
