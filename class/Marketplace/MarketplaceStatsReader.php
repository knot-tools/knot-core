<?php

declare(strict_types=1);

namespace Knot\Marketplace;

use Knot\Repository\AbstractRepository;

/**
 * Aggregates lightweight Marketplace SPA analytics persisted as {@code marketplace.track} audit rows.
 */
final class MarketplaceStatsReader extends AbstractRepository
{
    /**
     * Top CTA click keys from tracked contexts (last {@code days} days, entity scoped).
     *
     * Aggregation uses normalized keys in this order when present:
     * {@code cta_slug} | {@code cta_id}|{@code route} | trimmed {@code href} prefix.
     *
     * @return list<array{key: string, count: positive-int}>
     */
    public function topCtaClickKeys(int $entity, int $days = 30, int $limit = 10): array
    {
        $days = max(1, min(366, $days));
        $limit = max(1, min(50, $limit));
        $threshold = gmdate('Y-m-d H:i:s', time() - $days * 86400);

        $sql = 'SELECT payload FROM ' . $this->table('audit_log')
            . " WHERE entity = " . (int) $entity
            . " AND action_type = 'marketplace.track'"
            . " AND created_at >= '" . $this->db->escape($threshold) . "'"
            . ' ORDER BY rowid DESC LIMIT 25000';

        $resql = $this->db->query($sql);
        if ($resql === false) {
            return [];
        }

        /** @var array<string, positive-int> $counts */
        $counts = [];

        while ($row = $this->db->fetch_object($resql)) {
            $raw = isset($row->payload) ? (string) $row->payload : '';
            if ($raw === '') {
                continue;
            }
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }
            if (($decoded['event'] ?? '') !== 'cta_click') {
                continue;
            }
            $ctx = $decoded['context'] ?? null;
            if (!is_array($ctx)) {
                continue;
            }
            $key = self::normalizeCtaAggregationKey($ctx);
            $counts[$key] = isset($counts[$key]) ? $counts[$key] + 1 : 1;
        }

        arsort($counts);
        /** @var list<array{key: string, count: positive-int}> $out */
        $out = [];
        foreach ($counts as $k => $c) {
            $out[] = ['key' => $k, 'count' => $c];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<string, scalar> $context
     */
    public static function normalizeCtaAggregationKey(array $context): string
    {
        if (isset($context['cta_slug']) && is_string($context['cta_slug'])) {
            $s = trim($context['cta_slug']);
            if ($s !== '') {
                return 'slug:' . mb_substr($s, 0, 96, 'UTF-8');
            }
        }
        $id = isset($context['cta_id']) && is_string($context['cta_id']) ? trim($context['cta_id']) : '';
        $route = isset($context['route']) && is_string($context['route']) ? trim($context['route']) : '';
        if ($id !== '' && $route !== '') {
            return 'id|' . mb_substr($id, 0, 64, 'UTF-8') . '|' . mb_substr($route, 0, 96, 'UTF-8');
        }
        if ($id !== '') {
            return 'id:' . mb_substr($id, 0, 128, 'UTF-8');
        }
        if (isset($context['href']) && is_string($context['href'])) {
            $h = trim($context['href']);
            if ($h !== '') {
                return 'href:' . mb_substr($h, 0, 160, 'UTF-8');
            }
        }

        return '(no_key)';
    }

    /**
     * Count product/template engagement signals keyed by slug (30-day window).
     *
     * @return array<string, positive-int>
     */
    public function installCountsBySlug(int $entity, int $days = 30): array
    {
        $days = max(1, min(366, $days));
        $threshold = gmdate('Y-m-d H:i:s', time() - $days * 86400);

        $sql = 'SELECT payload FROM ' . $this->table('audit_log')
            . " WHERE entity = " . (int) $entity
            . " AND action_type = 'marketplace.track'"
            . " AND created_at >= '" . $this->db->escape($threshold) . "'"
            . ' ORDER BY rowid DESC LIMIT 25000';

        $resql = $this->db->query($sql);
        if ($resql === false) {
            return [];
        }

        /** @var array<string, positive-int> $counts */
        $counts = [];
        $interestEvents = [
            'product_page_visit' => true,
            'template_instantiated' => true,
            'drawer.open' => true,
            'spotlight.click' => true,
        ];

        while ($row = $this->db->fetch_object($resql)) {
            $raw = isset($row->payload) ? (string) $row->payload : '';
            if ($raw === '') {
                continue;
            }
            /** @var mixed $decoded */
            $decoded = json_decode($raw, true);
            if (!is_array($decoded)) {
                continue;
            }
            $event = (string) ($decoded['event'] ?? '');
            if (!isset($interestEvents[$event])) {
                continue;
            }
            $ctx = $decoded['context'] ?? null;
            if (!is_array($ctx)) {
                continue;
            }
            $slug = isset($ctx['slug']) && is_string($ctx['slug']) ? trim($ctx['slug']) : '';
            if ($slug === '') {
                continue;
            }
            $counts[$slug] = isset($counts[$slug]) ? $counts[$slug] + 1 : 1;
        }

        return $counts;
    }

    /**
     * @return list<string> Slugs sorted by descending engagement count.
     */
    public function popularSlugs(int $entity, int $days = 30, int $limit = 10): array
    {
        $counts = $this->installCountsBySlug($entity, $days);
        arsort($counts);
        /** @var list<string> $out */
        $out = [];
        foreach (array_keys($counts) as $slug) {
            $out[] = $slug;
            if (count($out) >= max(1, min(50, $limit))) {
                break;
            }
        }

        return $out;
    }
}
