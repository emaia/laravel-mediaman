<?php

use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Tests\Models\Subject;
use Illuminate\Support\Facades\DB;

beforeEach(function () {
    $this->subject = Subject::create();
});

function countInsertsInto(string $table): int
{
    return collect(DB::getQueryLog())
        ->filter(fn ($q) => str_starts_with(strtolower($q['query']), 'insert')
            && str_contains($q['query'], $table))
        ->count();
}

it('attaches N media items in a single insert query', function () {
    $media = Media::factory()->count(5)->create();

    DB::enableQueryLog();
    $this->subject->attachMedia($media);
    DB::disableQueryLog();

    expect(countInsertsInto('mediaman_mediables'))->toEqual(1);
    expect($this->subject->getMedia())->toHaveCount(5);
});

it('does not run inserts when nothing new is attached', function () {
    $media = Media::factory()->create();
    $this->subject->attachMedia($media);

    DB::enableQueryLog();
    $this->subject->attachMedia($media); // re-attach same id
    DB::disableQueryLog();

    expect(countInsertsInto('mediaman_mediables'))->toEqual(0);
});

it('attaches only the new ids when some already exist', function () {
    $existing = Media::factory()->create();
    $newMedia = Media::factory()->count(3)->create();

    $this->subject->attachMedia($existing);

    DB::enableQueryLog();
    $result = $this->subject->syncMedia(
        $newMedia->pluck('id')->push($existing->id)->toArray(),
        Media::DEFAULT_CHANNEL,
        [],
        false // no detaching
    );
    DB::disableQueryLog();

    expect(countInsertsInto('mediaman_mediables'))->toEqual(1)
        ->and($result['attached'])->toHaveCount(3)
        ->and($result['updated'])->toEqual([$existing->id]);
});

it('keeps detach as a single query for many ids', function () {
    $media = Media::factory()->count(5)->create();
    $this->subject->attachMedia($media);

    DB::enableQueryLog();
    $this->subject->syncMedia([], Media::DEFAULT_CHANNEL, [], true);
    DB::disableQueryLog();

    // shouldDetachAll path: single detach
    $deletes = collect(DB::getQueryLog())
        ->filter(fn ($q) => str_starts_with(strtolower($q['query']), 'delete')
            && str_contains($q['query'], 'mediaman_mediables'))
        ->count();

    expect($deletes)->toEqual(1)
        ->and($this->subject->hasMedia())->toBeFalse();
});
