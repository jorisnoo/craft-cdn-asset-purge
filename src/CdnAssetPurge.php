<?php

namespace Noo\CraftCdnAssetPurge;

use Craft;
use craft\base\Element;
use craft\elements\Asset;
use craft\events\ModelEvent;
use craft\events\ReplaceAssetEvent;
use craft\services\Assets;
use yii\base\Event;
use yii\base\Module;

/**
 * @property CdnAssetPurgeService $purgeService
 */
class CdnAssetPurge extends Module
{
    private array $config;

    /** @var array<int, string> */
    private array $pendingPurgeUrls = [];

    public static function getInstance(): static
    {
        return Craft::$app->getModule('cdn-asset-purge');
    }

    public function init(): void
    {
        Craft::setAlias('@Noo/CraftCdnAssetPurge', __DIR__);

        parent::init();

        $this->config = array_merge(
            require dirname(__DIR__) . '/config.php',
            Craft::$app->config->getConfigFromFile('cdn-asset-purge'),
        );

        $this->set('purgeService', new CdnAssetPurgeService(
            $this->config['apiUrl'],
            $this->config['apiKey'],
            $this->config['authType'],
        ));

        $this->registerEventListeners();
    }

    private function queuePurge(?string $url): void
    {
        if ($url !== null) {
            Craft::$app->getQueue()->push(new PurgeAssetUrlJob([
                'url' => $url,
            ]));
        }
    }

    private function registerEventListeners(): void
    {
        $filesystems = $this->config['filesystems'];

        if (empty($filesystems)) {
            return;
        }

        // Purge when asset file is replaced (same URL, new content)
        Event::on(
            Assets::class,
            Assets::EVENT_AFTER_REPLACE_ASSET,
            function (ReplaceAssetEvent $event) use ($filesystems) {
                $asset = $event->asset;

                if (! in_array($asset->getVolume()->getFs()->handle, $filesystems)) {
                    return;
                }

                $this->queuePurge($asset->getUrl());
            },
        );

        // Capture old URL before save for rename/move detection
        Event::on(
            Asset::class,
            Element::EVENT_BEFORE_SAVE,
            function (ModelEvent $event) use ($filesystems) {
                /** @var Asset $asset */
                $asset = $event->sender;

                if ($event->isNew || ! in_array($asset->getVolume()->getFs()->handle, $filesystems)) {
                    return;
                }

                $this->pendingPurgeUrls[$asset->id] = $asset->getUrl();
            },
        );

        // After save, purge old URL if it changed (renamed/moved)
        Event::on(
            Asset::class,
            Element::EVENT_AFTER_SAVE,
            function (ModelEvent $event) {
                /** @var Asset $asset */
                $asset = $event->sender;

                if (! isset($this->pendingPurgeUrls[$asset->id])) {
                    return;
                }

                $oldUrl = $this->pendingPurgeUrls[$asset->id];
                unset($this->pendingPurgeUrls[$asset->id]);

                if ($oldUrl !== $asset->getUrl()) {
                    $this->queuePurge($oldUrl);
                }
            },
        );

        // Purge URL before asset is deleted
        Event::on(
            Asset::class,
            Element::EVENT_BEFORE_DELETE,
            function (ModelEvent $event) use ($filesystems) {
                /** @var Asset $asset */
                $asset = $event->sender;

                if (! in_array($asset->getVolume()->getFs()->handle, $filesystems)) {
                    return;
                }

                $this->queuePurge($asset->getUrl());
            },
        );
    }
}
