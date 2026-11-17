<?php

namespace Noo\CraftCdnAssetPurge;

use craft\queue\BaseJob;

class PurgeAssetUrlJob extends BaseJob
{
    /** A single URL to purge. Kept for jobs queued before `urls` existed. */
    public ?string $url = null;

    /** @var string[] URLs to purge */
    public array $urls = [];

    /** Failed tries so far, used for backoff and the retry limit. */
    public int $attempt = 0;

    public function execute($queue): void
    {
        CdnAssetPurge::getInstance()->purgeService->purgeUrls($this->allUrls(), $this->attempt);
    }

    protected function defaultDescription(): ?string
    {
        $urls = $this->allUrls();
        $retry = $this->attempt > 0 ? " (retry {$this->attempt})" : '';

        if (count($urls) === 1) {
            return "Purging CDN cache for {$urls[0]}{$retry}";
        }

        return 'Purging CDN cache for ' . count($urls) . " URLs{$retry}";
    }

    /** @return list<string> */
    private function allUrls(): array
    {
        $urls = $this->url !== null ? [$this->url, ...$this->urls] : $this->urls;

        return array_values(array_unique($urls));
    }
}
