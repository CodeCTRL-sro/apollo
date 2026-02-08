<?php

namespace CodeCTRL\Apollo\Utility\Helper;

class PasswordHelper
{
    const ACCEPTABLE_PASSWORD = 3;

    public static function calculateStrength(string $password): int {
        $strength = 0;

        // Length (up to 6 points)
        $len = mb_strlen($password, '8bit');
        if ($len >= 8)  $strength += 2;
        if ($len >= 10) $strength += 1;
        if ($len >= 12) $strength += 1;
        if ($len >= 14) $strength += 1;
        if ($len >= 16) $strength += 1;

        // Character variety (up to 4 points)
        if (preg_match('/[a-z]/', $password))              $strength += 1;
        if (preg_match('/[A-Z]/', $password))              $strength += 1;
        if (preg_match('/\d/', $password))                 $strength += 1;
        if (preg_match('/[^a-zA-Z0-9]/', $password))       $strength += 1;

        // Complexity (up to 2 points)
        $hasTwoLower = preg_match('/[a-z].*[a-z]/', $password);
        $hasTwoUpper = preg_match('/[A-Z].*[A-Z]/', $password);
        $hasTwoDigits = preg_match('/\d.*\d/', $password);
        if ($hasTwoLower && $hasTwoUpper && $hasTwoDigits) {
            $strength += 1;
        }
        if (preg_match('/[^a-zA-Z0-9].*[^a-zA-Z0-9]/', $password)) {
            $strength += 1;
        }

        // Deductions
        if (preg_match('/(.)\1{2,}/', $password)) {
            $strength -= 1;
        }
        if (preg_match('/(?:abc|bcd|cde|def|efg|fgh|ghi|hij|ijk|jkl|klm|lmn|mno|nop|opq|pqr|qrs|rst|stu|tuv|uvw|vwx|wxy|xyz)/i', $password)) {
            $strength -= 1;
        }
        if (preg_match('/(?:012|123|234|345|456|567|678|789)/', $password)) {
            $strength -= 1;
        }

        // Strength to level
        if ($strength < 1) {
            $strength = 1;
        }
        $strengthLevel = ceil($strength / 2.4);
        if ($strengthLevel > 5) {
            $strengthLevel = 5;
        }

        return $strengthLevel;
    }
}
