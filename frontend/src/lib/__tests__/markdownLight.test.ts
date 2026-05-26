/**
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest';
import { parseMarkdownLight } from '../markdownLight';

describe('parseMarkdownLight', () => {
  it('renders headings and emphasis', () => {
    const html = parseMarkdownLight('# Title\n\n**bold** and *em*');
    expect(html).toContain('<h2');
    expect(html).toContain('<strong>bold</strong>');
    expect(html).toContain('<em>em</em>');
  });

  it('renders bullet lists', () => {
    const html = parseMarkdownLight('- one\n- two');
    expect(html).toContain('<ul');
    expect(html).toContain('<li>one</li>');
  });
});
