<?php

namespace Noo\CraftBunnyPurge;

use Craft;
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

    private function registerEventListeners(): void
    {
        $volumes = $this->config['volumes'];

        if (empty($volumes)) {
            return;
        }

        Event::on(
            Assets::class,
            Assets::EVENT_AFTER_REPLACE_ASSET,
            function (ReplaceAssetEvent $event) use ($volumes) {
                $asset = $event->asset;

                if (! in_array($asset->getVolume()->handle, $volumes)) {
                    return;
                }

                $url = $asset->getUrl();

                if ($url === null) {
                    return;
                }

                Craft::$app->getQueue()->push(new PurgeAssetUrlJob([
                    'url' => $url,
                ]));
            },
        );
    }
}
