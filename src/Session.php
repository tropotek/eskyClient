<?php
declare(strict_types=1);

namespace Esky;

/**
 * The active vault name and the CSRF token, held in the session and nowhere else.
 *
 * The only file that touches $_SESSION. Kept apart from Vaults so the loader
 * can be tested in CLI, where starting a session is neither possible nor
 * wanted — under CLI this reads and writes the superglobal directly.
 */
final class Session
{
    private const KEY = 'esky_vault';
    private const CSRF_KEY = 'esky_csrf';

    public static function vault(): ?string
    {
        self::start();
        $value = $_SESSION[self::KEY] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function setVault(string $name): void
    {
        self::start();
        $_SESSION[self::KEY] = $name;
    }

    public static function csrfToken(): ?string
    {
        self::start();
        $value = $_SESSION[self::CSRF_KEY] ?? null;

        return is_string($value) && $value !== '' ? $value : null;
    }

    public static function setCsrfToken(string $token): void
    {
        self::start();
        $_SESSION[self::CSRF_KEY] = $token;
    }

    private static function start(): void
    {
        if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
            session_start();
        }
    }
}
