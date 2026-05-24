<?php

declare(strict_types=1);

namespace Knot\Cron;

use DateTimeImmutable;
use DateTimeZone;
use InvalidArgumentException;

/**
 * Tiny POSIX cron expression evaluator.
 *
 * Supports the 5-field standard form (minute hour day-of-month month day-of-week)
 * with `*`, lists `1,15,30`, ranges `1-5`, and steps `* /5` / `0-30/2`. The
 * day-of-month and day-of-week fields are OR-ed when both are restricted, to
 * match the historical Vixie cron behavior used by Dolibarr.
 *
 * The evaluator is intentionally dependency-free so we can ship it inside the
 * module zip without pulling a Composer vendor tree.
 */
final class CronEvaluator
{
    /**
     * Compute the next Unix timestamp at or after $afterTs that matches the
     * given cron expression in the given timezone. Caps the search to ~1 year
     * in the future so an unmatched expression cannot loop forever; returns
     * $afterTs + 60 as a conservative fallback when no match is found.
     */
    public static function nextRunAfter(string $expression, string $timezone, int $afterTs): int
    {
        $expression = trim($expression);
        if ($expression === '') {
            return $afterTs + 60;
        }

        try {
            $tz = new DateTimeZone($timezone !== '' ? $timezone : 'UTC');
        } catch (\Throwable $e) {
            $tz = new DateTimeZone('UTC');
        }

        $parts = preg_split('/\s+/', $expression) ?: [];
        if (count($parts) !== 5) {
            return $afterTs + 60;
        }

        try {
            $minutes = self::expandField($parts[0], 0, 59);
            $hours = self::expandField($parts[1], 0, 23);
            $doms = self::expandField($parts[2], 1, 31);
            $months = self::expandField($parts[3], 1, 12);
            $dows = self::expandField($parts[4], 0, 6, true);
        } catch (InvalidArgumentException $e) {
            return $afterTs + 60;
        }

        $domRestricted = $parts[2] !== '*' && $parts[2] !== '?';
        $dowRestricted = $parts[4] !== '*' && $parts[4] !== '?';

        $cursor = (new DateTimeImmutable('@' . $afterTs))
            ->setTimezone($tz)
            ->modify('+1 minute')
            ->setTime((int) (new DateTimeImmutable('@' . ($afterTs + 60)))->setTimezone($tz)->format('H'), (int) (new DateTimeImmutable('@' . ($afterTs + 60)))->setTimezone($tz)->format('i'), 0);

        for ($i = 0; $i < 527040; $i++) {
            $mon = (int) $cursor->format('n');
            if (!in_array($mon, $months, true)) {
                $cursor = $cursor->modify('first day of next month')->setTime(0, 0, 0);
                continue;
            }

            $dom = (int) $cursor->format('j');
            $dow = (int) $cursor->format('w');
            $domOk = in_array($dom, $doms, true);
            $dowOk = in_array($dow, $dows, true);
            $dayOk = match (true) {
                $domRestricted && $dowRestricted => $domOk || $dowOk,
                $domRestricted => $domOk,
                $dowRestricted => $dowOk,
                default => true,
            };
            if (!$dayOk) {
                $cursor = $cursor->modify('+1 day')->setTime(0, 0, 0);
                continue;
            }

            $hour = (int) $cursor->format('G');
            if (!in_array($hour, $hours, true)) {
                $cursor = $cursor->modify('+1 hour')->setTime((int) $cursor->modify('+1 hour')->format('H'), 0, 0);
                continue;
            }

            $minute = (int) $cursor->format('i');
            if (!in_array($minute, $minutes, true)) {
                $cursor = $cursor->modify('+1 minute');
                continue;
            }

            return $cursor->getTimestamp();
        }

        return $afterTs + 60;
    }

    /**
     * Expand a single cron field to a sorted unique list of integer values.
     *
     * @return array<int, int>
     */
    private static function expandField(string $field, int $min, int $max, bool $isDow = false): array
    {
        $field = trim($field);
        if ($field === '' || $field === '?') {
            $field = '*';
        }

        $values = [];
        foreach (explode(',', $field) as $chunk) {
            $chunk = trim($chunk);
            if ($chunk === '') {
                continue;
            }

            $step = 1;
            if (str_contains($chunk, '/')) {
                [$range, $stepRaw] = explode('/', $chunk, 2);
                $step = max(1, (int) $stepRaw);
            } else {
                $range = $chunk;
            }

            if ($range === '*') {
                $start = $min;
                $end = $max;
            } elseif (str_contains($range, '-')) {
                [$lo, $hi] = explode('-', $range, 2);
                $start = (int) $lo;
                $end = (int) $hi;
            } else {
                $start = (int) $range;
                $end = $start;
            }

            if ($isDow && $start === 7) {
                $start = 0;
            }
            if ($isDow && $end === 7) {
                $end = 0;
            }

            if ($start < $min || $end > $max || $start > $end) {
                throw new InvalidArgumentException(sprintf('Out of range cron field segment "%s"', $chunk));
            }

            for ($v = $start; $v <= $end; $v += $step) {
                $values[$v] = true;
            }
        }

        $list = array_map('intval', array_keys($values));
        sort($list);
        return $list;
    }
}
