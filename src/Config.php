<?php
declare(strict_types=1);

namespace Esky;

/**
 * One configured esky vault: the MCP endpoint, the token that opens it, and
 * the display name the navbar shows.
 *
 * The REST surface (`/api/{profile}/…`, which the metrics page reads) lives on
 * the same host and behind the same token as the MCP endpoint, so both the base
 * and the profile name are derived from the MCP url rather than configured
 * twice and left to drift. The optional apiUrl and profile fields in
 * config.json override the derivation for a deployment where that assumption
 * does not hold.
 */
final class Config
{
    public readonly string $apiBase;
    public readonly ?string $profile;

    public function __construct(
        public readonly string $name,
        public readonly string $title,
        public readonly string $url,
        public readonly string $token,
        ?string $apiBase = null,
        ?string $profile = null,
    ) {
        $this->apiBase = rtrim($apiBase ?? self::deriveBase($url), '/');
        $this->profile = $profile ?? self::deriveProfile($url);
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
}
