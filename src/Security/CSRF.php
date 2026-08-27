<?php

declare(strict_types=1);

namespace CodeCTRL\Apollo\Security;

use CodeCTRL\Apollo\UI\Form\Form;

/**
 * Per-form CSRF tokens.
 *
 * Three things were wrong before 3.3.0 and are worth naming, because applications may
 * have worked around them:
 *
 *  - generateToken() read $_SESSION without an isset() guard and only produced a token
 *    when $regenerate was true, so the first call emitted a null token and a notice.
 *  - the hidden input was named "token" while formValidate() read "_csrf", so form
 *    validation never actually matched.
 *  - comparison used ===, which is not constant time.
 *
 * The field is now named _csrf. A second hidden input named "token" is still emitted so
 * that templates and controllers reading the old name keep working, and verification
 * accepts either. That shim is deprecated and will be removed in 4.0.
 */
class CSRF
{
    /** Canonical field name. */
    public const FIELD = '_csrf';

    /**
     * Field name emitted up to 3.2.x.
     *
     * @deprecated 3.3.0 Accepted on input and still emitted, removed in 4.0.
     */
    public const LEGACY_FIELD = 'token';

    private const SESSION_PREFIX = '_csrf__';

    /**
     * Returns the token for a form, creating one on first use.
     *
     * @param string $formId
     * @param bool $withHtml Return ready to insert hidden inputs instead of the raw token.
     * @param bool $regenerate Force a new token, invalidating any form already rendered.
     * @return string
     */
    public static function generateToken($formId, $withHtml = false, $regenerate = false): string
    {
        $key = self::SESSION_PREFIX . $formId;

        if ($regenerate || empty($_SESSION[$key]) || !is_string($_SESSION[$key])) {
            $_SESSION[$key] = bin2hex(random_bytes(32));
        }

        $token = $_SESSION[$key];

        return $withHtml ? self::field((string)$formId) : $token;
    }

    /**
     * The hidden inputs for a form. Emits both the canonical and the legacy field name;
     * drop the legacy one in 4.0.
     *
     * @param string $formId
     * @return string
     */
    public static function field(string $formId): string
    {
        $token = htmlspecialchars(self::generateToken($formId), ENT_QUOTES, 'UTF-8');

        return sprintf('<input type="hidden" name="%s" value="%s">', self::FIELD, $token)
            . sprintf('<input type="hidden" name="%s" value="%s">', self::LEGACY_FIELD, $token);
    }

    /**
     * @param string $formId
     * @param string|null $token
     * @return bool
     */
    public static function verifyToken($formId, $token): bool
    {
        $key = self::SESSION_PREFIX . $formId;

        if (empty($_SESSION[$key]) || !is_string($_SESSION[$key]) || !is_string($token) || $token === '') {
            return false;
        }

        return hash_equals($_SESSION[$key], $token);
    }

    /**
     * Pulls the submitted token out of a payload, accepting the legacy field name.
     *
     * @param array<string, mixed> $data
     * @return string|null
     */
    public static function tokenFrom(array $data): ?string
    {
        foreach (array(self::FIELD, self::LEGACY_FIELD) as $field) {
            if (isset($data[$field]) && is_string($data[$field]) && $data[$field] !== '') {
                return $data[$field];
            }
        }

        return null;
    }

    /**
     * Invalidate a form's token, e.g. after a successful single use submission.
     *
     * @param string $formId
     */
    public static function forget(string $formId): void
    {
        unset($_SESSION[self::SESSION_PREFIX . $formId]);
    }

    /**
     * @param Form $form
     * @return array<string, array<int, string>>
     */
    public static function formValidate(Form $form): array
    {
        $formId = (string)$form->getAttribute('name');
        $token = self::tokenFrom((array)$form->getData());

        if (!self::verifyToken($formId, $token)) {
            // Deliberately does not echo the submitted token: the previous message
            // reflected attacker controlled input straight back into the response.
            return array('submit' => array('Invalid CSRF token, please refresh the page.'));
        }

        return array();
    }
}
