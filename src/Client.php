<?php
declare(strict_types=1);

namespace Esky;

/**
 * MCP-over-HTTP client for esky. Performs the initialize / initialized
 * handshake once per instance, then issues tools/call requests.
 */
final class Client
{
    private const PROTOCOL_VERSION = '2024-11-05';

    private ?string $sessionId = null;
    private int $nextId = 1;

    public function __construct(
        private readonly Config $config,
        private readonly int $timeout = 30,
    ) {
    }

    /** @return list<array<string, mixed>> */
    public function recent(int $limit = 50, ?string $kind = null): array
    {
        return $this->sorted($this->call('memory_recent', ['limit' => $limit, 'kind' => $kind]));
    }

    /** @return list<array<string, mixed>> */
    public function search(string $query, int $limit = 50, ?array $tags = null): array
    {
        return $this->sorted($this->call('memory_search', [
            'query' => $query,
            'limit' => $limit,
            'tags' => $tags,
        ]));
    }

    /** @return list<array<string, mixed>> */
    private function call(string $tool, array $arguments): array
    {
        $this->handshake();

        $response = $this->post([
            'jsonrpc' => '2.0',
            'id' => $this->nextId++,
            'method' => 'tools/call',
            'params' => ['name' => $tool, 'arguments' => $arguments],
        ]);

        $messages = SseParser::messages($response['body']);
        if ($messages === []) {
            throw new EskyException('Esky returned no parsable message for ' . $tool);
        }

        return ResponseDecoder::records($messages[0]);
    }

    private function handshake(): void
    {
        if ($this->sessionId !== null) {
            return;
        }

        $response = $this->post([
            'jsonrpc' => '2.0',
            'id' => $this->nextId++,
            'method' => 'initialize',
            'params' => [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => (object) [],
                'clientInfo' => ['name' => 'esky-client', 'version' => '1.0'],
            ],
        ]);

        if ($response['sessionId'] === null) {
            throw new EskyException('Esky did not return an Mcp-Session-Id header');
        }
        $this->sessionId = $response['sessionId'];

        // Notification: no id, no response body to inspect.
        $this->post(['jsonrpc' => '2.0', 'method' => 'notifications/initialized']);
    }

    /** @return array{body: string, sessionId: ?string} */
    private function post(array $payload): array
    {
        $headers = [
            'Authorization: Bearer ' . $this->config->token,
            'Content-Type: application/json',
            'Accept: application/json, text/event-stream',
        ];
        if ($this->sessionId !== null) {
            $headers[] = 'Mcp-Session-Id: ' . $this->sessionId;
        }

        $ch = curl_init($this->config->url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => true,
            CURLOPT_TIMEOUT => $this->timeout,
        ]);

        $raw = curl_exec($ch);
        if ($raw === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new EskyException('Could not reach Esky: ' . $error);
        }

        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $headerSize = (int) curl_getinfo($ch, CURLINFO_HEADER_SIZE);
        curl_close($ch);

        $rawHeaders = substr((string) $raw, 0, $headerSize);
        $body = substr((string) $raw, $headerSize);

        if ($status !== 200 && $status !== 202) {
            throw new EskyException(sprintf(
                'Esky returned HTTP %d: %s',
                $status,
                substr(trim($body), 0, 300)
            ));
        }

        return ['body' => $body, 'sessionId' => self::sessionIdFrom($rawHeaders)];
    }

    private static function sessionIdFrom(string $rawHeaders): ?string
    {
        foreach (preg_split('/\r\n|\r|\n/', $rawHeaders) ?: [] as $line) {
            if (stripos($line, 'mcp-session-id:') === 0) {
                return trim(substr($line, strlen('mcp-session-id:')));
            }
        }

        return null;
    }

    /**
     * @param list<array<string, mixed>> $records
     * @return list<array<string, mixed>>
     */
    private static function sorted(array $records): array
    {
        usort(
            $records,
            static fn (array $a, array $b): int => strcmp((string) ($b['updated_at'] ?? ''), (string) ($a['updated_at'] ?? ''))
        );

        return $records;
    }
}
