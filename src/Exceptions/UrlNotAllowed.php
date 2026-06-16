<?php

namespace Emaia\MediaMan\Exceptions;

use Exception;

class UrlNotAllowed extends Exception
{
    public static function forScheme(string $scheme): self
    {
        return new self("URL scheme [$scheme] is not allowed.");
    }

    public static function noHost(): self
    {
        return new self('URL has no valid host.');
    }

    public static function forHost(string $host): self
    {
        return new self("URL host [$host] is not allowed.");
    }

    public static function forPrivateIp(string $host): self
    {
        return new self("URL host [$host] resolves to a private or reserved IP address.");
    }
}
