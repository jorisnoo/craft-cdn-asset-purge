<?php

namespace Noo\CraftCdnAssetPurge;

use Craft;
use craft\helpers\App;

class CdnAssetPurgeService
{
    private const URL_BATCH_LIMIT = 100;

    private string $apiUrl;

    private ?string $apiKey;

    private string $authType;

    public function __construct(string $apiUrl, ?string $apiKey, string $authType)
    {
        $this->apiUrl = $apiUrl;
        $this->apiKey = $apiKey;
        $this->authType = $authType;
    }

    /** @param string[] $urls */
    public function purgeUrls(array $urls): void
    {
        $apiKey = App::parseEnv($this->apiKey);

        if (empty($apiKey)) {
            Craft::warning('CDN purge API key not configured.', __METHOD__);

            return;
        }

        $apiUrl = App::parseEnv($this->apiUrl);

        $headers = array_merge(
            ['Content-Type' => 'application/json'],
            $this->authHeaders($apiKey),
        );

        $client = Craft::createGuzzleClient();

        foreach (array_chunk($urls, self::URL_BATCH_LIMIT) as $batch) {
            try {
                $client->post($apiUrl, [
                    'headers' => $headers,
                    'json' => ['urls' => $batch],
                ]);
            } catch (\Throwable $e) {
                Craft::error("CDN purge request failed: {$e->getMessage()}", __METHOD__);
            }
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
