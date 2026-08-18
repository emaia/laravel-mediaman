<?php

use Emaia\MediaMan\Console\Concerns\ParsesMediaKeys;

function mediaKeyParser(): object
{
    return new class
    {
        use ParsesMediaKeys;

        public function parse(string $value, int $limit = 10000): array
        {
            return $this->parseMediaKeys($value, $limit);
        }
    };
}

it('preserves opaque media keys and expands integer ranges', function () {
    expect(mediaKeyParser()->parse('550e8400-e29b-41d4-a716-446655440000,2..4'))->toBe([
        '550e8400-e29b-41d4-a716-446655440000',
        2,
        3,
        4,
    ]);
});

it('enforces the media-key limit across multiple ranges', function () {
    expect(mediaKeyParser()->parse('1..3,10..12', 5))->toBe([]);
});
