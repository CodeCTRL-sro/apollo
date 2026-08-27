<?php

declare(strict_types=1);

namespace CodeCTRL\Apollo\Tests\Unit\Security;

use CodeCTRL\Apollo\Security\RedirectGuard;
use Laminas\Diactoros\ServerRequest;
use Laminas\Diactoros\Uri;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

#[CoversClass(RedirectGuard::class)]
final class RedirectGuardTest extends TestCase
{
    /**
     * @return array<string, array{0: string}>
     */
    public static function hostileUrlProvider(): array
    {
        return array(
            'absolute http' => array('http://evil.example/'),
            'absolute https' => array('https://evil.example/'),
            'protocol relative' => array('//evil.example/'),
            'backslash relative' => array('/\\evil.example/'),
            'scheme only' => array('javascript:alert(1)'),
            'data uri' => array('data:text/html,<script>alert(1)</script>'),
            'not rooted' => array('dashboard'),
            'empty' => array(''),
            'newline injection' => array("/dashboard\r\nSet-Cookie: a=b"),
            'null byte' => array("/dashboard\0"),
            'tab' => array("/dash\tboard"),
        );
    }

    #[DataProvider('hostileUrlProvider')]
    public function testHostileUrlsAreRejected(string $url): void
    {
        self::assertNull(RedirectGuard::sanitize($url));
    }

    public function testOverlongUrlIsRejected(): void
    {
        self::assertNull(RedirectGuard::sanitize('/' . str_repeat('a', 2048)));
    }

    public function testPlainLocalPathsAreAccepted(): void
    {
        self::assertSame('/dashboard', RedirectGuard::sanitize('/dashboard'));
        self::assertSame('/dashboard?page=2', RedirectGuard::sanitize('/dashboard?page=2'));
        self::assertSame('/', RedirectGuard::sanitize('/'));
    }

    public function testUrlMustStayInsideTheBasepath(): void
    {
        self::assertSame('/admin/users', RedirectGuard::sanitize('/admin/users', '/admin'));
        self::assertSame('/admin', RedirectGuard::sanitize('/admin', '/admin'));
        self::assertNull(RedirectGuard::sanitize('/public/users', '/admin'));
        // A prefix match must not be enough: /administrator is not inside /admin.
        self::assertNull(RedirectGuard::sanitize('/administrator', '/admin'));
    }

    public function testDeniedPathsAreRejectedIgnoringQueryAndTrailingSlash(): void
    {
        $deny = array('/login');

        self::assertNull(RedirectGuard::sanitize('/login', '/', $deny));
        self::assertNull(RedirectGuard::sanitize('/login/', '/', $deny));
        self::assertNull(RedirectGuard::sanitize('/login?next=/x', '/', $deny));
        self::assertSame('/logout', RedirectGuard::sanitize('/logout', '/', $deny));
    }

    public function testFromRequestOnlyHonoursGet(): void
    {
        $request = new ServerRequest(uri: new Uri('/dashboard?page=2'), method: 'GET');
        self::assertSame('/dashboard?page=2', RedirectGuard::fromRequest($request));

        $post = new ServerRequest(uri: new Uri('/dashboard'), method: 'POST');
        self::assertNull(RedirectGuard::fromRequest($post));

        self::assertNull(RedirectGuard::fromRequest(null));
    }

    public function testAppendToBuildsTheRedirectParameter(): void
    {
        self::assertSame(
            '/login?redirect=%2Fdashboard',
            RedirectGuard::appendTo('/login', '/dashboard')
        );
        self::assertSame(
            '/login?a=b&redirect=%2Fdashboard',
            RedirectGuard::appendTo('/login?a=b', '/dashboard')
        );
        self::assertSame('/login', RedirectGuard::appendTo('/login', null));
    }
}
