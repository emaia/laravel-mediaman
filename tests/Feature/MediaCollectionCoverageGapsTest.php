<?php

use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Models\MediaCollection;
use Illuminate\Support\Collection as BaseCollection;

beforeEach(function () {
    $this->collection = MediaCollection::create(['name' => 'gallery']);
});

it('treats an empty array as detach-all in syncMedia', function () {
    $media = Media::factory()->create();
    $this->collection->attachMedia($media);

    $this->collection->syncMedia([]);

    expect($this->collection->media()->count())->toEqual(0);
});

it('returns null from syncMedia when fetchMedia yields no match', function () {
    expect($this->collection->syncMedia('does-not-exist'))->toBeNull();
});

it('returns null from attachMedia when fetchMedia yields no match', function () {
    expect($this->collection->attachMedia('does-not-exist'))->toBeNull();
});

it('returns null from detachMedia when fetchMedia yields no match', function () {
    expect($this->collection->detachMedia('does-not-exist'))->toBeNull();
});

it('returns null from fetchMedia for unsupported input types', function () {
    $reflection = new ReflectionClass(MediaCollection::class);
    $method = $reflection->getMethod('fetchMedia');
    $method->setAccessible(true);

    expect($method->invoke($this->collection, 3.14))->toBeNull();
});

it('passes through an array of BaseCollection entries in fetchMedia', function () {
    $reflection = new ReflectionClass(MediaCollection::class);
    $method = $reflection->getMethod('fetchMedia');
    $method->setAccessible(true);

    $input = [new BaseCollection([1, 2])];
    expect($method->invoke($this->collection, $input))->toBe($input);
});

it('attaches media from an Eloquent collection of model instances', function () {
    $media = Media::factory()->count(2)->create();

    $attached = $this->collection->attachMedia($media);

    expect($attached)->toEqual(2);
});

it('attaches a single media by numeric id', function () {
    $media = Media::factory()->create();

    $attached = $this->collection->attachMedia($media->id);

    expect($attached)->toEqual(1);
});

it('attaches media by array of ids', function () {
    $media = Media::factory()->count(2)->create();

    $attached = $this->collection->attachMedia($media->pluck('id')->toArray());

    expect($attached)->toEqual(2);
});

it('syncs media by model instance', function () {
    $first = Media::factory()->create();
    $second = Media::factory()->create();
    $this->collection->attachMedia($first);

    $result = $this->collection->syncMedia($second);

    expect($result)->toHaveKey('attached')
        ->and($result['attached'])->toContain($second->id)
        ->and($result['detached'])->toContain($first->id);
});
