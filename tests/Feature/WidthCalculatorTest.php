<?php

use Emaia\MediaMan\ResponsiveImages\WidthCalculator\BreakpointWidthCalculator;
use Emaia\MediaMan\ResponsiveImages\WidthCalculator\FileSizeOptimizedWidthCalculator;

// --- BreakpointWidthCalculator ---

it('filters breakpoints larger than original width', function () {
    $calculator = new BreakpointWidthCalculator([320, 640, 1024, 1920]);

    $widths = $calculator->calculateWidths(100000, 800, 600);

    expect($widths->toArray())->toContain(320)
        ->toContain(640)
        ->toContain(800) // original always included
        ->not->toContain(1024)
        ->not->toContain(1920);
});

it('always includes original width in breakpoint calculator', function () {
    $calculator = new BreakpointWidthCalculator([320, 640]);

    $widths = $calculator->calculateWidths(100000, 500, 400);

    expect($widths->toArray())->toContain(500);
});

it('returns widths in descending order', function () {
    $calculator = new BreakpointWidthCalculator([320, 640, 1024]);

    $widths = $calculator->calculateWidths(100000, 1200, 900);
    $array = $widths->toArray();

    expect($array)->toEqual(array_values($array)); // values are reindexed
    // Check descending order
    for ($i = 0; $i < count($array) - 1; $i++) {
        expect($array[$i])->toBeGreaterThanOrEqual($array[$i + 1]);
    }
});

it('handles image smaller than smallest breakpoint', function () {
    $calculator = new BreakpointWidthCalculator([320, 640, 1024]);

    $widths = $calculator->calculateWidths(50000, 200, 150);

    // Should only contain the original width
    expect($widths->toArray())->toEqual([200]);
});

it('allows setting custom breakpoints', function () {
    $calculator = new BreakpointWidthCalculator([320, 640]);
    $calculator->setBreakpoints([480, 720, 1080]);

    expect($calculator->getBreakpoints())->toEqual([480, 720, 1080]);

    $widths = $calculator->calculateWidths(100000, 1200, 900);
    expect($widths->toArray())->toContain(480)
        ->toContain(720)
        ->toContain(1080);
});

it('removes duplicate widths', function () {
    $calculator = new BreakpointWidthCalculator([640, 640, 320]);

    $widths = $calculator->calculateWidths(100000, 640, 480);

    // 640 appears in breakpoints AND as original, but should be deduplicated
    $array = $widths->toArray();
    expect(count(array_unique($array)))->toEqual(count($array));
});

// --- FileSizeOptimizedWidthCalculator ---

it('includes original width in file size optimized results', function () {
    $calculator = new FileSizeOptimizedWidthCalculator;

    $widths = $calculator->calculateWidths(500000, 1920, 1080);

    expect($widths->first())->toEqual(1920);
});

it('generates multiple widths for large images', function () {
    $calculator = new FileSizeOptimizedWidthCalculator;

    $widths = $calculator->calculateWidths(500000, 1920, 1080);

    expect($widths->count())->toBeGreaterThan(1);
});

it('stops at minimum width threshold', function () {
    $calculator = new FileSizeOptimizedWidthCalculator;

    $widths = $calculator->calculateWidths(500000, 1920, 1080);

    // All widths should be >= 20 (minimum threshold)
    $widths->each(fn ($w) => expect($w)->toBeGreaterThanOrEqual(20));
});

it('returns widths in descending order for file size calculator', function () {
    $calculator = new FileSizeOptimizedWidthCalculator;

    $widths = $calculator->calculateWidths(500000, 1920, 1080);
    $array = $widths->toArray();

    for ($i = 0; $i < count($array) - 1; $i++) {
        expect($array[$i])->toBeGreaterThanOrEqual($array[$i + 1]);
    }
});

it('handles small images with few widths', function () {
    $calculator = new FileSizeOptimizedWidthCalculator;

    // Small image, small file — should generate very few widths
    $widths = $calculator->calculateWidths(15000, 300, 200);

    expect($widths->count())->toBeGreaterThanOrEqual(1)
        ->and($widths->first())->toEqual(300);
});

it('returns unique widths only', function () {
    $calculator = new FileSizeOptimizedWidthCalculator;

    $widths = $calculator->calculateWidths(500000, 1920, 1080);
    $array = $widths->toArray();

    expect(count(array_unique($array)))->toEqual(count($array));
});
