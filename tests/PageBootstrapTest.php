<?php
declare(strict_types=1);

namespace Esky\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Layout::navbar() resolves the active vault, which starts the session, so any
 * page wearing the navbar must have touched the session before it emits a
 * byte — otherwise PHP warns that headers have already been sent. Checked by
 * reading the scripts rather than fetching them: no test may open a socket.
 */
final class PageBootstrapTest extends TestCase
{
    /** @return list<array{0: string}> */
    public static function pagesWearingTheNavbar(): array
    {
        return [['index.php'], ['view.php'], ['metrics.php'], ['settings.php'], ['about.php']];
    }

    #[DataProvider('pagesWearingTheNavbar')]
    public function testTheSessionIsResolvedBeforeAnyOutput(string $page): void
    {
        $source = (string) file_get_contents(dirname(__DIR__) . '/public/' . $page);

        $session = strpos($source, 'Session::');
        $output = strpos($source, '?>');

        self::assertNotFalse($session, $page . ' never resolves the session');
        self::assertNotFalse($output, $page . ' emits no markup');
        self::assertLessThan(
            $output,
            $session,
            $page . ' starts the session after output; the navbar cannot do it for you'
        );
    }
}
