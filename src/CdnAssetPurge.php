<?php

namespace Noo\CraftCdnAssetPurge;

use Craft;
use craft\base\Element;
use craft\controllers\AssetsController;
use craft\elements\Asset;
use craft\events\ElementEvent;
use craft\events\ImageTransformerOperationEvent;
use craft\events\ModelEvent;
use craft\events\ReplaceAssetEvent;
use craft\helpers\Assets as AssetHelper;
use craft\helpers\Html;
use craft\imagetransforms\ImageTransformer;
use craft\models\Volume;
use craft\services\Assets;
use craft\services\Elements;
use WeakMap;
use yii\base\ActionEvent;
use yii\base\Application;
use yii\base\Event;
use yii\base\Module;
use yii\db\Connection;
use yii\queue\Queue;

/**
 * @property CdnAssetPurgeService $purgeService
 */
class CdnAssetPurge extends Module
{
    private array $config;

    /** @var array<string,string> */
    private array $urls = [];

    /** @var array<string,string> URLs from an uncommitted asset operation */
    private array $transactionUrls = [];

    public static function getInstance(): static
    {
        $module = Craft::$app->getModule('cdn-asset-purge');
        if (!$module instanceof static) {
            throw new \yii\base\InvalidConfigException('The cdn-asset-purge module is not registered.');
        }
        return $module;
    }

    public function init(): void
    {
        Craft::setAlias('@Noo/CraftCdnAssetPurge', __DIR__);

        parent::init();

        $defaults = require dirname(__DIR__) . '/config.php';

        $this->config = array_merge(
            $defaults,
            Craft::$app->config->getConfigFromFile('cdn-asset-purge'),
        );

        // Unset env vars come through as null; fall back to the defaults
        // rather than throwing a TypeError from a bootstrapped module.
        $authType = $this->config['authType'] ?? $defaults['authType'];

        if (! in_array($authType, ['access_key', 'bearer'], true)) {
            Craft::warning('Unknown CDN purge authType; falling back to access_key.', __METHOD__);
            $authType = 'access_key';
        }

        $this->set('purgeService', new CdnAssetPurgeService(
            $this->config['apiUrl'] ?? $defaults['apiUrl'],
            $this->config['apiKey'] ?? null,
            $authType,
        ));

        $this->registerEventListeners();
    }

    private function collect(?string $url): void
    {
        if ($url === null || $url === '') {
            return;
        }

        if (Craft::$app->getDb()->getTransaction() !== null) {
            $this->transactionUrls[$url] = $url;
        } else {
            $this->urls[$url] = $url;
        }
    }

    private function flush(): void
    {
        if ($this->urls === []) {
            return;
        }

        // A synchronous queue can fire afterExec from inside push().
        $pending = $this->urls;
        $this->urls = [];
        try {
            $id = Craft::$app->getQueue()->push(new PurgeAssetUrlJob(['urls' => array_values($pending)]));
            if ($id === null) {
                throw new PurgeFailedException('Queue declined the CDN purge job.');
            }
        } catch (\Throwable $e) {
            $this->urls += $pending;
            throw $e;
        }
    }

    private function monitors(Volume $volume, bool $transform = false): bool
    {
        $fs = $transform ? $volume->getTransformFs() : $volume->getFs();
        return in_array($fs->handle, $this->config['filesystems'] ?? [], true)
            || in_array($volume->handle, $this->config['volumes'] ?? [], true);
    }

    private function registerEventListeners(): void
    {
        if (!empty($this->config['volumes'])) {
            Craft::warning('CDN asset purge: volumes is deprecated; migrate volume handles to filesystem handles in filesystems.', __METHOD__);
        }
        if (empty($this->config['filesystems']) && empty($this->config['volumes'])) {
            Craft::warning('CDN asset purge is disabled: no filesystems or volumes configured.', __METHOD__);
            return;
        }

        if (! $this->purgeService->hasApiKey()) {
            Craft::warning('CDN asset purge is disabled: no API key configured.', __METHOD__);

            return;
        }

        $db = Craft::$app->getDb();
        $db->on(Connection::EVENT_COMMIT_TRANSACTION, function () {
            $this->urls += $this->transactionUrls;
            $this->transactionUrls = [];
        });
        $db->on(Connection::EVENT_ROLLBACK_TRANSACTION, function () {
            $this->transactionUrls = [];
        });

        Event::on(Application::class, Application::EVENT_AFTER_REQUEST, fn () => $this->flush());
        Event::on(Queue::class, Queue::EVENT_AFTER_EXEC, fn () => $this->flush());
        Event::on(Queue::class, Queue::EVENT_AFTER_ERROR, fn () => $this->flush());

        // Purge when asset file is replaced (same URL, new content)
        Event::on(
            Assets::class,
            Assets::EVENT_AFTER_REPLACE_ASSET,
            function (ReplaceAssetEvent $event) {
                $asset = $event->asset;

                if (! $this->monitors($asset->getVolume())) {
                    return;
                }

                $this->collect($asset->getUrl());
            },
        );

        // Capture old URL before save for rename/move detection
        $beforeSave = new WeakMap();
        Event::on(
            Asset::class,
            Element::EVENT_BEFORE_SAVE,
            function (ModelEvent $event) use ($beforeSave) {
                /** @var Asset $asset */
                $asset = $event->sender;

                if ($event->isNew || ! $this->monitors($asset->getVolume())) {
                    unset($beforeSave[$asset]);
                    return;
                }

                $beforeSave[$asset] = $asset->getUrl();
            },
        );

        // After save, purge old URL if it changed (renamed/moved)
        Event::on(
            Asset::class,
            Element::EVENT_AFTER_SAVE,
            function (ModelEvent $event) use ($beforeSave) {
                /** @var Asset $asset */
                $asset = $event->sender;

                if (! isset($beforeSave[$asset])) {
                    return;
                }

                $oldUrl = $beforeSave[$asset];
                unset($beforeSave[$asset]);

                if ($oldUrl !== $asset->getUrl()) {
                    $this->collect($oldUrl);
                }
            },
        );

        // Capture before removal, but only release the URL after Craft completes the delete.
        $beforeDelete = new WeakMap();
        Event::on(
            Asset::class,
            Element::EVENT_BEFORE_DELETE,
            function (ModelEvent $event) use ($beforeDelete) {
                /** @var Asset $asset */
                $asset = $event->sender;

                if (! $this->monitors($asset->getVolume())) {
                    unset($beforeDelete[$asset]);
                    return;
                }

                $beforeDelete[$asset] = $asset->getUrl();
            },
        );
        Event::on(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, function (ElementEvent $event) use ($beforeDelete) {
            $this->collect($beforeDelete[$event->element] ?? null);
            unset($beforeDelete[$event->element]);
        });

        Event::on(ImageTransformer::class, ImageTransformer::EVENT_DELETE_TRANSFORMED_IMAGE, function (ImageTransformerOperationEvent $event) {
            $volume = $event->asset->getVolume();
            if (! $this->monitors($volume, true) || ($root = $volume->getTransformFs()->getRootUrl()) === null) {
                return;
            }
            $url = rtrim($root, '/') . '/' . ltrim(str_replace('\\', '/', $event->path), '/');
            if (Craft::$app->getConfig()->getGeneral()->revAssetUrls) {
                $url = AssetHelper::revUrl($url, $event->asset, $event->imageTransformIndex->dateUpdated);
            }
            $this->collect(Html::encodeSpaces($url));
        });

        $this->registerFolderRenameListeners();
    }

    private function registerFolderRenameListeners(): void
    {
        // Craft renames the directory without saving its assets or firing a folder event.
        $oldPrefixes = new WeakMap();
        Event::on(AssetsController::class, AssetsController::EVENT_BEFORE_ACTION, function (ActionEvent $event) use ($oldPrefixes) {
            if ($event->action->id !== 'rename-folder') {
                return;
            }
            $id = filter_var(Craft::$app->getRequest()->getBodyParam('folderId'), FILTER_VALIDATE_INT);
            $folder = $id ? Craft::$app->getAssets()->getFolderById($id) : null;
            if (!$folder || !$folder->parentId || trim($folder->path, '/') === '') {
                return;
            }

            $volume = $folder->getVolume();
            $prefixes = [];
            foreach ([false, true] as $transform) {
                if (!$this->monitors($volume, $transform)) {
                    continue;
                }
                $fs = $transform ? $volume->getTransformFs() : $volume->getFs();
                $root = $fs->getRootUrl();
                if ($root === null) {
                    continue;
                }
                // transformSubpath was a public property before Craft 5.2.
                $subpath = $transform ? $volume->transformSubpath : $volume->getSubpath();
                $path = trim(rtrim((string) $subpath, '/') . '/' . trim($folder->path, '/'), '/');
                $prefixes[] = Html::encodeSpaces(rtrim($root, '/') . '/' . $path . '/*');
            }
            $oldPrefixes[$event->action] = $prefixes;
        });
        Event::on(AssetsController::class, AssetsController::EVENT_AFTER_ACTION, function (ActionEvent $event) use ($oldPrefixes) {
            if ($event->result instanceof \yii\web\Response && $event->result->getIsSuccessful()) {
                foreach ($oldPrefixes[$event->action] ?? [] as $url) {
                    $this->collect($url);
                }
            }
            unset($oldPrefixes[$event->action]);
        });
    }
}
