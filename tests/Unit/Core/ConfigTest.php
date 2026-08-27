<?php

declare(strict_types=1);

namespace CodeCTRL\Apollo\Tests\Unit\Core;

use CodeCTRL\Apollo\Core\Config\Config;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Config::class)]
final class ConfigTest extends TestCase
{
    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        return array(
            'route' => array(
                'debug' => true,
                'modules' => array(
                    'Session' => array(
                        'session_key' => 'user',
                        'entity' => array('user' => 'App\\Entity\\User'),
                    ),
                ),
            ),
            'routing' => array('basepath' => '/admin'),
        );
    }

    public function testGetWalksDimensions(): void
    {
        $config = new Config($this->fixture());

        self::assertTrue($config->get(array('route', 'debug')));
        self::assertSame('user', $config->get(array('route', 'modules', 'Session', 'session_key')));
        self::assertSame('/admin', $config->get('routing')['basepath']);
    }

    public function testGetReturnsTheDefaultForAMissingPath(): void
    {
        $config = new Config($this->fixture());

        self::assertNull($config->get(array('route', 'nope')));
        self::assertSame('fallback', $config->get(array('route', 'nope'), 'fallback'));
        self::assertSame('fallback', $config->get(array('does', 'not', 'exist'), 'fallback'));
    }

    /**
     * A path that ends on a non-array must not be walked into.
     */
    public function testGetDoesNotDescendIntoScalars(): void
    {
        $config = new Config($this->fixture());

        self::assertNull($config->get(array('route', 'debug', 'deeper')));
    }

    public function testHas(): void
    {
        $config = new Config($this->fixture());

        self::assertTrue($config->has('route'));
        self::assertTrue($config->has(array('route', 'modules', 'Session')));
        self::assertFalse($config->has(array('route', 'missing')));
    }

    public function testSetCreatesIntermediateLevels(): void
    {
        $config = new Config($this->fixture());
        $config->set(array('route', 'session', 'samesite'), 'Strict');

        self::assertSame('Strict', $config->get(array('route', 'session', 'samesite')));
    }

    public function testFromDimensionReturnsAnIndependentConfig(): void
    {
        $config = new Config($this->fixture());
        $modules = $config->fromDimension(array('route', 'modules'));

        self::assertInstanceOf(Config::class, $modules);
        self::assertSame('user', $modules->get(array('Session', 'session_key')));

        $modules->set('Session', array('session_key' => 'changed'));
        self::assertSame('user', $config->get(array('route', 'modules', 'Session', 'session_key')));
    }

    public function testSetBaseRelocatesEveryLookup(): void
    {
        $config = new Config($this->fixture());
        $config->setBase(array('route', 'modules'));

        self::assertSame('user', $config->get(array('Session', 'session_key')));
        self::assertTrue($config->has('Session'));
    }

    public function testMergeIsRecursive(): void
    {
        $config = new Config($this->fixture());
        $config->merge(array('route' => array('modules' => array('Session' => array('session_destroy' => false)))));

        self::assertSame('user', $config->get(array('route', 'modules', 'Session', 'session_key')));
        self::assertFalse($config->get(array('route', 'modules', 'Session', 'session_destroy')));
    }

    public function testMergeAcceptsAnotherConfig(): void
    {
        $config = new Config(array('a' => 1));
        $config->merge(new Config(array('b' => 2)));

        self::assertSame(array('a' => 1, 'b' => 2), $config->toArray());
    }
}
