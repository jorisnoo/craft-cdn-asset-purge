<?php

namespace Noo\CraftCdnAssetPurge;

use craft\queue\BaseJob;
use yii\queue\RetryableJobInterface;

class PurgeAssetUrlJob extends BaseJob implements RetryableJobInterface
{
    public const MAX_ATTEMPTS = 3;

    public const TTR = 300;

    /** @var string[] */
    public array $urls = [];

    /** @deprecated Kept so jobs queued by earlier versions still run. Use $urls instead. */
    public ?string $url = null;

    public function execute($queue): void
    {
        CdnAssetPurge::getInstance()->purgeService->purgeUrls($this->getUrls());
    }

    public function getTtr(): int
    {
        return self::TTR;
    }

    public function canRetry($attempt, $error): bool
    {
        return $attempt < self::MAX_ATTEMPTS;
    }

    /** @return string[] */
    private function getUrls(): array
    {
        return array_values(array_unique(array_filter([...$this->urls, $this->url])));
    }

    protected function defaultDescription(): ?string
    {
        $urls = $this->getUrls();

        if (count($urls) === 1) {
            return "Purging CDN cache for {$urls[0]}";
        }

        return 'Purging CDN cache for ' . count($urls) . ' URLs';
    }
}
