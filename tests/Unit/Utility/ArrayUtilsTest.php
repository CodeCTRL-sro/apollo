<?php

declare(strict_types=1);

namespace CodeCTRL\Apollo\Tests\Unit\Utility;

use CodeCTRL\Apollo\Utility\Utils\ArrayUtils;
use CodeCTRL\Apollo\Utility\Utils\ArrayUtils\MergeRemoveKey;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ArrayUtils::class)]
final class ArrayUtilsTest extends TestCase
{
    public function testStringKeysFromTheSecondArrayWin(): void
    {
        self::assertSame(
            array('a' => 2, 'b' => 3),
            ArrayUtils::merge(array('a' => 1, 'b' => 3), array('a' => 2))
        );
    }

    public function testNestedArraysAreMergedRecursively(): void
    {
        $result = ArrayUtils::merge(
            array('route' => array('debug' => false, 'modules' => array('A' => 1))),
            array('route' => array('modules' => array('B' => 2)))
        );

        self::assertSame(
            array('route' => array('debug' => false, 'modules' => array('A' => 1, 'B' => 2))),
            $result
        );
    }

    /**
     * This is what makes config files additive: numeric keys append rather than
     * overwrite, so two config files each listing routes end up with both lists.
     */
    public function testNumericKeysAppendByDefault(): void
    {
        self::assertSame(
            array('a', 'b', 'c'),
            ArrayUtils::merge(array('a', 'b'), array('c'))
        );
    }

    public function testNumericKeysCanBeOverwrittenInstead(): void
    {
        self::assertSame(
            array('c', 'b'),
            ArrayUtils::merge(array('a', 'b'), array('c'), true)
        );
    }

    public function testMergeRemoveKeyDeletesAnExistingKey(): void
    {
        $result = ArrayUtils::merge(
            array('keep' => 1, 'drop' => 2),
            array('drop' => new MergeRemoveKey())
        );

        self::assertSame(array('keep' => 1), $result);
    }

    public function testMergeRemoveKeyForAnAbsentKeyAddsNothing(): void
    {
        $result = ArrayUtils::merge(array('keep' => 1), array('never-there' => new MergeRemoveKey()));

        self::assertSame(array('keep' => 1), $result);
    }

    public function testScalarReplacesArrayAndViceVersa(): void
    {
        self::assertSame(array('a' => 'scalar'), ArrayUtils::merge(array('a' => array(1, 2)), array('a' => 'scalar')));
        self::assertSame(array('a' => array(1)), ArrayUtils::merge(array('a' => 'scalar'), array('a' => array(1))));
    }

    public function testNullValuesAreCarriedOver(): void
    {
        self::assertSame(array('a' => null), ArrayUtils::merge(array('a' => 1), array('a' => null)));
    }
}
