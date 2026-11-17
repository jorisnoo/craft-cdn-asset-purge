<?php

namespace Noo\CraftCdnAssetPurge;

/**
 * Thrown when a purge cannot succeed by retrying (bad config, a rejected
 * request) or has run out of retries.
 */
class PurgeFailedException extends \RuntimeException
{
}
