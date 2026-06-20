<?php

namespace Emaia\MediaMan\Exceptions;

use Exception;

class MediaNotAcceptedByChannel extends Exception
{
    public function __construct(
        public readonly string $channel,
        public readonly ?string $rule,
        public readonly int|string $mediaId,
        string $message,
    ) {
        parent::__construct($message);
    }

    public static function ruleFailed(string $channel, int|string $mediaId, ?string $rule = null): self
    {
        $which = $rule !== null ? "rule `{$rule}`" : 'a file rule';

        return new self(
            $channel,
            $rule,
            $mediaId,
            "Media #{$mediaId} rejected by channel `{$channel}`: {$which} failed.",
        );
    }
}
