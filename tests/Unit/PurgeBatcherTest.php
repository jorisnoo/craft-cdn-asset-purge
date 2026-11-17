<?php

use Noo\CraftCdnAssetPurge\PurgeBatch;
use Noo\CraftCdnAssetPurge\PurgeBatcher;

function urls(string $prefix, int $count, string $suffix = ''): array
{
    return array_map(fn (int $i) => "https://cdn.example.com/{$prefix}{$i}{$suffix}", range(1, $count));
}

it('detects wildcard URLs', function (string $url, bool $expected) {
    expect(PurgeBatcher::isWildcard($url))->toBe($expected);
})->with([
    'plain file' => ['https://cdn.example.com/images/hero.jpg', false],
    'asterisk' => ['https://cdn.example.com/images/*', true],
    'asterisk mid-path' => ['https://cdn.example.com/*/hero.jpg', true],
    'trailing slash' => ['https://cdn.example.com/images/', true],
]);

it('chunks exact URLs at 50', function () {
    $batches = PurgeBatcher::batches(urls('a', 120));

    expect($batches)->toHaveCount(3)
        ->and(array_map(fn (PurgeBatch $b) => count($b->urls), $batches))->toBe([50, 50, 20])
        ->and(array_map(fn (PurgeBatch $b) => $b->wildcard, $batches))->toBe([false, false, false]);
});

it('chunks wildcard URLs below the wildcard burst', function () {
    $batches = PurgeBatcher::batches(urls('dir', 40, '/*'));

    expect(array_map(fn (PurgeBatch $b) => count($b->urls), $batches))->toBe([15, 15, 10])
        ->and(array_map(fn (PurgeBatch $b) => $b->wildcard, $batches))->toBe([true, true, true]);
});

it('never mixes exact and wildcard URLs in one batch, exact first', function () {
    $input = [
        'https://cdn.example.com/a/*',
        'https://cdn.example.com/a.jpg',
        'https://cdn.example.com/b/',
        'https://cdn.example.com/b.jpg',
    ];

    $batches = PurgeBatcher::batches($input);

    expect($batches)->toHaveCount(2)
        ->and($batches[0]->wildcard)->toBeFalse()
        ->and($batches[0]->urls)->toBe(['https://cdn.example.com/a.jpg', 'https://cdn.example.com/b.jpg'])
        ->and($batches[1]->wildcard)->toBeTrue()
        ->and($batches[1]->urls)->toBe(['https://cdn.example.com/a/*', 'https://cdn.example.com/b/']);

    foreach ($batches as $batch) {
        foreach ($batch->urls as $url) {
            expect(PurgeBatcher::isWildcard($url))->toBe($batch->wildcard);
        }
    }
});

it('drops duplicate and empty URLs', function () {
    $batches = PurgeBatcher::batches(['https://cdn.example.com/a.jpg', '', null, 'https://cdn.example.com/a.jpg']);

    expect($batches)->toHaveCount(1)
        ->and($batches[0]->urls)->toBe(['https://cdn.example.com/a.jpg']);
});

it('returns no batches for no URLs', function () {
    expect(PurgeBatcher::batches([]))->toBe([]);
});

it('paces each batch to its bucket refill window', function () {
    expect((new PurgeBatch(['x'], false))->pacingSeconds())->toBe(10)
        ->and((new PurgeBatch(['x/*'], true))->pacingSeconds())->toBe(30);
});

it('grows the retry delay per attempt up to the cap', function (bool $wildcard, int $attempt, int $expected) {
    expect(PurgeBatcher::retryDelay(new PurgeBatch(['x'], $wildcard), $attempt))->toBe($expected);
})->with([
    'exact, first retry' => [false, 1, 10],
    'exact, third retry' => [false, 3, 30],
    'exact, capped' => [false, 50, 300],
    'wildcard, first retry' => [true, 1, 30],
    'wildcard, capped' => [true, 20, 300],
    'attempt 0 treated as first' => [false, 0, 10],
]);

describe('rate limit delay', function () {
    $exact = new PurgeBatch(['https://cdn.example.com/a.jpg'], false);
    $wildcard = new PurgeBatch(['https://cdn.example.com/a/*'], true);

    it('honours a Retry-After longer than the pacing window', function () use ($exact) {
        expect(PurgeBatcher::rateLimitDelay($exact, 1, '45', null))->toBe(45);
    });

    it('floors a too-short Retry-After to the wildcard refill window', function () use ($wildcard) {
        // Bunny advertised ~2s for a drained prefix bucket that needs ~30s.
        expect(PurgeBatcher::rateLimitDelay($wildcard, 1, '2', '{"type":"prefix","retry_after_seconds":2}'))->toBe(30);
    });

    it('floors a too-short Retry-After to the exact refill window', function () use ($exact) {
        expect(PurgeBatcher::rateLimitDelay($exact, 1, '1', null))->toBe(10);
    });

    it('falls back to retry_after_seconds in the body', function () use ($exact) {
        expect(PurgeBatcher::rateLimitDelay($exact, 1, '', '{"type":"exact","retry_after_seconds":42}'))->toBe(42)
            ->and(PurgeBatcher::rateLimitDelay($exact, 1, null, '{"retry_after_seconds":"12.2"}'))->toBe(13);
    });

    it('prefers the header over the body', function () use ($exact) {
        expect(PurgeBatcher::rateLimitDelay($exact, 1, '20', '{"retry_after_seconds":90}'))->toBe(20);
    });

    it('accepts an HTTP-date Retry-After', function () use ($exact) {
        $now = 1_800_000_000;
        $date = gmdate('D, d M Y H:i:s \G\M\T', $now + 75);

        expect(PurgeBatcher::rateLimitDelay($exact, 1, $date, null, $now))->toBe(75);
    });

    it('uses the default backoff when no wait is advertised', function () use ($exact) {
        expect(PurgeBatcher::rateLimitDelay($exact, 1, null, null))->toBe(60)
            ->and(PurgeBatcher::rateLimitDelay($exact, 1, 'soon', 'not json'))->toBe(60)
            ->and(PurgeBatcher::rateLimitDelay($exact, 1, '0', '{"retry_after_seconds":0}'))->toBe(60);
    });

    it('caps the advertised wait', function () use ($exact) {
        expect(PurgeBatcher::rateLimitDelay($exact, 1, '3600', null))->toBe(300);
    });

    it('backs off further on repeated rate limits', function () use ($wildcard) {
        expect(PurgeBatcher::rateLimitDelay($wildcard, 4, '2', null))->toBe(120)
            ->and(PurgeBatcher::rateLimitDelay($wildcard, 10, '2', null))->toBe(300);
    });
});
