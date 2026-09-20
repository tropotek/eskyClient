<?php
declare(strict_types=1);

namespace Esky;

/**
 * Reduces each configured vault to a row the settings page renders.
 *
 * The probe is injectable for the same reason Api's transport is: the suite
 * must not open a socket. Tokens are masked here rather than in the view, so
 * no caller can render one whole by accident.
 */
final class Health
{
    private const TIMEOUT = 2;

    /**
     * @param ?\Closure(Config): void $probe
     * @return list<array{name: string, title: string, url: string, profile: ?string, token: string, ok: bool, message: string}>
     */
    public static function check(Vaults $vaults, ?\Closure $probe = null, int $timeout = self::TIMEOUT): array
    {
        /* A short timeout: this page waits on every vault in turn, and an
           unreachable one must not hold the others up for the full 30s the
           memory pages allow. */
        $probe ??= static fn (Config $config) => (new Client($config, $timeout))->ping();

        $rows = [];
        foreach ($vaults->all() as $vault) {
            $ok = true;
            $message = '';
            try {
                $probe($vault);
            } catch (EskyException $e) {
                $ok = false;
                $message = $e->getMessage();
            }

            $rows[] = [
                'name' => $vault->name,
                'title' => $vault->title,
                'url' => $vault->url,
                'profile' => $vault->profile,
                'token' => Page::mask($vault->token),
                'ok' => $ok,
                'message' => $message,
            ];
        }

        return $rows;
    }
}
