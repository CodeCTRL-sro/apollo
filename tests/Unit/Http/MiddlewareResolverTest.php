<?php

declare(strict_types=1);

namespace CodeCTRL\Apollo\Tests\Unit\Http;

use CodeCTRL\Apollo\Core\Config\Config;
use CodeCTRL\Apollo\Http\Middlewares\CsrfMiddleware;
use CodeCTRL\Apollo\Http\Middlewares\MiddlewareResolver;
use CodeCTRL\Apollo\Http\Middlewares\SecurityHeadersMiddleware;
use CodeCTRL\Apollo\Http\Middlewares\ThrottleMiddleware;
use InvalidArgumentException;
use League\Container\Container;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;

#[CoversClass(MiddlewareResolver::class)]
final class MiddlewareResolverTest extends TestCase
{
    /**
     * @param array<string, mixed> $config
     */
    private function resolver(array $config = array()): MiddlewareResolver
    {
        return new MiddlewareResolver(new Container(), new Config($config));
    }

    public function testBuiltInAliasResolvesToItsMiddleware(): void
    {
        $resolved = $this->resolver()->resolve(array('csrf'));

        self::assertCount(1, $resolved);
        self::assertInstanceOf(CsrfMiddleware::class, $resolved[0]);
    }

    public function testGroupExpandsToItsMembersInOrder(): void
    {
        $resolver = $this->resolver(array(
            'middleware' => array(
                'groups' => array('web' => array('headers', 'csrf')),
            ),
        ));

        $resolved = $resolver->resolve(array('web'));

        self::assertCount(2, $resolved);
        self::assertInstanceOf(SecurityHeadersMiddleware::class, $resolved[0]);
        self::assertInstanceOf(CsrfMiddleware::class, $resolved[1]);
    }

    public function testGroupsMayNestOtherGroups(): void
    {
        $resolver = $this->resolver(array(
            'middleware' => array(
                'groups' => array(
                    'base' => array('headers'),
                    'web' => array('base', 'csrf'),
                ),
            ),
        ));

        self::assertCount(2, $resolver->resolve(array('web')));
    }

    public function testASelfReferencingGroupIsReportedRatherThanLoopingForever(): void
    {
        $resolver = $this->resolver(array(
            'middleware' => array(
                'groups' => array(
                    'a' => array('b'),
                    'b' => array('a'),
                ),
            ),
        ));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/refers to itself/');

        $resolver->resolve(array('a'));
    }

    public function testPositionalArgumentsBecomeOptions(): void
    {
        $resolved = $this->resolver()->resolve(array('throttle:5,120'));

        self::assertInstanceOf(ThrottleMiddleware::class, $resolved[0]);

        $options = (new \ReflectionProperty(ThrottleMiddleware::class, 'options'))->getValue($resolved[0]);
        self::assertSame('5', $options['throttle_limit']);
        self::assertSame('120', $options['throttle_window']);
    }

    public function testCanAliasIsTranslatedToThePermissionPairShape(): void
    {
        $resolved = $this->resolver()->resolve(array('can:users,read'));

        $options = (new \ReflectionProperty($resolved[0]::class, 'options'))->getValue($resolved[0]);

        self::assertSame(array(array('users', 'read')), $options['require_permissions']);
        self::assertArrayNotHasKey('permission_module', $options);
    }

    public function testApplicationAliasOverridesTheBuiltIn(): void
    {
        $resolver = $this->resolver(array(
            'middleware' => array(
                'aliases' => array('csrf' => StubMiddleware::class),
            ),
        ));

        self::assertInstanceOf(StubMiddleware::class, $resolver->resolve(array('csrf'))[0]);
    }

    public function testAFullyQualifiedClassNameWorksWithoutAnAlias(): void
    {
        self::assertInstanceOf(
            StubMiddleware::class,
            $this->resolver()->resolve(array(StubMiddleware::class))[0]
        );
    }

    public function testAnUnknownNameIsReportedClearly(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unknown middleware "nope"/');

        $this->resolver()->resolve(array('nope'));
    }

    public function testAlreadyInstantiatedMiddlewarePassesThrough(): void
    {
        $instance = new StubMiddleware(array());

        self::assertSame($instance, $this->resolver()->resolve(array($instance))[0]);
    }

    public function testEmptyAndNonStringEntriesAreIgnored(): void
    {
        self::assertSame(array(), $this->resolver()->resolve(array('', null, 123)));
    }
}

final class StubMiddleware implements MiddlewareInterface
{
    /**
     * @param array<string, mixed> $options
     */
    public function __construct(public array $options = array())
    {
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        return $handler->handle($request);
    }
}
