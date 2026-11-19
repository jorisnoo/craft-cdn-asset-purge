<?php

use craft\config\GeneralConfig;
use craft\controllers\AssetsController;
use craft\elements\Asset;
use craft\events\ElementEvent;
use craft\events\ImageTransformerOperationEvent;
use craft\events\ModelEvent;
use craft\events\ReplaceAssetEvent;
use craft\fs\Local;
use craft\imagetransforms\ImageTransformer;
use craft\models\Volume;
use craft\models\VolumeFolder;
use craft\queue\Queue;
use craft\services\Assets;
use craft\services\Config;
use craft\services\Elements;
use craft\web\Application;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Noo\CraftCdnAssetPurge\CdnAssetPurge;
use Noo\CraftCdnAssetPurge\CdnAssetPurgeService;
use Noo\CraftCdnAssetPurge\PurgeAssetUrlJob;
use Noo\CraftCdnAssetPurge\PurgeFailedException;
use yii\base\Action;
use yii\base\ActionEvent;
use yii\base\Event;
use yii\db\Connection;

beforeEach(function () {
    $this->settings = ['apiKey' => 'test', 'filesystems' => ['images', 'renditions']];
    $this->config = $this->createStub(Config::class);
    $this->config->method('getGeneral')->willReturn(new GeneralConfig());
    $this->config->method('getConfigFromFile')->willReturnCallback(fn ($name) => match ($name) {
        'cdn-asset-purge' => $this->settings,
        'guzzle' => ['handler' => $this->handler],
    });
    $this->jobs = [];
    $this->queue = $this->getMockBuilder(Queue::class)->disableOriginalConstructor()->onlyMethods(['push'])->getMock();
    $this->queue->method('push')->willReturnCallback(function (PurgeAssetUrlJob $job) {
        $this->jobs[] = $job;
        return (string) count($this->jobs);
    });
    $this->db = new Connection(['dsn' => 'sqlite::memory:']);
    $this->request = new yii\web\Request();
    $this->assets = $this->createStub(Assets::class);
    $app = $this->getMockBuilder(Application::class)->disableOriginalConstructor()
        ->onlyMethods(['getConfig', 'getQueue', 'getDb', 'getAssets', 'getRequest', 'getVersion'])->getMock();
    $app->method('getConfig')->willReturn($this->config);
    $app->method('getQueue')->willReturn($this->queue);
    $app->method('getDb')->willReturn($this->db);
    $app->method('getRequest')->willReturn($this->request);
    $app->method('getAssets')->willReturn($this->assets);
    $app->method('getVersion')->willReturn('5.0');
    Craft::$app = $app;

    $this->volume = $this->getMockBuilder(Volume::class)->disableOriginalConstructor()
        ->onlyMethods(['getFs', 'getTransformFs', 'getSubpath', 'getTransformSubpath'])->getMock();
    $this->volume->handle = 'editorial';
    foreach (['getFs' => 'images', 'getTransformFs' => 'renditions'] as $method => $handle) {
        $fs = $this->createStub(Local::class);
        $fs->handle = $handle;
        $fs->method('getRootUrl')->willReturn("https://{$handle}.example/");
        $this->volume->method($method)->willReturn($fs);
    }
    $this->volume->method('getSubpath')->willReturn('sub/');
    $this->volume->method('getTransformSubpath')->willReturn('transforms/');
    $this->asset = $this->createStub(Asset::class);
    $this->asset->method('getVolume')->willReturn($this->volume);
    $this->assetUrl = 'https://images.example/sub/hero.jpg';
    $this->asset->method('getUrl')->willReturnCallback(fn () => $this->assetUrl);
});

afterEach(function () {
    Event::offAll();
    Craft::$app = null;
});

it('batches committed asset and transform events once per request or worker job', function (array $scope) {
    $this->settings = ['apiKey' => 'test'] + $scope;
    new CdnAssetPurge('cdn-asset-purge');
    $tx = $this->db->beginTransaction();
    $replace = new ReplaceAssetEvent(['asset' => $this->asset]);
    Event::trigger(Assets::class, Assets::EVENT_AFTER_REPLACE_ASSET, $replace);
    Event::trigger(Assets::class, Assets::EVENT_AFTER_REPLACE_ASSET, $replace);
    Event::trigger(ImageTransformer::class, ImageTransformer::EVENT_DELETE_TRANSFORMED_IMAGE, new ImageTransformerOperationEvent([
        'asset' => $this->asset, 'path' => 'transforms\\gallery\\_hero\\two words.jpg',
    ]));
    Event::trigger(Application::class, Application::EVENT_AFTER_REQUEST);
    expect($this->jobs)->toBe([]);
    $tx->commit();
    expect($this->jobs)->toBe([]);
    Event::trigger(Queue::class, Queue::EVENT_AFTER_EXEC);
    Event::trigger(Application::class, Application::EVENT_AFTER_REQUEST);
    expect($this->jobs)->toHaveCount(1)
        ->and($this->jobs[0]->urls)->toBe([$this->assetUrl, 'https://renditions.example/transforms/gallery/_hero/two%20words.jpg']);
})->with([
    'filesystem handles' => [['filesystems' => ['images', 'renditions']]],
    'legacy volume handles' => [['volumes' => ['editorial']]],
]);

it('purges the old rename URL and the captured delete URL, not a veto or rollback', function () {
    new CdnAssetPurge('cdn-asset-purge');
    $old = $this->assetUrl;
    Event::trigger($this->asset, Asset::EVENT_BEFORE_SAVE, new ModelEvent());
    $this->assetUrl = 'https://images.example/sub/renamed.jpg';
    Event::trigger($this->asset, Asset::EVENT_AFTER_SAVE, new ModelEvent());
    Event::trigger($this->asset, Asset::EVENT_BEFORE_DELETE, new ModelEvent());
    Event::trigger(Application::class, Application::EVENT_AFTER_REQUEST);
    expect($this->jobs)->toHaveCount(1)->and($this->jobs[0]->urls)->toBe([$old]);
    $tx = $this->db->beginTransaction();
    Event::trigger(Assets::class, Assets::EVENT_AFTER_REPLACE_ASSET, new ReplaceAssetEvent(['asset' => $this->asset]));
    $tx->rollBack();
    Event::trigger(Queue::class, Queue::EVENT_AFTER_ERROR);
    expect($this->jobs)->toHaveCount(1);
    Event::trigger($this->asset, Asset::EVENT_BEFORE_DELETE, new ModelEvent());
    $deleted = $this->assetUrl;
    $this->assetUrl = null;
    Event::trigger(Elements::class, Elements::EVENT_AFTER_DELETE_ELEMENT, new ElementEvent(['element' => $this->asset]));
    Event::trigger(Queue::class, Queue::EVENT_AFTER_EXEC);
    expect($this->jobs)->toHaveCount(2)->and($this->jobs[1]->urls)->toBe([$deleted]);
});

it('does not confuse a source filesystem with its transform filesystem', function () {
    $this->settings['filesystems'] = ['images'];
    new CdnAssetPurge('cdn-asset-purge');
    Event::trigger(ImageTransformer::class, ImageTransformer::EVENT_DELETE_TRANSFORMED_IMAGE, new ImageTransformerOperationEvent([
        'asset' => $this->asset, 'path' => '_hero/hero.jpg',
    ]));
    Event::trigger(Application::class, Application::EVENT_AFTER_REQUEST);
    expect($this->jobs)->toBe([]);
});

it('purges only the old folder prefixes after a successful control-panel rename', function (int $status) {
    $folder = $this->createStub(VolumeFolder::class);
    $folder->parentId = 10;
    $folder->path = 'portfolio/old/';
    $folder->method('getVolume')->willReturn($this->volume);
    $this->assets->method('getFolderById')->willReturn($folder);
    $this->request->setBodyParams(['folderId' => '42']);
    new CdnAssetPurge('cdn-asset-purge');
    $action = $this->createStub(Action::class);
    $action->id = 'rename-folder';
    $event = new ActionEvent($action);
    Event::trigger(AssetsController::class, AssetsController::EVENT_BEFORE_ACTION, $event);
    $folder->path = 'portfolio/new/';
    $response = new yii\web\Response(['charset' => 'UTF-8', 'version' => '1.1']);
    $response->setStatusCode($status);
    $event->result = $response;
    Event::trigger(AssetsController::class, AssetsController::EVENT_AFTER_ACTION, $event);
    Event::trigger(Application::class, Application::EVENT_AFTER_REQUEST);
    expect($this->jobs[0]->urls ?? [])->toBe($status === 200 ? [
        'https://images.example/sub/portfolio/old/*', 'https://renditions.example/transforms/portfolio/old/*',
    ] : []);
})->with([200, 403]);

it('sends a bounded HTTP batch, requeues on 429 and fails on rejection', function (string $auth, string $header, string $value) {
    $history = [];
    $this->handler = HandlerStack::create(new MockHandler([new Response(429, ['Retry-After' => '25']), new Response(401)]));
    $this->handler->push(Middleware::history($history));
    $service = new CdnAssetPurgeService('https://purge.example/api', 'test', $auth);
    $urls = urls('asset', 51);
    $service->purgeUrls($urls);
    $request = $history[0]['request'];
    expect($request->getMethod())->toBe('POST')->and($request->getHeaderLine($header))->toBe($value)
        ->and(json_decode((string) $request->getBody(), true))->toBe(['urls' => array_slice($urls, 0, 50)])
        ->and($history[0]['options']['timeout'])->toBe(10)->and($history[0]['options']['connect_timeout'])->toBe(5)
        ->and($this->jobs)->toHaveCount(1)->and($this->jobs[0]->urls)->toBe($urls)->and($this->jobs[0]->attempt)->toBe(1);
    expect(fn () => $service->purgeUrls($urls))->toThrow(PurgeFailedException::class, 'HTTP 401');
})->with([['bearer', 'Authorization', 'Bearer test'], ['access_key', 'AccessKey', 'test']]);
