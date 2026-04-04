<?php

namespace Noo\CraftBunnyPurge;

use Craft;
use craft\elements\Asset;
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

    /** @return string[] */
    private function getAssetUrlsAcrossSites(int $assetId): array
    {
        $urls = [];

        foreach (Craft::$app->getSites()->getAllSites() as $site) {
            $url = Asset::find()
                ->id($assetId)
                ->siteId($site->id)
                ->one()
                ?->getUrl();

            if ($url !== null) {
                $urls[] = $url;
            }
        }

        return array_unique($urls);
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

                $urls = $this->getAssetUrlsAcrossSites($asset->id);

                if (empty($urls)) {
                    return;
                }

                Craft::$app->getQueue()->push(new PurgeAssetUrlJob([
                    'urls' => $urls,
                ]));
            },
        );
    }
}
