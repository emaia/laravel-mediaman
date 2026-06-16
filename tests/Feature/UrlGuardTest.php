<?php

use Emaia\MediaMan\Exceptions\UrlNotAllowed;
use Emaia\MediaMan\Support\UrlGuard;
use Illuminate\Support\Facades\Config;

beforeEach(function () {
    Config::set('mediaman.url_sources.allow_private_hosts', false);
});

// --- Scheme validation ---

it('allows standard http URL', function () {
    UrlGuard::check('https://example.com/file.jpg');
})->throwsNoExceptions();

it('allows standard https URL', function () {
    UrlGuard::check('https://example.com/file.jpg');
})->throwsNoExceptions();

it('rejects ftp scheme', function () {
    UrlGuard::check('ftp://example.com/file.txt');
})->throws(UrlNotAllowed::class);

it('rejects file scheme', function () {
    UrlGuard::check('file:///etc/passwd');
})->throws(UrlNotAllowed::class);

it('rejects URL with no scheme', function () {
    UrlGuard::check('//example.com/file.jpg');
})->throws(UrlNotAllowed::class);

// --- Hostname blocking ---

it('rejects localhost hostname', function () {
    UrlGuard::check('http://localhost/admin');
})->throws(UrlNotAllowed::class);

it('rejects localhost with trailing dot', function () {
    UrlGuard::check('http://localhost./admin');
})->throws(UrlNotAllowed::class);

it('rejects subdomain of localhost', function () {
    UrlGuard::check('http://app.localhost/admin');
})->throws(UrlNotAllowed::class);

// --- IPv4 private ranges ---

it('rejects 127.0.0.1 loopback', function () {
    UrlGuard::check('http://127.0.0.1/api');
})->throws(UrlNotAllowed::class);

it('rejects 10.x.x.x private range', function () {
    UrlGuard::check('http://10.0.0.1/api');
})->throws(UrlNotAllowed::class);

it('rejects 172.16.x.x private range start', function () {
    UrlGuard::check('http://172.16.0.1/api');
})->throws(UrlNotAllowed::class);

it('rejects 172.31.x.x private range end', function () {
    UrlGuard::check('http://172.31.255.254/api');
})->throws(UrlNotAllowed::class);

it('rejects 192.168.x.x private range', function () {
    UrlGuard::check('http://192.168.1.1/api');
})->throws(UrlNotAllowed::class);

it('rejects 169.254.x.x AWS/GCP metadata range', function () {
    UrlGuard::check('http://169.254.169.254/latest/meta-data/');
})->throws(UrlNotAllowed::class);

it('rejects 0.0.0.0', function () {
    UrlGuard::check('http://0.0.0.0/api');
})->throws(UrlNotAllowed::class);

it('rejects 255.255.255.255 broadcast', function () {
    UrlGuard::check('http://255.255.255.255/api');
})->throws(UrlNotAllowed::class);

// --- IPv6 blocking ---

it('rejects IPv6 loopback ::1', function () {
    UrlGuard::check('http://[::1]/api');
})->throws(UrlNotAllowed::class);

it('rejects IPv6 unspecified ::', function () {
    UrlGuard::check('http://[::]/api');
})->throws(UrlNotAllowed::class);

it('rejects IPv6 link-local fe80::', function () {
    UrlGuard::check('http://[fe80::1]/api');
})->throws(UrlNotAllowed::class);

it('rejects IPv6 unique local fc00::', function () {
    UrlGuard::check('http://[fc00::1]/api');
})->throws(UrlNotAllowed::class);

it('rejects IPv6 unique local fd00::', function () {
    UrlGuard::check('http://[fd00::1]/api');
})->throws(UrlNotAllowed::class);

it('rejects IPv4-mapped IPv6 ::ffff:127.0.0.1', function () {
    UrlGuard::check('http://[::ffff:127.0.0.1]/api');
})->throws(UrlNotAllowed::class);

it('rejects 6to4 tunnel 2002::', function () {
    UrlGuard::check('http://[2002::1]/api');
})->throws(UrlNotAllowed::class);

it('rejects Teredo tunnel 2001::', function () {
    UrlGuard::check('http://[2001::1]/api');
})->throws(UrlNotAllowed::class);

// --- URL with no host ---

it('rejects URL with no host', function () {
    UrlGuard::check('http://');
})->throws(UrlNotAllowed::class);

// --- allow_private_hosts bypass ---

it('allows localhost when allow_private_hosts is true', function () {
    Config::set('mediaman.url_sources.allow_private_hosts', true);

    UrlGuard::check('http://localhost/admin');
})->throwsNoExceptions();

it('allows 127.0.0.1 when allow_private_hosts is true', function () {
    Config::set('mediaman.url_sources.allow_private_hosts', true);

    UrlGuard::check('http://127.0.0.1/api');
})->throwsNoExceptions();

it('allows 10.x.x.x when allow_private_hosts is true', function () {
    Config::set('mediaman.url_sources.allow_private_hosts', true);

    UrlGuard::check('http://10.0.0.1/api');
})->throwsNoExceptions();

it('allows 169.254.169.254 when allow_private_hosts is true', function () {
    Config::set('mediaman.url_sources.allow_private_hosts', true);

    UrlGuard::check('http://169.254.169.254/metadata');
})->throwsNoExceptions();

// --- DNS resolution ---

it('rejects host that resolves to a private IP via DNS', function () {
    Config::set('mediaman.url_sources.allow_private_hosts', false);

    UrlGuard::check('http://localhost.localdomain/api');
})->throws(UrlNotAllowed::class);

it('rejects host with mixed public and private A records', function () {
    Config::set('mediaman.url_sources.allow_private_hosts', false);

    $resolved = gethostbynamel('localhost.localdomain');

    if ($resolved === false || count($resolved) < 2) {
        $this->markTestSkipped('localhost.localdomain did not return multiple IPs in this environment.');
    }

    UrlGuard::check('http://localhost.localdomain/api');
})->throws(UrlNotAllowed::class);

// --- Exception messages ---

it('includes the scheme in the rejection message', function () {
    try {
        UrlGuard::check('ftp://example.com/file');
        $this->fail('Expected UrlNotAllowed to be thrown');
    } catch (UrlNotAllowed $e) {
        expect($e->getMessage())->toContain('ftp');
    }
});

it('includes the host in the rejection message', function () {
    try {
        UrlGuard::check('http://127.0.0.1/api');
        $this->fail('Expected UrlNotAllowed to be thrown');
    } catch (UrlNotAllowed $e) {
        expect($e->getMessage())->toContain('127.0.0.1');
    }
});
