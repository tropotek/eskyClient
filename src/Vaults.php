<?php
declare(strict_types=1);

namespace Esky;

/**
 * The vaults configured in config.json, as Config objects.
 *
 * Vaults are configured in a file rather than through the app because the app
 * has no users and no authentication: a form that stored bearer tokens would
 * be writable by anyone who could reach the port.
 *
 * Nothing here touches the session — Session resolves the active name and
 * passes it to current(), which keeps this loader testable in CLI.
 */
final class Vaults
{
    private const NAME = '/^[a-z0-9_-]+$/';

    /** @param list<Config> $vaults */
    private function __construct(private readonly array $vaults)
    {
    }

    public static function path(string $projectDir): string
    {
        return rtrim($projectDir, '/') . '/config.json';
    }

    public static function load(string $projectDir): self
    {
        $path = self::path($projectDir);
        $json = is_readable($path) ? file_get_contents($path) : false;
        if ($json === false) {
            throw new EskyException(sprintf(
                'Cannot read the vault configuration at %s — copy config.json.example to config.json.',
                $path
            ));
        }

        return self::fromJson($json);
    }

    public static function fromJson(string $json): self
    {
        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new EskyException('config.json is not valid JSON: ' . $e->getMessage());
        }

        $list = is_array($data) ? $data['vaults'] ?? null : null;
        if (!is_array($list) || $list === []) {
            throw new EskyException('config.json defines no vaults.');
        }

        $vaults = [];
        $seen = [];
        foreach (array_values($list) as $i => $entry) {
            if (!is_array($entry)) {
                throw new EskyException(sprintf('Vault %d in config.json is not an object.', $i + 1));
            }

            $name = self::required($entry, 'name', $i);
            if (preg_match(self::NAME, $name) !== 1) {
                throw new EskyException(sprintf(
                    'Vault name "%s" is not usable: names may hold lowercase letters, digits, - and _ only.',
                    $name
                ));
            }
            if (isset($seen[$name])) {
                throw new EskyException(sprintf('config.json has a duplicate vault name: %s', $name));
            }
            $seen[$name] = true;

            $vaults[] = new Config(
                $name,
                self::required($entry, 'title', $i),
                self::required($entry, 'url', $i),
                self::required($entry, 'token', $i),
                self::optional($entry, 'apiUrl'),
                self::optional($entry, 'profile'),
            );
        }

        return new self($vaults);
    }

    /** @return list<Config> */
    public function all(): array
    {
        return $this->vaults;
    }

    public function get(string $name): ?Config
    {
        foreach ($this->vaults as $vault) {
            if ($vault->name === $name) {
                return $vault;
            }
        }

        return null;
    }

    public function first(): Config
    {
        return $this->vaults[0];
    }

    /**
     * The session may still name a vault that has been removed from the file,
     * so an unknown name falls back rather than failing the request.
     */
    public function current(?string $name): Config
    {
        return ($name === null ? null : $this->get($name)) ?? $this->first();
    }

    private static function required(array $entry, string $key, int $index): string
    {
        $value = $entry[$key] ?? null;
        if (!is_string($value) || trim($value) === '') {
            throw new EskyException(sprintf(
                'Vault %d in config.json is missing a "%s".',
                $index + 1,
                $key
            ));
        }

        return trim($value);
    }

    private static function optional(array $entry, string $key): ?string
    {
        $value = $entry[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
