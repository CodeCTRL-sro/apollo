<?php

declare(strict_types=1);

namespace CodeCTRL\Apollo\Tests\Unit\Utility;

use CodeCTRL\Apollo\Utility\Helper\PasswordHelper;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(PasswordHelper::class)]
final class PasswordHelperTest extends TestCase
{
    public function testScoreStaysInsideTheDocumentedRange(): void
    {
        foreach (array('', 'a', 'password', 'Str0ng!Passw0rd#2026', str_repeat('Aa1!', 20)) as $password) {
            $strength = PasswordHelper::calculateStrength($password);

            self::assertGreaterThanOrEqual(1, $strength);
            self::assertLessThanOrEqual(5, $strength);
        }
    }

    public function testStrongerPasswordsScoreHigher(): void
    {
        self::assertGreaterThan(
            PasswordHelper::calculateStrength('password'),
            PasswordHelper::calculateStrength('P4ssw0rd!Longer#2026')
        );
    }

    public function testRepetitionAndSequencesArePenalised(): void
    {
        self::assertLessThanOrEqual(
            PasswordHelper::calculateStrength('Kt9#mVx2qLp7'),
            PasswordHelper::calculateStrength('Abcdefgh1234')
        );
    }

    public function testAcceptableThresholdIsReachableByARealisticPassword(): void
    {
        self::assertGreaterThanOrEqual(
            PasswordHelper::ACCEPTABLE_PASSWORD,
            PasswordHelper::calculateStrength('Tr0ub4dour&3xtra')
        );
    }
}
