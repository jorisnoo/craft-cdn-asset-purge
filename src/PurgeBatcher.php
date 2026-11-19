<?php

namespace Noo\CraftCdnAssetPurge;

/**
 * Splits purge URLs into requests and works out retry delays, following
 * Bunny's purge rate limits.
 *
 * Compatible gateways accept up to 50 URLs per JSON request. Bunny's
 * per-account token bucket is separate per purge type, one token per URL. Exact URLs
 * burst to ~120 tokens and refill at 5/sec. Wildcard URLs (containing `*` or
 * ending in `/`) use a separate bucket that bursts to ~20 and refills at
 * 0.5/sec. A request that mixes both types is charged to both buckets, so a
 * batch only ever holds one type, sized below that type's burst.
 *
 * On a 429, Bunny's advertised wait can be far too short (~2s for a drained
 * wildcard bucket that needs ~30s), so delays are floored to the bucket's
 * refill window.
 */
final class PurgeBatcher
{
    /** Gateway batch limit, under the ~120 exact burst. */
    public const EXACT_BATCH_SIZE = 50;

    /** Under the ~20 wildcard burst. */
    public const WILDCARD_BATCH_SIZE = 15;

    /** 50 exact tokens at 5 per second. */
    public const EXACT_PACING_SECONDS = 10;

    /** 15 wildcard tokens at 0.5 per second. */
    public const WILDCARD_PACING_SECONDS = 30;

    /** Used for a 429 that advertises no wait. */
    public const DEFAULT_RATE_LIMIT_BACKOFF_SECONDS = 60;

    /** Cap on any single delay, including the CDN's advertised wait. */
    public const MAX_BACKOFF_SECONDS = 300;

    public static function isWildcard(string $url): bool
    {
        return str_contains($url, '*') || str_ends_with($url, '/');
    }

    /**
     * Splits URLs into single-type batches, exact batches first. Duplicate
     * and empty URLs are dropped.
     *
     * @param iterable<string|null> $urls
     * @return list<PurgeBatch>
     */
    public static function batches(iterable $urls): array
    {
        $exact = [];
        $wildcard = [];

        foreach ($urls as $url) {
            if ($url === null || $url === '') {
                continue;
            }

            if (self::isWildcard($url)) {
                $wildcard[$url] = $url;
            } else {
                $exact[$url] = $url;
            }
        }

        $batches = [];

        foreach (array_chunk(array_values($exact), self::EXACT_BATCH_SIZE) as $chunk) {
            $batches[] = new PurgeBatch($chunk, false);
        }

        foreach (array_chunk(array_values($wildcard), self::WILDCARD_BATCH_SIZE) as $chunk) {
            $batches[] = new PurgeBatch($chunk, true);
        }

        return $batches;
    }

    /**
     * Delay before retrying a batch after a server or connection error.
     * Grows with each attempt, from the batch's pacing window up to the cap.
     */
    public static function retryDelay(PurgeBatch $batch, int $attempt): int
    {
        return min($batch->pacingSeconds() * max(1, $attempt), self::MAX_BACKOFF_SECONDS);
    }

    /**
     * Delay before retrying a batch that was rate-limited (429). Honours the
     * `Retry-After` header, falling back to the body's `retry_after_seconds`,
     * capped, and never shorter than the batch's retry delay.
     */
    public static function rateLimitDelay(
        PurgeBatch $batch,
        int $attempt,
        ?string $retryAfterHeader,
        ?string $body,
        ?int $now = null,
    ): int {
        $advertised = self::parseRetryAfter($retryAfterHeader, $now) ?? self::parseRetryAfterBody($body);

        $wait = $advertised !== null && $advertised > 0
            ? min($advertised, self::MAX_BACKOFF_SECONDS)
            : self::DEFAULT_RATE_LIMIT_BACKOFF_SECONDS;

        return max($wait, self::retryDelay($batch, $attempt));
    }

    /** Parses a `Retry-After` header given in seconds or as an HTTP date. */
    private static function parseRetryAfter(?string $header, ?int $now): ?int
    {
        $header = trim((string) $header);

        if ($header === '') {
            return null;
        }

        if (is_numeric($header)) {
            return (int) ceil((float) $header);
        }

        $timestamp = strtotime($header);

        if ($timestamp === false) {
            return null;
        }

        return $timestamp - ($now ?? time());
    }

    private static function parseRetryAfterBody(?string $body): ?int
    {
        if ($body === null || $body === '') {
            return null;
        }

        $data = json_decode($body, true);
        $seconds = is_array($data) ? ($data['retry_after_seconds'] ?? null) : null;

        return is_numeric($seconds) ? (int) ceil((float) $seconds) : null;
    }
}
