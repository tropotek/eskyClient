<?php
declare(strict_types=1);

namespace Esky\Tests;

use Esky\EskyException;
use Esky\ResponseDecoder;
use PHPUnit\Framework\TestCase;

final class ResponseDecoderTest extends TestCase
{
    public function testDecodesTheDoubleEncodedPayload(): void
    {
        $inner = json_encode([['uid' => 'abc', 'text' => 'hello', 'kind' => 'project']]);
        $message = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => [['text' => $inner]]]];

        $records = ResponseDecoder::records($message);

        self::assertCount(1, $records);
        self::assertSame('abc', $records[0]['uid']);
        self::assertSame('hello', $records[0]['text']);
    }

    public function testEmptyContentMeansNoResults(): void
    {
        $message = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => []]];

        self::assertSame([], ResponseDecoder::records($message));
    }

    public function testJsonRpcErrorThrowsWithTheServerMessage(): void
    {
        $message = ['jsonrpc' => '2.0', 'id' => 1, 'error' => ['code' => -32602, 'message' => 'bad params']];

        $this->expectException(EskyException::class);
        $this->expectExceptionMessageMatches('/bad params/');

        ResponseDecoder::records($message);
    }

    public function testMalformedInnerPayloadThrows(): void
    {
        $message = ['jsonrpc' => '2.0', 'id' => 1, 'result' => ['content' => [['text' => 'not json']]]];

        $this->expectException(EskyException::class);
        $this->expectExceptionMessageMatches('/malformed/i');

        ResponseDecoder::records($message);
    }

    public function testMissingResultThrows(): void
    {
        $this->expectException(EskyException::class);

        ResponseDecoder::records(['jsonrpc' => '2.0', 'id' => 1]);
    }
}
