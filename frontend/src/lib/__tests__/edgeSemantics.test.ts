import { describe, expect, it } from 'vitest';
import { deriveEdgeType, edgeStrokeColor, handleColor, buildKnotEdgeFields } from '../edgeSemantics';

describe('edgeSemantics', () => {
  it('derives edge type from source handle', () => {
    expect(deriveEdgeType('true')).toBe('true');
    expect(deriveEdgeType('false')).toBe('false');
    expect(deriveEdgeType('error')).toBe('error');
    expect(deriveEdgeType('main')).toBe('success');
    expect(deriveEdgeType(null)).toBe('success');
  });

  it('maps semantic types to stroke colors', () => {
    expect(edgeStrokeColor('true')).toBe(handleColor('true'));
    expect(edgeStrokeColor('error')).toBe(handleColor('error'));
  });

  it('builds knot edge fields from source handle', () => {
    const fields = buildKnotEdgeFields('true', false, 'arrowclosed');
    expect(fields.type).toBe('knot');
    expect(fields.data.type).toBe('true');
    expect(fields.animated).toBe(false);
    expect(fields.markerEnd.color).toBe(handleColor('true'));
  });
});
