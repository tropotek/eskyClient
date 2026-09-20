<?php
declare(strict_types=1);

namespace Esky\Tests;

use Esky\Api;
use Esky\Config;
use Esky\EskyException;
use PHPUnit\Framework\TestCase;

final class ApiTest extends TestCase
{
    /** @var list<string> */
    private array $requested = [];

    private function api(int $status, string $body, string $url = 'http://esky.test:8011/mcp/personal'): Api
    {
        $this->requested = [];

        return new Api(
            new Config('personal', 'Personal', $url, 'tok'),
            function (string $requestUrl, string $token) use ($status, $body): array {
                $this->requested[] = $requestUrl;
                self::assertSame('tok', $token);

                return ['status' => $status, 'body' => $body];
            }
        );
    }

    public function testQuerySummaryAsksTheProfilesSummaryEndpoint(): void
    {
        $api = $this->api(200, '{"days":7}');

        $api->querySummary(7);

        self::assertSame(
            ['http://esky.test:8011/api/personal/queries/summary?days=7'],
            $this->requested
        );
    }

    public function testStatsAsksTheProfilesStatsEndpoint(): void
    {
        $api = $this->api(200, '{"facts":0}');

        $api->stats(30);

        self::assertSame(
            ['http://esky.test:8011/api/personal/stats?days=30'],
            $this->requested
        );
    }

    public function testTheDecodedBodyIsReturned(): void
    {
        $api = $this->api(200, '{"days":7,"totals":{"searches":3}}');

        self::assertSame(3, $api->querySummary(7)['totals']['searches']);
    }

    public function testARejectedTokenSaysSo(): void
    {
        $api = $this->api(401, '{"error":"unauthorized"}');

        $this->expectException(EskyException::class);
        $this->expectExceptionMessageMatches('/token/i');

        $api->querySummary(7);
    }

    public function testAnotherFailureCarriesTheStatus(): void
    {
        $api = $this->api(500, 'boom');

        $this->expectException(EskyException::class);
        $this->expectExceptionMessageMatches('/500/');

        $api->querySummary(7);
    }

    public function testAnUnparsableBodyIsAnError(): void
    {
        $api = $this->api(200, 'not json');

        $this->expectException(EskyException::class);
        $this->expectExceptionMessageMatches('/could not be read|parse/i');

        $api->querySummary(7);
    }

    public function testAUrlWithoutAProfileIsRefusedWithAdvice(): void
    {
        /* The REST paths are per profile, so the metrics page cannot work at
           all until the profile is known. */
        $this->expectException(EskyException::class);
        $this->expectExceptionMessageMatches('/ESKY_PROFILE/');

        $this->api(200, '{}', 'http://esky.test:8011/')->stats(7);
    }
}
