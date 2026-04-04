<?php

namespace Noo\CraftBunnyPurge;

use craft\queue\BaseJob;

class PurgeAssetUrlJob extends BaseJob
{
    /** @var string[] */
    public array $urls;

    public function execute($queue): void
    {
        BunnyPurge::getInstance()->purgeService->purgeUrls($this->urls);
    }

    protected function defaultDescription(): ?string
    {
        $count = count($this->urls);

        return "Purging Bunny CDN cache for {$count} URL(s)";
    }
}
