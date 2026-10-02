<?php
declare(strict_types=1);

namespace Esky;

/**
 * One random token per session, embedded in the forget form and checked on
 * POST. The app has no login, so this is what stops another page from
 * submitting the form on a visitor's behalf.
 */
final class Csrf
{
    public static function token(): string
    {
        $token = Session::csrfToken();
        if ($token === null) {
            $token = bin2hex(random_bytes(32));
            Session::setCsrfToken($token);
        }

        return $token;
    }

    /** Takes mixed because the value comes straight from $_POST. */
    public static function verify(mixed $submitted): bool
    {
        $expected = Session::csrfToken();

        return $expected !== null
            && is_string($submitted)
            && $submitted !== ''
            && hash_equals($expected, $submitted);
    }
}
