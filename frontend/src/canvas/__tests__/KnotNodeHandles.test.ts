import { describe, expect, it } from 'vitest';
import { defaultOutputsForCategory, layoutForOutput } from '@/lib/edgeSemantics';

describe('KnotNode handle layout', () => {
  it('exposes true/false positions for branch outputs', () => {
    expect(layoutForOutput('true', 'logic', '#000').position).toBe('right-top');
    expect(layoutForOutput('false', 'logic', '#000').position).toBe('right-bottom');
  });

  it('falls back to main+error for action category', () => {
    const outs = defaultOutputsForCategory('communication');
    expect(outs.map((o) => o.id)).toEqual(['main', 'error']);
  });
});
