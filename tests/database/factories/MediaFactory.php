<?php

namespace Emaia\MediaMan\Database\Factories;

use Emaia\MediaMan\Models\Media;
use Illuminate\Database\Eloquent\Factories\Factory;

class MediaFactory extends Factory
{
    protected $model = Media::class;

    public function definition(): array
    {
        return [
            'name' => fake()->word(),
            'file_name' => 'file-name.png',
            'disk' => config('mediaman.disk'),
            'mime_type' => 'image/png',
            'size' => fake()->randomNumber(4),
        ];
    }
}
