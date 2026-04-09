<?php

namespace Noo\CraftCdnAssetPurge;

use craft\queue\BaseJob;

class PurgeAssetUrlJob extends BaseJob
{
    public string $url;

    public function execute($queue): void
    {
        CdnAssetPurge::getInstance()->purgeService->purgeUrls([$this->url]);
    }

    protected function defaultDescription(): ?string
    {
        return "Purging CDN cache for {$this->url}";
    }
}
