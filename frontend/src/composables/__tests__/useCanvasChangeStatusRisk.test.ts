import { describe, expect, it } from 'vitest';
import type { DolibarrVerb } from '@/lib/api';
import {
  mergeVerbAndProbabilityRisk,
  parseStrictPositiveIntId,
  resolveChangeStatusVerb,
} from '../useCanvasChangeStatusRisk';
import {
  OBJECT_REGISTRY_DISCOVERY_UNVERIFIED,
  OBJECT_REGISTRY_ALL_EXCEPT_UNVERIFIED,
} from '@/lib/objectRegistryMode';

describe('parseStrictPositiveIntId', () => {
  it('accepts digit-only positive ints', () => {
    expect(parseStrictPositiveIntId('42')).toBe(42);
    expect(parseStrictPositiveIntId(7)).toBe(7);
  });
  it('rejects expressions and placeholders', () => {
    expect(parseStrictPositiveIntId('{{$json.id}}')).toBeNull();
    expect(parseStrictPositiveIntId('12a')).toBeNull();
    expect(parseStrictPositiveIntId('')).toBeNull();
    expect(parseStrictPositiveIntId(0)).toBeNull();
  });
});

describe('resolveChangeStatusVerb', () => {
  it('uses custom verb in discovery_unverified when set', () => {
    expect(
      resolveChangeStatusVerb({
        objectRegistryMode: OBJECT_REGISTRY_DISCOVERY_UNVERIFIED,
        statusMethod: 'valid',
        statusMethodCustom: 'myVerb',
      }),
    ).toBe('myVerb');
  });
  it('falls back to statusMethod', () => {
    expect(
      resolveChangeStatusVerb({
        objectRegistryMode: OBJECT_REGISTRY_ALL_EXCEPT_UNVERIFIED,
        statusMethod: 'setpaid',
      }),
    ).toBe('setpaid');
  });
});

describe('mergeVerbAndProbabilityRisk', () => {
  const okVerb: DolibarrVerb = {
    name: 'valid',
    parameters: [],
    maturity: 'verified',
    pattern: '',
    simulateError: null,
  };
  it('prefers simulateError over probability', () => {
    const out = mergeVerbAndProbabilityRisk(
      { statusMethod: 'valid' },
      [{ ...okVerb, simulateError: 'SIM_FAIL' }],
      [{ method: 'valid', probability: 'low' }],
    );
    expect(out.severity).toBe('error');
    expect(out.detail).toBe('SIM_FAIL');
  });
  it('warns on low probability when no simulate error', () => {
    const out = mergeVerbAndProbabilityRisk(
      { statusMethod: 'validate' },
      [{ ...okVerb, name: 'validate' }],
      [{ method: 'validate', probability: 'low' }],
    );
    expect(out.severity).toBe('warning');
  });
  it('returns none when OK', () => {
    const out = mergeVerbAndProbabilityRisk(
      { statusMethod: 'validate' },
      [{ ...okVerb, name: 'validate' }],
      [{ method: 'validate', probability: 'high' }],
    );
    expect(out.severity).toBe('none');
  });
});
