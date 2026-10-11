<?php

namespace Noo\CraftCdnAssetPurge;

use Craft;
use craft\base\Element;
use craft\base\Fs;
use craft\elements\Asset;
use craft\events\ImageTransformerOperationEvent;
use craft\events\ModelEvent;
use craft\events\ReplaceAssetEvent;
use craft\imagetransforms\ImageTransformer;
use craft\services\Assets;
use yii\base\Application;
use yii\base\Event;
use yii\base\Module;
use yii\queue\Queue;

/**
 * @property CdnAssetPurgeService $purgeService
 */
class CdnAssetPurge extends Module
{
    private array $config;

    /** @var array<int, string> */
    private array $pendingPurgeUrls = [];

    /**
     * URLs collected during the current request (or queue job), keyed by URL.
     *
     * @var array<string, true>
     */
    private array $queuedUrls = [];

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
        if ($url !== null && $url !== '') {
            $this->queuedUrls[$url] = true;
        }
    }

    /**
     * Pushes a single job for all URLs collected so far, so bulk operations
     * (e.g. moving a folder) are purged in batched API requests.
     */
    public function flushPurgeQueue(): void
    {
        if (empty($this->queuedUrls)) {
            return;
        }

        $urls = array_keys($this->queuedUrls);
        $this->queuedUrls = [];

        Craft::$app->getQueue()->push(new PurgeAssetUrlJob([
            'urls' => $urls,
        ]));
    }

    private function registerEventListeners(): void
    {
        $filesystems = $this->config['filesystems'];

        if (empty($filesystems)) {
            return;
        }

        // Push one job per web/console request. Long-running queue workers
        // never end their request, so also flush after each queue job.
        Event::on(Application::class, Application::EVENT_AFTER_REQUEST, fn () => $this->flushPurgeQueue());
        Event::on(Queue::class, Queue::EVENT_AFTER_EXEC, fn () => $this->flushPurgeQueue());
        Event::on(Queue::class, Queue::EVENT_AFTER_ERROR, fn () => $this->flushPurgeQueue());

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

        // Forget the captured URL if the save fails validation (no after-save event follows)
        Event::on(
            Asset::class,
            Element::EVENT_AFTER_VALIDATE,
            function (Event $event) {
                /** @var Asset $asset */
                $asset = $event->sender;

                if ($asset->hasErrors()) {
                    unset($this->pendingPurgeUrls[$asset->id]);
                }
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

        // Purge generated transforms when Craft deletes them. This happens
        // whenever an asset is replaced, moved, renamed or deleted, or its
        // focal point changes, while the transform index still exists.
        Event::on(
            ImageTransformer::class,
            ImageTransformer::EVENT_DELETE_TRANSFORMED_IMAGE,
            function (ImageTransformerOperationEvent $event) use ($filesystems) {
                $fs = $event->asset->getVolume()->getTransformFs();

                if (! $fs instanceof Fs || ! in_array($fs->handle, $filesystems)) {
                    return;
                }

                $rootUrl = $fs->getRootUrl();

                if ($rootUrl !== null) {
                    $this->queuePurge(rtrim($rootUrl, '/') . '/' . ltrim(str_replace('\\', '/', $event->path), '/'));
                }
            },
        );
    }
}
