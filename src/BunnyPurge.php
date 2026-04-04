<?php

namespace Noo\CraftBunnyPurge;

use Craft;
use craft\base\Element;
use craft\elements\Asset;
use craft\events\ModelEvent;
use craft\events\ReplaceAssetEvent;
use craft\services\Assets;
use yii\base\Event;
use yii\base\Module;

/**
 * @property BunnyPurgeService $purgeService
 */
class BunnyPurge extends Module
{
    private array $config;

    /** @var array<int, string[]> */
    private array $pendingPurgeUrls = [];

    public static function getInstance(): static
    {
        return Craft::$app->getModule('bunny-purge');
    }

    public function init(): void
    {
        parent::init();

        $this->config = array_merge(
            require dirname(__DIR__) . '/config.php',
            Craft::$app->config->getConfigFromFile('bunny-purge'),
        );

        $this->set('purgeService', new BunnyPurgeService(
            $this->config['apiUrl'],
            $this->config['apiKey'],
            $this->config['authType'],
        ));

        $this->registerEventListeners();
    }

    private function queuePurge(array $urls): void
    {
        if (! empty($urls)) {
            Craft::$app->getQueue()->push(new PurgeAssetUrlJob([
                'urls' => $urls,
            ]));
        }
    }

    /** @return string[] */
    private function getAssetUrlsAcrossSites(int $assetId): array
    {
        $urls = [];
        $sitesService = Craft::$app->getSites();
        $originalSite = $sitesService->getCurrentSite();

        foreach ($sitesService->getAllSites() as $site) {
            $sitesService->setCurrentSite($site);

            $url = Asset::find()
                ->id($assetId)
                ->siteId($site->id)
                ->one()
                ?->getUrl();

            if ($url !== null) {
                $urls[] = $url;
            }
        }

        $sitesService->setCurrentSite($originalSite);

        return array_unique($urls);
    }

    private function registerEventListeners(): void
    {
        $volumes = $this->config['volumes'];

        if (empty($volumes)) {
            return;
        }

        // Purge when asset file is replaced (same URL, new content)
        Event::on(
            Assets::class,
            Assets::EVENT_AFTER_REPLACE_ASSET,
            function (ReplaceAssetEvent $event) use ($volumes) {
                $asset = $event->asset;

                if (! in_array($asset->getVolume()->handle, $volumes)) {
                    return;
                }

                $this->queuePurge($this->getAssetUrlsAcrossSites($asset->id));
            },
        );

        // Capture old URLs before save for rename/move detection
        Event::on(
            Asset::class,
            Element::EVENT_BEFORE_SAVE,
            function (ModelEvent $event) use ($volumes) {
                /** @var Asset $asset */
                $asset = $event->sender;

                if ($event->isNew || ! in_array($asset->getVolume()->handle, $volumes)) {
                    return;
                }

                $this->pendingPurgeUrls[$asset->id] = $this->getAssetUrlsAcrossSites($asset->id);
            },
        );

        // After save, purge old URLs that are no longer valid (renamed/moved)
        Event::on(
            Asset::class,
            Element::EVENT_AFTER_SAVE,
            function (ModelEvent $event) {
                /** @var Asset $asset */
                $asset = $event->sender;

                if (! isset($this->pendingPurgeUrls[$asset->id])) {
                    return;
                }

                $oldUrls = $this->pendingPurgeUrls[$asset->id];
                unset($this->pendingPurgeUrls[$asset->id]);

                $staleUrls = array_values(array_diff($oldUrls, $this->getAssetUrlsAcrossSites($asset->id)));

                $this->queuePurge($staleUrls);
            },
        );

        // Purge URLs before asset is deleted
        Event::on(
            Asset::class,
            Element::EVENT_BEFORE_DELETE,
            function (ModelEvent $event) use ($volumes) {
                /** @var Asset $asset */
                $asset = $event->sender;

                if (! in_array($asset->getVolume()->handle, $volumes)) {
                    return;
                }

                $this->queuePurge($this->getAssetUrlsAcrossSites($asset->id));
            },
        );
    }
}
