<?php

namespace Emaia\MediaMan\Tests\Models;

use Emaia\MediaMan\Models\Media;
use Emaia\MediaMan\Traits\HasMedia;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class ThrowingSubject extends Model
{
    use HasMedia;

    protected $table = 'subjects';

    /**
     * When set, the next call to getMedia() throws and resets the flag.
     * getMedia() is called inside syncMedia's try/catch block, so this
     * lets tests exercise the catch logic directly.
     */
    public static ?\Throwable $throwOnGetMedia = null;

    public function registerMediaChannels(): void
    {
        $this->addMediaChannel('default');
    }

    public function getMedia(?string $channel = Media::DEFAULT_CHANNEL): Collection
    {
        if (self::$throwOnGetMedia !== null) {
            $exception = self::$throwOnGetMedia;
            self::$throwOnGetMedia = null;

            throw $exception;
        }

        return parent::getMedia($channel);
    }
}
