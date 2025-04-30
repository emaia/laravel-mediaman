<?php

namespace Emaia\MediaMan\Tests\Models;


use Illuminate\Database\Eloquent\Model;
use Emaia\MediaMan\Traits\HasMedia;

class Subject extends Model
{
    use HasMedia;

    public function registerMediaChannels()
    {
        $this->addMediaChannel('converted-images')
            ->performConversions('conversion');
    }
}
