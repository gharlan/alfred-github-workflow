<?php

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ItemTest extends TestCase
{
    #[DataProvider('dataMatch')]
    public function testMatch(Item $item, string $query, bool $expected): void
    {
        self::assertSame($expected, $item->match($query));
    }

    /** @return iterable<string, array{Item, string, bool}> */
    public static function dataMatch(): iterable
    {
        $repo = static fn () => Item::create()->title('user/repo');

        yield 'subsequence usr' => [$repo(), 'usr', true];
        yield 'full prefix' => [$repo(), 'user', true];
        yield 'spans separator' => [$repo(), 'u/r', true];
        yield 'missing chars' => [$repo(), 'xyz', false];
        yield 'trailing extra char' => [$repo(), 'repo!', false];
        yield 'uppercase query, mixed-case title' => [Item::create()->title('User/Repo'), 'USER', true];
        yield 'mixed-case query, mixed-case title' => [Item::create()->title('User/Repo'), 'uSeR', true];
        yield 'comparator overrides title (match)' => [
            Item::create()->title('Display Title')->comparator('user/repo'),
            'user',
            true,
        ];
        yield 'comparator overrides title (no match against display)' => [
            Item::create()->title('Display Title')->comparator('user/repo'),
            'display',
            false,
        ];
        yield 'prefix stripped from query (onlyTitle=false)' => [
            Item::create()->title('repo')->prefix('gh ', false),
            'gh repo',
            true,
        ];
        yield 'prefix kept in query (onlyTitle=true)' => [
            Item::create()->title('repo')->prefix('gh '),
            'gh repo',
            false,
        ];
    }

    /**
     * @param list<string> $titles
     * @param list<string> $expectedOrder
     */
    #[DataProvider('dataMatchRankingOrder')]
    public function testMatchRankingOrder(string $query, array $titles, array $expectedOrder): void
    {
        $items = [];
        foreach ($titles as $title) {
            $item = Item::create()->title($title);
            self::assertTrue($item->match($query), "expected '$title' to match '$query'");
            $items[] = $item;
        }
        usort($items, static fn (Item $a, Item $b) => $a->compare($b));

        $titleProp = new ReflectionProperty(Item::class, 'title');
        $sorted = array_map(static fn (Item $i) => (string) $titleProp->getValue($i), $items);
        self::assertSame($expectedOrder, $sorted);
    }

    /** @return array<string, array{string, list<string>, list<string>}> */
    public static function dataMatchRankingOrder(): array
    {
        return [
            'consecutive prefix beats interspersed' => [
                'user/r',
                ['user/something-with-r-later', 'user/repo'],
                ['user/repo', 'user/something-with-r-later'],
            ],
            'substring after separator beats single-letter fuzzy prefix' => [
                'react',
                ['rxexaxcxt', 'user/react'],
                ['user/react', 'rxexaxcxt'],
            ],
            'prefix run beats interspersed competitor' => [
                'abco',
                ['azbco', 'abxxx/core'],
                ['abxxx/core', 'azbco'],
            ],
            'shorter title wins on equal sameChars' => [
                'rep',
                ['repository', 'repo'],
                ['repo', 'repository'],
            ],
        ];
    }

    public function testCompareFallsBackToPrioWhenSameCharsEqual(): void
    {
        $high = Item::create()->title('alpha')->prio(10);
        $low = Item::create()->title('alpha')->prio(1);

        $high->match('a');
        $low->match('a');

        self::assertSame(-1, $high->compare($low));
        self::assertSame(1, $low->compare($high));
    }

    public function testToXmlRendersTitleSubtitleAndIcon(): void
    {
        $item = Item::create()
            ->title('Hello')
            ->subtitle('World')
            ->icon('file')
            ->arg('https://example.com');

        $xml = self::parseXml(Item::toXml([$item], false, false, null));

        self::assertSame('Hello', (string) $xml->item[0]->title);
        self::assertSame('World', (string) $xml->item[0]->subtitle);
        self::assertSame('icons/file.png', (string) $xml->item[0]->icon);
    }

    public function testToXmlFallsBackToDefaultIconWhenFileMissing(): void
    {
        $item = Item::create()->title('Hello')->icon('does-not-exist');

        $xml = self::parseXml(Item::toXml([$item], false, false, null));

        self::assertSame('icon.png', (string) $xml->item[0]->icon);
    }

    public function testToXmlEscapesSpecialChars(): void
    {
        $item = Item::create()->title('A & B <c>')->subtitle('"q\'s"');

        $raw = Item::toXml([$item], false, false, null);
        self::assertIsString($raw);
        self::assertStringContainsString('A &amp; B &lt;c&gt;', $raw);

        $xml = self::parseXml($raw);
        self::assertSame('A & B <c>', (string) $xml->item[0]->title);
        self::assertSame('"q\'s"', (string) $xml->item[0]->subtitle);
    }

    #[DataProvider('dataToXmlArg')]
    public function testToXmlArg(string $arg, bool $enterprise, ?string $baseUrl, string $expected): void
    {
        $item = Item::create()->title('t')->arg($arg);

        $xml = self::parseXml(Item::toXml([$item], $enterprise, false, $baseUrl));

        self::assertSame($expected, (string) $xml->item[0]['arg']);
    }

    /** @return iterable<string, array{string, bool, ?string, string}> */
    public static function dataToXmlArg(): iterable
    {
        yield 'enterprise prefixes non-url' => ['> login', true, null, 'e > login'];
        yield 'url passes through unchanged' => ['https://example.com', true, null, 'https://example.com'];
        yield 'leading slash is expanded with baseUrl' => ['/user/repo', false, 'https://github.com', 'https://github.com/user/repo'];
    }

    public function testToXmlMarksInvalidItemAndAppendsAdd(): void
    {
        $item = Item::create()->title('search')->valid(false, ' …');

        $xml = self::parseXml(Item::toXml([$item], false, false, null));

        self::assertSame('no', (string) $xml->item[0]['valid']);
        self::assertSame('search …', (string) $xml->item[0]->title);
    }

    #[DataProvider('dataToXmlAutocomplete')]
    public function testToXmlAutocomplete(Item $item, bool $hotkey, ?string $expected): void
    {
        $xml = self::parseXml(Item::toXml([$item], false, $hotkey, null));

        if (null === $expected) {
            self::assertNull($xml->item[0]['autocomplete']);
        } else {
            self::assertSame($expected, (string) $xml->item[0]['autocomplete']);
        }
    }

    /** @return iterable<string, array{Item, bool, ?string}> */
    public static function dataToXmlAutocomplete(): iterable
    {
        yield 'title default with leading space (no hotkey)' => [
            Item::create()->title('repo')->autocomplete(),
            false,
            ' repo',
        ];
        yield 'title default without space (hotkey)' => [
            Item::create()->title('repo')->autocomplete(),
            true,
            'repo',
        ];
        yield 'comparator overrides title' => [
            Item::create()->title('Display')->comparator('cmp')->autocomplete(),
            true,
            'cmp',
        ];
        yield 'explicit string wins over title' => [
            Item::create()->title('Display')->autocomplete('forced'),
            true,
            'forced',
        ];
        yield 'prefix included when not onlyTitle' => [
            Item::create()->title('repo')->prefix('gh ', false),
            true,
            'gh repo',
        ];
        yield 'autocomplete=false → no attribute' => [
            Item::create()->title('repo')->autocomplete(false),
            true,
            null,
        ];
    }

    public function testToXmlPrefixIsPrependedToTitle(): void
    {
        $item = Item::create()->title('repo')->prefix('gh ');

        $xml = self::parseXml(Item::toXml([$item], false, false, null));

        self::assertSame('gh repo', (string) $xml->item[0]->title);
    }

    public function testToXmlUid(): void
    {
        $a = Item::create()->title('repo');
        $b = Item::create()->title('repo');
        $random = Item::create()->title('repo')->randomUid();

        $xmlA = self::parseXml(Item::toXml([$a], false, false, null));
        $xmlB = self::parseXml(Item::toXml([$b], false, false, null));
        $xmlR = self::parseXml(Item::toXml([$random], false, false, null));

        self::assertSame(
            (string) $xmlA->item[0]['uid'],
            (string) $xmlB->item[0]['uid'],
            'same title must produce identical uid',
        );
        self::assertNotSame(
            (string) $xmlA->item[0]['uid'],
            (string) $xmlR->item[0]['uid'],
            'randomUid() must produce a different uid for the same title',
        );
    }

    private static function parseXml(string|false $xml): SimpleXMLElement
    {
        self::assertIsString($xml);
        $parsed = simplexml_load_string($xml);
        self::assertInstanceOf(SimpleXMLElement::class, $parsed);

        return $parsed;
    }
}
