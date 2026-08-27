<?php

declare(strict_types=1);

namespace CodeCTRL\Apollo\Tests\Unit\Http;

use CodeCTRL\Apollo\Http\Route\Router;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Router::class)]
final class RouterMergePathsTest extends TestCase
{
    public function testANewPathIsAddedWholesale(): void
    {
        $paths = array();
        Router::mergePaths($paths, array('/users' => array('methods' => array('GET' => array('callable' => 'a')))));

        self::assertArrayHasKey('/users', $paths);
        self::assertSame('a', $paths['/users']['methods']['GET']['callable']);
    }

    public function testMethodsOnTheSamePathAreCombined(): void
    {
        $paths = array('/users' => array('methods' => array('GET' => array('callable' => 'index'))));

        Router::mergePaths($paths, array('/users' => array('methods' => array('POST' => array('callable' => 'store')))));

        self::assertSame('index', $paths['/users']['methods']['GET']['callable']);
        self::assertSame('store', $paths['/users']['methods']['POST']['callable']);
    }

    public function testTheSameMethodIsOverwritten(): void
    {
        $paths = array('/users' => array('methods' => array('GET' => array('callable' => 'old'))));

        Router::mergePaths($paths, array('/users' => array('methods' => array('GET' => array('callable' => 'new')))));

        self::assertSame('new', $paths['/users']['methods']['GET']['callable']);
    }

    public function testNestedPathsAreMergedRatherThanReplaced(): void
    {
        $paths = array(
            '/users' => array(
                'paths' => array('/{id}' => array('methods' => array('GET' => array('callable' => 'show')))),
            ),
        );

        Router::mergePaths($paths, array(
            '/users' => array(
                'paths' => array('/export' => array('methods' => array('GET' => array('callable' => 'export')))),
            ),
        ));

        self::assertArrayHasKey('/{id}', $paths['/users']['paths']);
        self::assertArrayHasKey('/export', $paths['/users']['paths']);
    }

    public function testExistingOptionsOnAPathSurviveAMerge(): void
    {
        $paths = array('/admin' => array('require_auth' => true, 'methods' => array('GET' => array('callable' => 'a'))));

        Router::mergePaths($paths, array('/admin' => array('methods' => array('POST' => array('callable' => 'b')))));

        self::assertTrue($paths['/admin']['require_auth']);
    }
}
