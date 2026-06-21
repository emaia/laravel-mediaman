<?php

use Emaia\MediaMan\Events\MediaUploaded;
use Emaia\MediaMan\Exceptions\MediaNotAcceptedByChannel;
use Emaia\MediaMan\Jobs\PerformConversions;
use Emaia\MediaMan\MediaUploader;
use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Tests\Models\Subject;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    $this->subject = Subject::create();
});

function uploadImage(string $name = 'a.jpg'): Media
{
    return MediaUploader::source(UploadedFile::fake()->image($name))->upload();
}

// ─── Single rule ────────────────────────────────────────────────────

it('attaches when a single anonymous rule passes', function () {
    $this->subject->addMediaChannel('hero')
        ->acceptsFile(fn (Media $m) => $m->mime_type === 'image/jpeg');

    $media = uploadImage();

    $this->subject->attachMedia($media, 'hero');

    expect($this->subject->getMedia('hero'))->toHaveCount(1);
});

it('throws when a single anonymous rule fails', function () {
    $this->subject->addMediaChannel('hero')
        ->acceptsFile(fn (Media $m) => false);

    $media = uploadImage();

    expect(fn () => $this->subject->attachMedia($media, 'hero'))
        ->toThrow(MediaNotAcceptedByChannel::class);

    expect($this->subject->getMedia('hero'))->toHaveCount(0);
});

// ─── Stacked rules ──────────────────────────────────────────────────

it('stacks rules with implicit AND', function () {
    $this->subject->addMediaChannel('hero')
        ->acceptsFile(fn (Media $m) => true)
        ->acceptsFile(fn (Media $m) => true)
        ->acceptsFile(fn (Media $m) => true);

    $this->subject->attachMedia(uploadImage(), 'hero');

    expect($this->subject->getMedia('hero'))->toHaveCount(1);
});

it('rejects when any stacked rule fails', function () {
    $this->subject->addMediaChannel('hero')
        ->acceptsFile(fn (Media $m) => true)
        ->acceptsFile('blocker', fn (Media $m) => false)
        ->acceptsFile(fn (Media $m) => true);

    try {
        $this->subject->attachMedia(uploadImage(), 'hero');
        $this->fail('Expected MediaNotAcceptedByChannel to be thrown');
    } catch (MediaNotAcceptedByChannel $e) {
        expect($e->rule)->toBe('blocker');
    }
});

// ─── Named rules and exception payload ──────────────────────────────

it('carries rule name, channel and mediaId on the exception', function () {
    $this->subject->addMediaChannel('hero')
        ->acceptsFile('jpeg-only', fn (Media $m) => $m->mime_type === 'image/png');

    $media = uploadImage();

    try {
        $this->subject->attachMedia($media, 'hero');
        $this->fail('Expected MediaNotAcceptedByChannel to be thrown');
    } catch (MediaNotAcceptedByChannel $e) {
        expect($e->rule)->toBe('jpeg-only')
            ->and($e->channel)->toBe('hero')
            ->and($e->mediaId)->toBe($media->getKey());
    }
});

it('leaves rule null on the exception for anonymous failures', function () {
    $this->subject->addMediaChannel('hero')
        ->acceptsFile(fn (Media $m) => false);

    $media = uploadImage();

    try {
        $this->subject->attachMedia($media, 'hero');
        $this->fail('Expected MediaNotAcceptedByChannel to be thrown');
    } catch (MediaNotAcceptedByChannel $e) {
        expect($e->rule)->toBeNull()
            ->and($e->mediaId)->toBe($media->getKey());
    }
});

it('rejects acceptsFile with a name but no closure', function () {
    expect(fn () => $this->subject->addMediaChannel('hero')->acceptsFile('only-name'))
        ->toThrow(InvalidArgumentException::class);
});

// ─── Reflection-detected $model parameter ───────────────────────────

it('passes the owning model when the rule declares a second parameter', function () {
    $seen = null;

    $this->subject->addMediaChannel('hero')
        ->acceptsFile(function (Media $m, $model) use (&$seen) {
            $seen = $model;

            return true;
        });

    $this->subject->attachMedia(uploadImage(), 'hero');

    expect($seen)->toBe($this->subject);
});

// ─── Fast path single INSERT ────────────────────────────────────────

it('keeps property-only rules on the single-INSERT fast path', function () {
    $this->subject->addMediaChannel('hero')
        ->acceptsFile(fn (Media $m) => true);

    $a = uploadImage('a.jpg');
    $b = uploadImage('b.jpg');
    $c = uploadImage('c.jpg');

    DB::enableQueryLog();

    $this->subject->attachMedia([$a->getKey(), $b->getKey(), $c->getKey()], 'hero');

    $inserts = collect(DB::getQueryLog())
        ->filter(fn ($entry) => str_starts_with(strtolower(trim($entry['query'])), 'insert into "mediaman_mediables"'))
        ->count();

    DB::disableQueryLog();

    expect($inserts)->toBe(1)
        ->and($this->subject->getMedia('hero'))->toHaveCount(3);
});

// ─── Aggregate path: incremental count ──────────────────────────────

it('runs aggregate rules incrementally so count() grows mid-batch', function () {
    $this->subject->addMediaChannel('gallery')
        ->acceptsFile('max-2', fn (Media $m, $model) => $model->getMedia('gallery')->count() < 2);

    $a = uploadImage('a.jpg');
    $b = uploadImage('b.jpg');
    $c = uploadImage('c.jpg');

    try {
        $this->subject->attachMedia([$a->getKey(), $b->getKey(), $c->getKey()], 'gallery');
        $this->fail('Expected MediaNotAcceptedByChannel');
    } catch (MediaNotAcceptedByChannel $e) {
        expect($e->rule)->toBe('max-2')
            ->and($e->mediaId)->toBe($c->getKey());
    }

    // Rollback: nothing in the batch landed.
    expect($this->subject->getMedia('gallery'))->toHaveCount(0);
});

it('keeps fully-passing aggregate batches attached', function () {
    $this->subject->addMediaChannel('gallery')
        ->acceptsFile('max-3', fn (Media $m, $model) => $model->getMedia('gallery')->count() < 3);

    $a = uploadImage('a.jpg');
    $b = uploadImage('b.jpg');

    $this->subject->attachMedia([$a->getKey(), $b->getKey()], 'gallery');

    expect($this->subject->getMedia('gallery'))->toHaveCount(2);
});

// ─── Lock SQL (skipped on SQLite) ───────────────────────────────────

it('emits SELECT ... FOR UPDATE in the aggregate path', function () {
    if (DB::connection()->getDriverName() === 'sqlite') {
        $this->markTestSkipped('SQLite compiles lockForUpdate to an empty string; behavior covered by aggregate count tests.');
    }

    $this->subject->addMediaChannel('gallery')
        ->acceptsFile('max', fn (Media $m, $model) => $model->getMedia('gallery')->count() < 10);

    DB::enableQueryLog();

    $this->subject->attachMedia(uploadImage()->getKey(), 'gallery');

    $log = collect(DB::getQueryLog())->pluck('query');

    DB::disableQueryLog();

    expect($log->some(fn ($q) => str_contains(strtolower($q), 'for update')))->toBeTrue();
});

// ─── Conversion dispatch timing ─────────────────────────────────────

it('does not dispatch PerformConversions when a rule rejects the attach', function () {
    Bus::fake();

    $this->subject->addMediaChannel('hero')
        ->performConversions('thumbnail')
        ->acceptsFile(fn (Media $m) => false);

    $media = uploadImage();

    try {
        $this->subject->attachMedia($media, 'hero');
    } catch (MediaNotAcceptedByChannel) {
        // expected
    }

    Bus::assertNotDispatched(PerformConversions::class);
});

it('dispatches PerformConversions for attached items in the rule paths', function () {
    Bus::fake();

    $this->subject->addMediaChannel('hero')
        ->performConversions('thumbnail')
        ->acceptsFile(fn (Media $m) => true);

    $this->subject->attachMedia(uploadImage(), 'hero');

    Bus::assertDispatched(PerformConversions::class);
});

it('redispatches PerformConversions for already-attached media on re-sync (rules path)', function () {
    Bus::fake();

    $this->subject->addMediaChannel('hero')
        ->performConversions('thumbnail')
        ->acceptsFile(fn (Media $m) => true);

    $media = uploadImage();

    $this->subject->attachMedia($media, 'hero');
    Bus::assertDispatched(PerformConversions::class, 1);

    // syncMedia called again with the same id — already attached, becomes
    // "updated". Conversions should still dispatch (matches legacy intent of
    // "ensure these conversions exist for the requested set").
    $this->subject->syncMedia($media->getKey(), 'hero');

    Bus::assertDispatched(PerformConversions::class, 2);
});

// ─── Aggregate path validation of inputs ────────────────────────────

it('throws InvalidArgumentException for non-existent ids across all attach paths', function (string $channel, ?Closure $rule, ?Closure $modelRule) {
    $registration = $this->subject->addMediaChannel($channel);

    if ($rule !== null) {
        $registration->acceptsFile($rule);
    }
    if ($modelRule !== null) {
        $registration->acceptsFile('max', $modelRule);
    }

    $a = uploadImage('a.jpg');

    // All three attach paths (legacy, fast, aggregate) validate ids up-front
    // and throw the same exception type for the same condition — independent
    // of the connection's FK enforcement.
    expect(fn () => $this->subject->attachMedia([$a->getKey(), 99999], $channel))
        ->toThrow(InvalidArgumentException::class);

    expect($this->subject->getMedia($channel))->toHaveCount(0);
})->with([
    'legacy (no rules)' => ['legacy', null, null],
    'fast path (property rule)' => ['fast', fn (Media $m) => true, null],
    'aggregate (model rule)' => ['aggregate', null, fn (Media $m, $model) => $model->getMedia('aggregate')->count() < 10],
]);

// ─── Post-attach dispatch is outside the catch ──────────────────────

it('does not mask a successful attach when conversion dispatch fails', function () {
    $this->subject->addMediaChannel('hero')
        ->performConversions('thumbnail')
        ->acceptsFile(fn (Media $m) => true);

    $media = uploadImage();

    // Force the dispatch path to throw by pointing the queue at a missing
    // connection. The attach has already committed by the time dispatch
    // runs, so the exception must propagate — previously it was caught by
    // syncMedia's catch(Throwable) and surfaced as a silent null return.
    config(['queue.default' => 'nonexistent-connection']);

    $threw = false;
    try {
        $this->subject->attachMedia($media, 'hero');
    } catch (Throwable) {
        $threw = true;
    }

    expect($threw)->toBeTrue()
        ->and($this->subject->getMedia('hero'))->toHaveCount(1);
});

// ─── Channel without rules — legacy untouched ───────────────────────

it('leaves the legacy attach path untouched when the channel has no rules', function () {
    $this->subject->addMediaChannel('hero');

    $a = uploadImage('a.jpg');
    $b = uploadImage('b.jpg');

    $this->subject->attachMedia([$a->getKey(), $b->getKey()], 'hero');

    expect($this->subject->getMedia('hero'))->toHaveCount(2);
});

// ─── Channel isolation ──────────────────────────────────────────────

it('does not run hero rules when attaching to a different channel', function () {
    $this->subject->addMediaChannel('hero')
        ->acceptsFile(fn (Media $m) => false);
    $this->subject->addMediaChannel('avatar');

    $media = uploadImage();

    // hero would reject, but avatar has no rules.
    $this->subject->attachMedia($media, 'avatar');

    expect($this->subject->getMedia('avatar'))->toHaveCount(1)
        ->and($this->subject->getMedia('hero'))->toHaveCount(0);
});

// ─── Media stays in library on rejection ────────────────────────────

it('keeps rejected media available in the library for re-attach', function () {
    Event::fake([MediaUploaded::class]);

    $this->subject->addMediaChannel('hero')
        ->acceptsFile(fn (Media $m) => false);
    $this->subject->addMediaChannel('archive');

    $media = uploadImage();

    try {
        $this->subject->attachMedia($media, 'hero');
    } catch (MediaNotAcceptedByChannel) {
        // expected
    }

    // The Media record is still there — re-attach somewhere else works.
    expect($media->fresh())->not->toBeNull();

    $this->subject->attachMedia($media, 'archive');

    expect($this->subject->getMedia('archive'))->toHaveCount(1);
});
