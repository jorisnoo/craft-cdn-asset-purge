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

    /** @var array<int, string> */
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

                $this->queuePurge($asset->getUrl());
            },
        );

        // Capture old URL before save for rename/move detection
        Event::on(
            Asset::class,
            Element::EVENT_BEFORE_SAVE,
            function (ModelEvent $event) use ($volumes) {
                /** @var Asset $asset */
                $asset = $event->sender;

                if ($event->isNew || ! in_array($asset->getVolume()->handle, $volumes)) {
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
            function (ModelEvent $event) use ($volumes) {
                /** @var Asset $asset */
                $asset = $event->sender;

                if (! in_array($asset->getVolume()->handle, $volumes)) {
                    return;
                }

                $this->queuePurge($asset->getUrl());
            },
        );
    }
}
