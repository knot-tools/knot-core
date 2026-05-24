<?php

declare(strict_types=1);

namespace Knot\Errors;

use Throwable;

/**
 * Serializes Knot-shaped execution failures for storage in llx_knot_execution.error_payload.
 *
 * Keeps payloads bounded for TEXT column size and avoids embedding oversized context blobs.
 */
final class ExecutionErrorPayloadCodec
{
    public const MAX_STORAGE_BYTES = 62000;

    private const MAX_TECHNICAL_LEN = 12000;

    private const MAX_CONTEXT_STRING_LEN = 2000;

    private const MAX_CONTEXT_KEYS = 24;

    /**
     * Builds the Knot payload array persisted async failures share with synchronous APIs (ADR-007).
     *
     * @return array<string, mixed>
     */
    public static function fromThrowable(Throwable $throwable): array
    {
        if ($throwable instanceof KnotError) {
            return self::trimArray($throwable->toArray());
        }

        $knot = (new DolibarrErrorTranslator())->translate($throwable, [
            'endpoint' => 'cron_worker',
        ]);

        return self::trimArray($knot->toArray());
    }

    /**
     * JSON suitable for TEXT storage, or null when serialization fails entirely.
     *
     * @param array<string, mixed>|null $payload
     */
    public static function encode(?array $payload): ?string
    {
        if ($payload === null) {
            return null;
        }

        try {
            $trimmed = self::trimArray($payload);
            $json = json_encode($trimmed, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            if (strlen($json) <= self::MAX_STORAGE_BYTES) {
                return $json;
            }

            return self::encodeMinimalFallback($trimmed);
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param array<string, mixed> $payload */
    private static function encodeMinimalFallback(array $payload): string
    {
        $minimal = [
            'code' => (string) ($payload['code'] ?? 'KNOT_UNEXPECTED'),
            'user_message' => mb_substr((string) ($payload['user_message'] ?? ''), 0, 2000),
            'technical_message' => mb_substr((string) ($payload['technical_message'] ?? ''), 0, self::MAX_TECHNICAL_LEN),
            'doc_link' => isset($payload['doc_link']) && $payload['doc_link'] !== ''
                ? (string) $payload['doc_link']
                : null,
            'severity' => (string) ($payload['severity'] ?? 'error'),
            'suggestion' => isset($payload['suggestion'])
                ? mb_substr((string) $payload['suggestion'], 0, 2000)
                : null,
            'context' => ['truncated' => true],
        ];

        $json = json_encode($minimal, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        while (strlen($json) > self::MAX_STORAGE_BYTES && mb_strlen($minimal['technical_message']) > 200) {
            $minimal['technical_message'] = mb_substr((string) $minimal['technical_message'], 0, max(200, (int) (mb_strlen((string) $minimal['technical_message']) / 2)));
            $json = json_encode($minimal, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        }

        return $json;
    }

    /**
     * @param array<string, mixed> $arr
     *
     * @return array<string, mixed>
     */
    private static function trimArray(array $arr): array
    {
        if (isset($arr['technical_message'])) {
            $arr['technical_message'] = mb_substr((string) $arr['technical_message'], 0, self::MAX_TECHNICAL_LEN);
        }
        if (isset($arr['context']) && is_array($arr['context'])) {
            /** @var array<string, mixed> $ctx */
            $ctx = $arr['context'];
            $arr['context'] = self::trimContext($ctx);
        }

        return $arr;
    }

    /**
     * @param array<string, mixed> $ctx
     *
     * @return array<string, mixed>
     */
    private static function trimContext(array $ctx): array
    {
        $out = [];
        $n = 0;
        foreach ($ctx as $k => $v) {
            if ($n >= self::MAX_CONTEXT_KEYS) {
                $out['_truncated_keys'] = max(0, count($ctx) - self::MAX_CONTEXT_KEYS);
                break;
            }
            $key = (string) $k;
            if ($key === '') {
                continue;
            }
            if (is_string($v)) {
                $out[$key] = mb_substr($v, 0, self::MAX_CONTEXT_STRING_LEN);
            } elseif (is_scalar($v) || $v === null) {
                $out[$key] = $v;
            } else {
                $enc = json_encode($v, JSON_UNESCAPED_SLASHES);
                $out[$key] = $enc !== false
                    ? mb_substr($enc, 0, self::MAX_CONTEXT_STRING_LEN)
                    : '[unserializable]';
            }
            $n++;
        }

        return $out;
    }
}
