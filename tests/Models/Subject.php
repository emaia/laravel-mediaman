<?php

namespace Emaia\MediaMan\Tests\Models;

use Emaia\MediaMan\Traits\HasMedia;
use Illuminate\Database\Eloquent\Model;

class Subject extends Model
{
    use HasMedia;

    public function registerMediaChannels()
    {
        $this->addMediaChannel('converted-images')
            ->performConversions('conversion');
    }
}
