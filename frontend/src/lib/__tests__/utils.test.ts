import { describe, expect, it } from 'vitest';
import { cn } from '../utils';

describe('cn', () => {
  it('joins multiple classes', () => {
    expect(cn('px-2', 'py-1')).toBe('px-2 py-1');
  });

  it('deduplicates conflicting Tailwind classes (last wins)', () => {
    expect(cn('px-2 px-4')).toBe('px-4');
  });

  it('drops falsy values', () => {
    expect(cn('a', false, undefined, null, '', 'b')).toBe('a b');
  });

  it('supports object syntax', () => {
    expect(cn({ active: true, disabled: false })).toBe('active');
  });
});
