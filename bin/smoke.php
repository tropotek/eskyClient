<?php
declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use Esky\Client;
use Esky\EskyException;
use Esky\Vaults;

try {
    $vaults = Vaults::load(dirname(__DIR__));
    $vault = $vaults->current($argv[1] ?? null);
    printf("vault: %s (%s)\n", $vault->title, $vault->url);

    $client = new Client($vault);

    $recent = $client->recent(50);
    printf("memory_recent: %d records\n", count($recent));
    foreach ($recent as $record) {
        printf("  %-14s %-12s %s\n", $record['uid'], $record['kind'], $record['updated_at']);
    }

    $sorted = true;
    for ($i = 0, $n = count($recent) - 1; $i < $n; $i++) {
        if ($recent[$i]['updated_at'] < $recent[$i + 1]['updated_at']) {
            $sorted = false;
        }
    }
    printf("sorted desc: %s\n", $sorted ? 'yes' : 'NO');

    $hits = $client->search('git', 10);
    printf("memory_search('git'): %d records\n", count($hits));

    // esky's search is semantic: a nonsense phrase still returns a nearest match.
    // A uid-shaped query is the one reliably empty case, which is also why the
    // detail page cannot look a memory up by uid via search.
    $none = $client->search('LqwuU_ZCkQY', 10);
    printf("uid-shaped search returns array: %s (count %d)\n", is_array($none) ? 'yes' : 'NO', count($none));
} catch (EskyException $e) {
    fwrite(STDERR, 'FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
