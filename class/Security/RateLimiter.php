<?php

/* Copyright (C) 2026 Knot — GPL-3.0-or-later */

declare(strict_types=1);

namespace Knot\Security;

/**
 * Token-bucket / fixed-window rate limiter persisted in a generic key-value table.
 * Lightweight implementation backed by Dolibarr's transient cache fallback when
 * a dedicated table is not available.
 */
final class RateLimiter
{
    public function __construct(private readonly \DoliDB $db)
    {
    }

    /**
     * Returns true when the request is allowed and increments the counter.
     */
    public function consume(string $bucket, int $maxPerMinute): bool
    {
        if ($maxPerMinute <= 0) {
            return true;
        }
        $window = (int) floor(time() / 60);
        $tableExists = $this->ensureTable();
        if (!$tableExists) {
            return true;
        }
        $key = $this->db->escape(substr(hash('sha256', $bucket), 0, 64));
        $sql = "SELECT window_start, hits FROM " . MAIN_DB_PREFIX . "knot_rate_limit WHERE bucket = '" . $key . "' FOR UPDATE";
        $res = $this->db->query($sql);
        if ($res && $obj = $this->db->fetch_object($res)) {
            $start = (int) $obj->window_start;
            $hits = (int) $obj->hits;
            if ($start === $window) {
                if ($hits >= $maxPerMinute) {
                    return false;
                }
                $this->db->query(
                    "UPDATE " . MAIN_DB_PREFIX . "knot_rate_limit SET hits = hits + 1 WHERE bucket = '" . $key . "'",
                );
                return true;
            }
            $this->db->query(
                "UPDATE " . MAIN_DB_PREFIX . "knot_rate_limit SET window_start = " . $window . ", hits = 1 WHERE bucket = '" . $key . "'",
            );
            return true;
        }
        $this->db->query(
            "INSERT INTO " . MAIN_DB_PREFIX . "knot_rate_limit (bucket, window_start, hits) VALUES ('" . $key . "', " . $window . ", 1)",
        );
        return true;
    }

    public function retryAfterSeconds(): int
    {
        return 60 - ((int) (time() % 60));
    }

    private function ensureTable(): bool
    {
        static $checked = null;
        if ($checked !== null) {
            return $checked;
        }
        $sql = 'CREATE TABLE IF NOT EXISTS ' . MAIN_DB_PREFIX . 'knot_rate_limit ('
            . 'bucket VARCHAR(64) NOT NULL PRIMARY KEY, '
            . 'window_start INT NOT NULL DEFAULT 0, '
            . 'hits INT NOT NULL DEFAULT 0'
            . ') ENGINE=InnoDB';
        $checked = (bool) $this->db->query($sql);
        return $checked;
    }
}
