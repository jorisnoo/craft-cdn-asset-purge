<?php

namespace Noo\CraftCdnAssetPurge;

/**
 * A single purge request: up to one batch of URLs of a single type.
 */
final class PurgeBatch
{
    /** @param list<string> $urls */
    public function __construct(
        public readonly array $urls,
        public readonly bool $wildcard,
    ) {
    }

    public function type(): string
    {
        return $this->wildcard ? 'wildcard' : 'exact';
    }

    /** Seconds the batch's token bucket needs to refill what it used. */
    public function pacingSeconds(): int
    {
        return $this->wildcard
            ? PurgeBatcher::WILDCARD_PACING_SECONDS
            : PurgeBatcher::EXACT_PACING_SECONDS;
    }
}
