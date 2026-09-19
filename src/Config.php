<?php
declare(strict_types=1);

namespace Esky;

/**
 * Reads ESKY_URL and ESKY_TOKEN from the environment, falling back to a .env
 * file in the project directory. The environment takes precedence.
 *
 * The REST surface (`/api/{profile}/…`, which the metrics page reads) lives on
 * the same host and behind the same token as the MCP endpoint, so both the base
 * and the profile name are derived from ESKY_URL rather than configured twice
 * and left to drift. ESKY_API_URL and ESKY_PROFILE override the derivation for
 * a deployment where that assumption does not hold.
 */
final class Config
{
    public readonly string $apiBase;
    public readonly ?string $profile;

    public function __construct(
        public readonly string $url,
        public readonly string $token,
        ?string $apiBase = null,
        ?string $profile = null,
    ) {
        $this->apiBase = rtrim($apiBase ?? self::deriveBase($url), '/');
        $this->profile = $profile ?? self::deriveProfile($url);
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

        return new self(
            $url,
            $token,
            self::value('ESKY_API_URL', $file),
            self::value('ESKY_PROFILE', $file),
        );
    }

    /** Scheme, host and port of the MCP url — the REST paths hang off the same root. */
    private static function deriveBase(string $url): string
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return $url;
        }

        return $parts['scheme'] . '://' . $parts['host']
            . (isset($parts['port']) ? ':' . $parts['port'] : '');
    }

    /** The segment after /mcp/, which is the profile the token grants. */
    private static function deriveProfile(string $url): ?string
    {
        $path = trim((string) (parse_url($url, PHP_URL_PATH) ?? ''), '/');
        $segments = $path === '' ? [] : explode('/', $path);
        $last = end($segments);

        return is_string($last) && $last !== '' && $last !== 'mcp' ? $last : null;
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
