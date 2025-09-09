<?php

use Emaia\MediaMan\Models\Media;
use Faker\Generator as Faker;

$factory->define(Media::class, function (Faker $faker) {
    return [
        'name' => $faker->word,
        'file_name' => 'file-name.png',
        'disk' => config('mediaman.disk'),
        'mime_type' => 'image/png',
        'size' => $faker->randomNumber(4),
    ];
});
