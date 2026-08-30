<?php

declare(strict_types=1);

namespace PrivateCaptcha\Typo3\Tests\Unit\Configuration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use PrivateCaptcha\Typo3\Configuration\CustomDomainValidator;

final class CustomDomainValidatorTest extends TestCase
{
    #[Test]
    #[DataProvider('validEndpointRootProvider')]
    public function normalizesValidEndpointCustomRoots(string $input, string $expected): void
    {
        self::assertSame($expected, (new CustomDomainValidator())->validate($input));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validEndpointRootProvider(): iterable
    {
        yield 'not configured' => ['', ''];
        yield 'spaces only' => ['   ', ''];
        yield 'public root' => ['captcha.privatecaptcha.com', 'captcha.privatecaptcha.com'];
        yield 'normalized case and spaces' => ['  Captcha.PrivateCaptcha.COM  ', 'captcha.privatecaptcha.com'];
        yield 'punycode label' => ['xn--bcher-kva.de', 'xn--bcher-kva.de'];
        yield 'maximum label length' => [str_repeat('a', 63) . '.com', str_repeat('a', 63) . '.com'];
        yield 'maximum derived host length' => [self::maximumLengthRoot(), self::maximumLengthRoot()];
    }

    #[Test]
    #[DataProvider('invalidEndpointRootProvider')]
    public function rejectsUnsafeEndpointCustomRoots(string $input): void
    {
        $this->expectException(\InvalidArgumentException::class);

        (new CustomDomainValidator())->validate($input);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function invalidEndpointRootProvider(): iterable
    {
        yield 'HTTPS scheme' => ['https://captcha.privatecaptcha.com'];
        yield 'HTTP scheme' => ['http://captcha.privatecaptcha.com'];
        yield 'credentials' => ['user@captcha.privatecaptcha.com'];
        yield 'path' => ['captcha.privatecaptcha.com/puzzle'];
        yield 'query' => ['captcha.privatecaptcha.com?region=eu'];
        yield 'fragment' => ['captcha.privatecaptcha.com#widget'];
        yield 'port' => ['captcha.privatecaptcha.com:8443'];
        yield 'wildcard' => ['*.privatecaptcha.com'];
        yield 'IPv4 literal' => ['192.0.2.1'];
        yield 'IPv6 literal' => ['2001:db8::1'];
        yield 'bracketed IPv6 literal' => ['[2001:db8::1]'];
        yield 'localhost' => ['localhost'];
        yield 'localhost subdomain' => ['captcha.localhost'];
        yield 'single label' => ['intranet'];
        yield 'leading hyphen' => ['-captcha.privatecaptcha.com'];
        yield 'trailing hyphen' => ['captcha-.privatecaptcha.com'];
        yield 'empty label' => ['captcha..privatecaptcha.com'];
        yield 'underscore' => ['private_captcha.privatecaptcha.com'];
        yield 'trailing root dot' => ['captcha.privatecaptcha.com.'];
        yield 'numeric top-level label' => ['captcha.example.123'];
        yield 'oversized label' => [str_repeat('a', 64) . '.privatecaptcha.com'];
        yield 'root too long for derived hosts' => [implode('.', [
            str_repeat('a', 63),
            str_repeat('b', 63),
            str_repeat('c', 63),
            str_repeat('d', 54),
            'com',
        ])];
        yield 'null byte' => ["captcha.privatecaptcha.com\0"];
        yield 'line feed' => ["captcha.privatecaptcha.com\n"];
        yield 'C1 control' => ["captcha.privatecaptcha.com\u{0085}"];
        yield 'malformed UTF-8' => ["captcha.\xC0\xAF.com"];
        yield 'malformed punycode' => ['xn--a.de'];
        yield 'raw Unicode label' => ["b\u{00FC}cher.de"];
        yield 'Unicode full stop' => ["captcha\u{3002}privatecaptcha.com"];
        yield 'encoded path delimiter' => ['captcha.privatecaptcha.com%2Fpuzzle'];
        yield 'reserved local domain' => ['captcha.local'];
        yield 'reserved internal domain' => ['captcha.internal'];
        yield 'reserved mail domain' => ['captcha.mail'];
        yield 'reserved test domain' => ['captcha.test'];
        yield 'reserved invalid domain' => ['captcha.invalid'];
        yield 'reserved example domain' => ['captcha.example'];
        yield 'reserved example com domain' => ['captcha.example.com'];
        yield 'reserved example net domain' => ['captcha.example.net'];
        yield 'reserved example org domain' => ['captcha.example.org'];
        yield 'reserved home domain' => ['captcha.home.arpa'];
        yield 'reserved onion domain' => ['captcha.onion'];
        yield 'undelegated top-level domain' => ['captcha.zz'];
        yield 'private top-level domain' => ['captcha.private'];
    }

    private static function maximumLengthRoot(): string
    {
        return implode('.', [
            str_repeat('a', 63),
            str_repeat('b', 63),
            str_repeat('c', 63),
            str_repeat('d', 53),
            'com',
        ]);
    }
}
