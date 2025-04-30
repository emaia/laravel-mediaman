<?php

use Emaia\MediaMan\MediaChannel;

it('can register and retrieve conversions', function () {
    $mediaChannel = new MediaChannel();

    $mediaChannel->performConversions('one', 'two');

    $registeredConversions = $mediaChannel->getConversions();

    expect($registeredConversions)->toHaveCount(2)
        ->and($registeredConversions)->toEqual(['one', 'two']);
});

it('can determine if any conversions have been registered', function () {
    $mediaChannel = new MediaChannel();

    expect($mediaChannel->hasConversions())->toBeFalse();

    $mediaChannel->performConversions('conversion');

    expect($mediaChannel->hasConversions())->toBeTrue();
});
