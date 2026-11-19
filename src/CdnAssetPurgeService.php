<?php

namespace Noo\CraftCdnAssetPurge;

use Craft;
use craft\helpers\App;
use craft\helpers\Queue;
use GuzzleHttp\Exception\GuzzleException;
use GuzzleHttp\Psr7\Uri;

class CdnAssetPurgeService
{
    /** Total tries per batch before the purge fails visibly. */
    public const MAX_ATTEMPTS = 10;

    private const TIMEOUT_SECONDS = 10;

    private const CONNECT_TIMEOUT_SECONDS = 5;

    private string $apiUrl;

    private ?string $apiKey;

    private string $authType;

    public function __construct(string $apiUrl, ?string $apiKey, string $authType)
    {
        $this->apiUrl = $apiUrl;
        $this->apiKey = $apiKey;
        $this->authType = $authType;
    }

    public function hasApiKey(): bool
    {
        return ! empty(App::parseEnv($this->apiKey));
    }

    /**
     * Sends the first batch of URLs now and queues the rest, paced to the
     * CDN's rate limits (see PurgeBatcher). A rate-limited (429), 5xx or
     * connection failure queues the batch again with a delay. Any other
     * failure, or running out of retries, throws.
     *
     * @param string[] $urls
     * @param int $attempt Failed tries so far for the first batch
     * @throws PurgeFailedException
     */
    public function purgeUrls(array $urls, int $attempt = 0): void
    {
        $batches = PurgeBatcher::batches($urls);

        if ($batches === []) {
            return;
        }

        $apiKey = App::parseEnv($this->apiKey);

        if (empty($apiKey)) {
            throw new PurgeFailedException('CDN purge API key not configured.');
        }

        $apiUrl = App::parseEnv($this->apiUrl);

        if (empty($apiUrl)) {
            throw new PurgeFailedException('CDN purge API URL not configured.');
        }
        $endpoint = new Uri($apiUrl);
        $native = $endpoint->getHost() === 'api.bunny.net';
        if ($native && ($endpoint->getScheme() !== 'https' || rtrim($endpoint->getPath(), '/') !== '/purge')) {
            throw new PurgeFailedException('The Bunny Core purge endpoint is https://api.bunny.net/purge.');
        }

        $batch = array_shift($batches);
        $remaining = array_merge([], ...array_map(fn (PurgeBatch $b) => $b->urls, $batches));
        if ($native) {
            // The documented Core API accepts one URL, not the gateway JSON contract.
            $remaining = array_merge(array_slice($batch->urls, 1), $remaining);
            $batch = new PurgeBatch([$batch->urls[0]], $batch->wildcard);
            // Encode the entire nested URL, including percent escapes in Craft's paths.
            $endpoint = Uri::withQueryValue($endpoint, 'url', rawurlencode($batch->urls[0]));
        }

        $client = Craft::createGuzzleClient([
            'timeout' => self::TIMEOUT_SECONDS,
            'connect_timeout' => self::CONNECT_TIMEOUT_SECONDS,
        ]);

        $options = [
            'headers' => $this->authHeaders($apiKey),
            'http_errors' => false,
            'allow_redirects' => false,
        ];
        if (!$native) {
            $options['json'] = ['urls' => $batch->urls];
        }

        try {
            $response = $client->post($endpoint, $options);
        } catch (GuzzleException $e) {
            $this->retry($batch, $remaining, $attempt, PurgeBatcher::retryDelay($batch, $attempt + 1), "request failed: {$e->getMessage()}");

            return;
        }

        $status = $response->getStatusCode();

        if ($status >= 200 && $status < 300) {
            Craft::info(sprintf('CDN purge succeeded for %d %s URL(s).', count($batch->urls), $batch->type()), __METHOD__);

            if ($remaining !== []) {
                // Queue delays are whole seconds. A native request consumes one token.
                $delay = $native ? ($batch->wildcard ? 2 : 1) : $batch->pacingSeconds();
                $this->queue($remaining, 0, $delay);
            }

            return;
        }

        if ($status === 429) {
            $delay = PurgeBatcher::rateLimitDelay(
                $batch,
                $attempt + 1,
                $response->getHeaderLine('Retry-After'),
                (string) $response->getBody(),
            );

            $this->retry($batch, $remaining, $attempt, $delay, 'rate-limited (429)');

            return;
        }

        if ($status >= 500) {
            $this->retry($batch, $remaining, $attempt, PurgeBatcher::retryDelay($batch, $attempt + 1), "server error ({$status})");

            return;
        }

        $body = mb_substr(trim((string) $response->getBody()), 0, 500);

        throw new PurgeFailedException("CDN purge request was rejected with HTTP {$status}: {$body}");
    }

    /**
     * Queues the failed batch (and the batches behind it) again after
     * $delay seconds, or throws once it has used up its tries.
     *
     * @param list<string> $remaining
     * @throws PurgeFailedException
     */
    private function retry(PurgeBatch $batch, array $remaining, int $attempt, int $delay, string $reason): void
    {
        $tries = $attempt + 1;
        $count = count($batch->urls);

        if ($tries >= self::MAX_ATTEMPTS) {
            throw new PurgeFailedException("CDN purge of {$count} {$batch->type()} URL(s) {$reason}; giving up after {$tries} tries.");
        }

        Craft::warning("CDN purge of {$count} {$batch->type()} URL(s) {$reason}; retrying in {$delay}s (try {$tries} of " . self::MAX_ATTEMPTS . ').', __METHOD__);

        $this->queue(array_merge($batch->urls, $remaining), $tries, $delay);
    }

    /** @param list<string> $urls */
    private function queue(array $urls, int $attempt, int $delay): void
    {
        $id = Queue::push(new PurgeAssetUrlJob([
            'urls' => $urls,
            'attempt' => $attempt,
        ]), null, $delay);
        if ($id === null) {
            throw new PurgeFailedException('Queue declined the next CDN purge batch.');
        }
    }

    /** @return array<string, string> */
    private function authHeaders(string $apiKey): array
    {
        return match ($this->authType) {
            'bearer' => ['Authorization' => "Bearer {$apiKey}"],
            default => ['AccessKey' => $apiKey],
        };
    }
}
