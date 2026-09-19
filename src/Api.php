<?php
declare(strict_types=1);

namespace Esky;

/**
 * Reads esky's REST surface: the aggregates the metrics page charts.
 *
 * Separate from Client because this is not MCP — no handshake, no session, no
 * double-encoded payload, just a GET with the same bearer token. The transport
 * is injectable so the decoding and URL building can be tested without a
 * socket, which the suite is not allowed to open.
 */
final class Api
{
    /** @var \Closure(string, string): array{status: int, body: string} */
    private readonly \Closure $transport;

    public function __construct(
        private readonly Config $config,
        ?\Closure $transport = null,
        private readonly int $timeout = 15,
    ) {
        $this->transport = $transport ?? $this->curl(...);
    }

    /** @return array<string, mixed> */
    public function querySummary(int $days): array
    {
        return $this->get('queries/summary', $days);
    }

    /** @return array<string, mixed> */
    public function stats(int $days): array
    {
        return $this->get('stats', $days);
    }

    /** @return array<string, mixed> */
    private function get(string $path, int $days): array
    {
        if ($this->config->profile === null) {
            throw new EskyException(
                'Cannot tell which profile to read: ESKY_URL has no /mcp/{profile} '
                . 'segment. Set ESKY_PROFILE.'
            );
        }

        $url = sprintf(
            '%s/api/%s/%s?days=%d',
            $this->config->apiBase,
            rawurlencode($this->config->profile),
            $path,
            $days
        );

        $response = ($this->transport)($url, $this->config->token);

        if ($response['status'] === 401) {
            throw new EskyException('Esky rejected the token for this profile.');
        }
        if ($response['status'] !== 200) {
            throw new EskyException(sprintf(
                'Esky returned HTTP %d: %s',
                $response['status'],
                substr(trim($response['body']), 0, 300)
            ));
        }

        $decoded = json_decode($response['body'], true);
        if (!is_array($decoded)) {
            throw new EskyException('Esky sent a body that could not be read as JSON.');
        }

        return $decoded;
    }

    /** @return array{status: int, body: string} */
    private function curl(string $url, string $token): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $token],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new EskyException('Could not reach Esky: ' . $error);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        return ['status' => $status, 'body' => (string) $body];
    }
}
