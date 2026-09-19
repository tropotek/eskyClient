<?php
declare(strict_types=1);

namespace Esky\Tests;

use Esky\SseParser;
use PHPUnit\Framework\TestCase;

final class SseParserTest extends TestCase
{
    public function testParsesASingleMessage(): void
    {
        $body = "event: message\ndata: {\"jsonrpc\":\"2.0\",\"id\":1,\"result\":{\"ok\":true}}\n\n";

        $messages = SseParser::messages($body);

        self::assertCount(1, $messages);
        self::assertSame(1, $messages[0]['id']);
        self::assertTrue($messages[0]['result']['ok']);
    }

    public function testParsesMultipleMessagesInOneBody(): void
    {
        $body = "event: message\ndata: {\"id\":1}\n\nevent: message\ndata: {\"id\":2}\n\n";

        $messages = SseParser::messages($body);

        self::assertCount(2, $messages);
        self::assertSame(2, $messages[1]['id']);
    }

    public function testIgnoresBlankAndNonDataLines(): void
    {
        $body = "\nevent: message\n: a comment\nid: 7\ndata: {\"id\":1}\n\n";

        $messages = SseParser::messages($body);

        self::assertCount(1, $messages);
    }

    public function testReturnsEmptyArrayWhenThereAreNoDataLines(): void
    {
        self::assertSame([], SseParser::messages("event: ping\n\n"));
    }

    public function testSkipsDataLinesThatAreNotJson(): void
    {
        $body = "data: not json\ndata: {\"id\":1}\n";

        $messages = SseParser::messages($body);

        self::assertCount(1, $messages);
        self::assertSame(1, $messages[0]['id']);
    }

    public function testHandlesCarriageReturns(): void
    {
        $body = "event: message\r\ndata: {\"id\":1}\r\n\r\n";

        self::assertCount(1, SseParser::messages($body));
    }
}
