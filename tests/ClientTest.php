<?php
declare(strict_types=1);

namespace Esky\Tests;

use Esky\Client;
use Esky\Config;
use Esky\EskyException;
use PHPUnit\Framework\TestCase;

final class ClientTest extends TestCase
{
    /** @var list<array<string, mixed>> */
    private array $sent = [];

    private function client(string $toolBody): Client
    {
        $this->sent = [];

        return new Client(
            new Config('personal', 'Personal', 'http://esky.test/mcp/personal', 'tok'),
            transport: function (array $payload, ?string $sessionId) use ($toolBody): array {
                $this->sent[] = $payload;
                $method = $payload['method'] ?? '';

                if ($method === 'initialize') {
                    return ['body' => '', 'sessionId' => 'sess-1'];
                }
                if ($method === 'tools/call') {
                    self::assertSame('sess-1', $sessionId);

                    return ['body' => "event: message\ndata: " . $toolBody . "\n\n", 'sessionId' => null];
                }

                return ['body' => '', 'sessionId' => null];
            }
        );
    }

    private function toolCall(): array
    {
        foreach ($this->sent as $payload) {
            if (($payload['method'] ?? '') === 'tools/call') {
                return $payload['params'];
            }
        }
        self::fail('No tools/call was sent.');
    }

    public function testForgetSendsTheUidAndReason(): void
    {
        $client = $this->client('{"jsonrpc":"2.0","id":3,"result":{"content":[]}}');

        $client->forget('abc123', 'out of date');

        self::assertSame(
            ['name' => 'memory_forget', 'arguments' => ['uid' => 'abc123', 'reason' => 'out of date']],
            $this->toolCall()
        );
    }

    public function testForgetWithoutAReasonSendsNull(): void
    {
        $client = $this->client('{"jsonrpc":"2.0","id":3,"result":{"content":[]}}');

        $client->forget('abc123', null);

        self::assertNull($this->toolCall()['arguments']['reason']);
    }

    /* The forget response shape is not documented; plain text must not be
       mistaken for a malformed record list. */
    public function testForgetAcceptsAPlainTextAcknowledgement(): void
    {
        $client = $this->client('{"jsonrpc":"2.0","id":3,"result":{"content":[{"type":"text","text":"Forgotten."}]}}');

        $client->forget('abc123', null);

        $this->addToAssertionCount(1);
    }

    public function testForgetSurfacesAToolError(): void
    {
        $client = $this->client('{"jsonrpc":"2.0","id":3,"result":{"isError":true,"content":[{"type":"text","text":"no such memory"}]}}');

        $this->expectException(EskyException::class);
        $this->expectExceptionMessage('no such memory');

        $client->forget('abc123', null);
    }

    public function testForgetSurfacesAJsonRpcError(): void
    {
        $client = $this->client('{"jsonrpc":"2.0","id":3,"error":{"code":-32602,"message":"bad uid"}}');

        $this->expectException(EskyException::class);
        $this->expectExceptionMessage('bad uid');

        $client->forget('abc123', null);
    }

    public function testRecentStillDecodesRecordsThroughTheTransport(): void
    {
        $inner = json_encode([['uid' => 'a', 'updated_at' => '2026-01-01T00:00:00+00:00']]);
        $client = $this->client(json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'result' => ['content' => [['type' => 'text', 'text' => $inner]]],
        ]));

        self::assertSame('a', $client->recent(5)[0]['uid']);
    }

    /** @param array<string, string> $bodies tool name => raw JSON-RPC body */
    private function clientByTool(array $bodies): Client
    {
        $this->sent = [];

        return new Client(
            new Config('personal', 'Personal', 'http://esky.test/mcp/personal', 'tok'),
            transport: function (array $payload, ?string $sessionId) use ($bodies): array {
                $this->sent[] = $payload;
                $method = $payload['method'] ?? '';

                if ($method === 'initialize') {
                    return ['body' => '', 'sessionId' => 'sess-1'];
                }
                if ($method === 'tools/call') {
                    return ['body' => 'data: ' . $bodies[$payload['params']['name']] . "\n\n", 'sessionId' => null];
                }

                return ['body' => '', 'sessionId' => null];
            }
        );
    }

    private static function records(array $records): string
    {
        return (string) json_encode([
            'jsonrpc' => '2.0',
            'id' => 3,
            'result' => ['content' => [['type' => 'text', 'text' => json_encode($records)]]],
        ]);
    }

    /** @return list<string> */
    private function toolsCalled(): array
    {
        $names = [];
        foreach ($this->sent as $payload) {
            if (($payload['method'] ?? '') === 'tools/call') {
                $names[] = $payload['params']['name'];
            }
        }

        return $names;
    }

    public function testFindWithoutAQueryOnlyReadsTheRecentList(): void
    {
        $client = $this->clientByTool([
            'memory_recent' => self::records([['uid' => 'a', 'updated_at' => '2026-01-01T00:00:00+00:00']]),
            'memory_search' => self::records([]),
        ]);

        self::assertSame('a', $client->find('a')['uid']);
        self::assertSame(['memory_recent'], $this->toolsCalled());
    }

    /* A memory can be reachable only through the search that listed it. */
    public function testFindTriesTheSearchFirstWhenGivenAQuery(): void
    {
        $client = $this->clientByTool([
            'memory_search' => self::records([['uid' => 'only-in-search', 'updated_at' => '2026-01-01T00:00:00+00:00']]),
            'memory_recent' => self::records([]),
        ]);

        self::assertSame('only-in-search', $client->find('only-in-search', 'esky')['uid']);
        self::assertSame(['memory_search'], $this->toolsCalled());
    }

    public function testFindFallsBackToTheRecentListWhenTheSearchMissesIt(): void
    {
        $client = $this->clientByTool([
            'memory_search' => self::records([]),
            'memory_recent' => self::records([['uid' => 'b', 'updated_at' => '2026-01-01T00:00:00+00:00']]),
        ]);

        self::assertSame('b', $client->find('b', 'esky')['uid']);
        self::assertSame(['memory_search', 'memory_recent'], $this->toolsCalled());
    }

    public function testFindReturnsNullWhenNeitherListHasIt(): void
    {
        $client = $this->clientByTool([
            'memory_search' => self::records([]),
            'memory_recent' => self::records([]),
        ]);

        self::assertNull($client->find('zzz', 'esky'));
    }
}
