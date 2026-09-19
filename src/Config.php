<?php
declare(strict_types=1);

namespace Esky;

/**
 * Reads ESKY_URL and ESKY_TOKEN from the environment, falling back to a .env
 * file in the project directory. The environment takes precedence.
 */
final class Config
{
    public function __construct(
        public readonly string $url,
        public readonly string $token,
    ) {
    }

    public static function fromEnvironment(string $projectDir): self
    {
        $file = [];
        $path = rtrim($projectDir, '/') . '/.env';
        if (is_readable($path)) {
            $parsed = parse_ini_file($path, false, INI_SCANNER_TYPED);
            if (is_array($parsed)) {
                $file = $parsed;
            }
        }

        $url = self::value('ESKY_URL', $file);
        $token = self::value('ESKY_TOKEN', $file);

        if ($url === null) {
            throw new EskyException('Missing required configuration: ESKY_URL');
        }
        if ($token === null) {
            throw new EskyException('Missing required configuration: ESKY_TOKEN');
        }

        return new self($url, $token);
    }

    private static function value(string $name, array $file): ?string
    {
        $env = getenv($name);
        if (is_string($env) && $env !== '') {
            return $env;
        }

        $val = $file[$name] ?? null;

        return is_string($val) && $val !== '' ? $val : null;
    }
}
