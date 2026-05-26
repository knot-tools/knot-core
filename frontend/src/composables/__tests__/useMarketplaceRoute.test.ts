/**
 * Copyright (C) 2026 Knot — GPL-3.0-or-later
 */

import { describe, expect, it } from 'vitest';

import {
  normalizeMarketplaceHashPath,
  parseMarketplacePath,
  parseMarketplaceQuery,
  splitMarketplaceHash,
  isValidMarketplaceSlug,
  serializeMarketplaceQuery,
} from '../useMarketplaceRoute';

describe('normalizeMarketplaceHashPath', () => {
  it('lowercases slug segments', () => {
    expect(normalizeMarketplaceHashPath('#/Product/Foo-Bar')).toBe('/product/foo-bar');
  });

  it('strips query before normalizing', () => {
    expect(normalizeMarketplaceHashPath('#/Packs?q=hello')).toBe('/packs');
  });

  it('treats empty hash as home', () => {
    expect(normalizeMarketplaceHashPath('#/')).toBe('/');
  });

  it('prepends slash when omitted', () => {
    expect(normalizeMarketplaceHashPath('#product/hello')).toBe('/product/hello');
  });
});

describe('parseMarketplaceQuery', () => {
  it('reads q, tab and sort params', () => {
    expect(parseMarketplaceQuery('q=invoice&tab=packs&sort=label')).toEqual({
      q: 'invoice',
      tab: 'packs',
      sort: 'label',
    });
  });
});

describe('splitMarketplaceHash', () => {
  it('splits path and inline hash query', () => {
    expect(splitMarketplaceHash('#/search?q=crm&sort=label')).toEqual({
      path: '/search',
      query: { q: 'crm', sort: 'label' },
    });
  });
});

describe('serializeMarketplaceQuery', () => {
  it('round-trips known keys', () => {
    expect(serializeMarketplaceQuery({ q: 'a', tab: 'templates' })).toBe('?q=a&tab=templates');
  });
});

describe('parseMarketplacePath', () => {
  it('parses product routes when slug validates', () => {
    expect(parseMarketplacePath('/product/knot-demo')).toEqual({
      kind: 'product',
      slug: 'knot-demo',
      query: {},
    });
  });

  it('parses listing routes', () => {
    expect(parseMarketplacePath('/packs')?.kind).toBe('packs');
    expect(parseMarketplacePath('/templates')?.kind).toBe('templates');
    expect(parseMarketplacePath('/news')?.kind).toBe('news');
    expect(parseMarketplacePath('/search')?.kind).toBe('search');
  });

  it('parses category and collection slugs', () => {
    expect(parseMarketplacePath('/category/automation')).toEqual({
      kind: 'category',
      slug: 'automation',
      query: {},
    });
    expect(parseMarketplacePath('/collection/starters')?.kind).toBe('collection');
  });

  it('parses template and news identifiers', () => {
    expect(parseMarketplacePath('/template/hello-world')?.kind).toBe('template');
    expect(parseMarketplacePath('/news/weekly-roundup')?.kind).toBe('news');
  });

  it('rejects invalid slug characters', () => {
    expect(parseMarketplacePath('/product/hello_world')).toBeNull();
  });

  it('rejects malformed depth', () => {
    expect(parseMarketplacePath('/product')).toBeNull();
  });

  it('merges query onto parsed snapshot', () => {
    expect(parseMarketplacePath('/search', { q: 'foo', tab: 'packs' })).toEqual({
      kind: 'search',
      slug: null,
      query: { q: 'foo', tab: 'packs' },
    });
  });
});

describe('isValidMarketplaceSlug', () => {
  it('honours the Knot slug grammar', () => {
    expect(isValidMarketplaceSlug('some-pack-12')).toBe(true);
    expect(isValidMarketplaceSlug('bad_slug')).toBe(false);
    expect(isValidMarketplaceSlug('BAD')).toBe(false);
  });
});
