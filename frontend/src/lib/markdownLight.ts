/**
 * Minimal markdown subset for changelog notes (no dependency).
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

export function parseMarkdownLight(source: string): string {
  const escaped = source
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;');
  return escaped
    .replace(/^### (.+)$/gm, '<h4 class="k-font-semibold k-mt-2">$1</h4>')
    .replace(/^## (.+)$/gm, '<h3 class="k-font-semibold k-mt-2">$1</h3>')
    .replace(/^# (.+)$/gm, '<h2 class="k-font-bold k-mt-2">$1</h2>')
    .replace(/\*\*(.+?)\*\*/g, '<strong>$1</strong>')
    .replace(/\*(.+?)\*/g, '<em>$1</em>')
    .replace(/^- (.+)$/gm, '<li>$1</li>')
    .replace(/(<li>[\s\S]*?<\/li>\n?)+/g, (block) => `<ul class="k-list-disc k-list-inside k-space-y-1">${block}</ul>`)
    .replace(/\n\n+/g, '</p><p class="k-mt-2">')
    .replace(/^(?!<[hul])/gm, (line) => (line.trim() === '' ? '' : line))
    .replace(/^([^<].*)$/gm, '<p class="k-text-sm k-text-knot-text-muted">$1</p>');
}
