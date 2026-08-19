<?php

use Emaia\MediaMan\ResponsiveImages\ResponsiveGeneration;

it('accepts only canonical managed generation ULIDs', function (string $generation, bool $managed) {
    expect(ResponsiveGeneration::isManaged($generation))->toBe($managed);
})->with([
    'canonical' => ['01ARZ3NDEKTSV4RRFFQ69G5FAV', true],
    'lowercase' => ['01arz3ndektsv4rrffq69g5fav', false],
    'nil' => ['00000000000000000000000000', false],
    'max' => ['7ZZZZZZZZZZZZZZZZZZZZZZZZZ', false],
    'out of range' => ['8ZZZZZZZZZZZZZZZZZZZZZZZZZ', false],
    'invalid alphabet' => ['01ARZ3NDEKTSV4RRFFQ69G5FAI', false],
    'invalid length' => ['01ARZ3NDEKTSV4RRFFQ69G5FA', false],
]);
