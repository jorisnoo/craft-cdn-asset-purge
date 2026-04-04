<?php

namespace Noo\CraftBunnyPurge;

use craft\queue\BaseJob;

class PurgeAssetUrlJob extends BaseJob
{
    public string $url;

    public function execute($queue): void
    {
        $service = BunnyPurge::getInstance()->purgeService;
        $service->purgeUrls([$this->url]);
    }

    protected function defaultDescription(): ?string
    {
        return "Purging Bunny CDN cache for {$this->url}";
    }
}
