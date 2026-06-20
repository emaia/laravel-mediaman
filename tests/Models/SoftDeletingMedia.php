<?php

namespace Emaia\MediaMan\Tests\Models;

use Emaia\MediaMan\Models\Media;
use Illuminate\Database\Eloquent\SoftDeletes;

class SoftDeletingMedia extends Media
{
    use SoftDeletes;
}
