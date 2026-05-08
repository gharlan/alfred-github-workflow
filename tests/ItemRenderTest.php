<?php

use PHPUnit\Framework\TestCase;

/**
 * Characterization tests for Item::toXml. The XML structure, attribute
 * ordering, escaping, and the baseUrl-prefix/enterprise-prefix rules are
 * exercised against fixed inputs so that any accidental drift shows up as
 * a diff in CI.
 */
final class ItemRenderTest extends TestCase
{
    public function testMinimalItemProducesExpectedXml(): void
    {
        $item = Item::create()->title('repo');
        $xml = Item::toXml([$item], false, false, 'https://github.com');

        $expectedUid = md5('repo');
        $expected = '<?xml version="1.0"?>'."\n".
            '<items><item uid="'.$expectedUid.'" autocomplete=" repo"><icon>icon.png</icon><title>repo</title></item></items>'."\n";

        $this->assertSame($expected, $xml);
    }

    public function testAbsolutePathArgIsPrefixedWithBaseUrl(): void
    {
        $item = Item::create()->title('repo')->arg('/owner/repo');
        $xml = Item::toXml([$item], false, false, 'https://github.com');

        $this->assertStringContainsString('arg="https://github.com/owner/repo"', $xml);
    }

    public function testEnterpriseFlagPrefixesNonUrlArg(): void
    {
        $item = Item::create()->title('search')->arg('foo bar');
        $xml = Item::toXml([$item], true, false, 'https://ghe.example.com');

        $this->assertStringContainsString('arg="e foo bar"', $xml);
    }

    public function testNonEnterpriseDoesNotPrefixArg(): void
    {
        $item = Item::create()->title('search')->arg('foo bar');
        $xml = Item::toXml([$item], false, false, 'https://github.com');

        $this->assertStringContainsString('arg="foo bar"', $xml);
    }

    public function testAbsoluteUrlArgIsUsedVerbatim(): void
    {
        $item = Item::create()->title('site')->arg('https://example.com/thing');
        $xml = Item::toXml([$item], false, false, 'https://github.com');

        $this->assertStringContainsString('arg="https://example.com/thing"', $xml);
    }

    public function testInvalidItemAppendsEllipsisAndValidNo(): void
    {
        $item = Item::create()->title('partial')->valid(false);
        $xml = Item::toXml([$item], false, false, 'https://github.com');

        $this->assertStringContainsString('valid="no"', $xml);
        $this->assertStringContainsString('<title>partial&#x2026;</title>', $xml);
    }

    public function testInvalidItemUsesCustomSuffix(): void
    {
        $item = Item::create()->title('type')->valid(false, ' more');
        $xml = Item::toXml([$item], false, false, 'https://github.com');

        $this->assertStringContainsString('<title>type more</title>', $xml);
    }

    public function testSubtitleAndTitleAreHtmlEscaped(): void
    {
        $item = Item::create()->title('<b>bold</b>')->subtitle('a & b');
        $xml = Item::toXml([$item], false, false, 'https://github.com');

        $this->assertStringContainsString('<title>&lt;b&gt;bold&lt;/b&gt;</title>', $xml);
        $this->assertStringContainsString('<subtitle>a &amp; b</subtitle>', $xml);
    }

    public function testHotkeyFlagStripsLeadingAutocompleteSpace(): void
    {
        $item = Item::create()->title('repo');
        $xml = Item::toXml([$item], false, true, 'https://github.com');

        $this->assertStringContainsString('autocomplete="repo"', $xml);
    }

    public function testPrefixIsIncludedInDisplayTitleButNotAutocompleteByDefault(): void
    {
        $item = Item::create()->prefix('gh ')->title('repo');
        $xml = Item::toXml([$item], false, false, 'https://github.com');

        $this->assertStringContainsString('<title>gh repo</title>', $xml);
        $this->assertStringContainsString('autocomplete=" repo"', $xml);
    }

    public function testPrefixIncludedInAutocompleteWhenNotOnlyTitle(): void
    {
        $item = Item::create()->prefix('gh ', false)->title('repo');
        $xml = Item::toXml([$item], false, false, 'https://github.com');

        $this->assertStringContainsString('autocomplete=" gh repo"', $xml);
    }

    public function testMultipleItemsAreEmittedInOrder(): void
    {
        $items = [
            Item::create()->title('first'),
            Item::create()->title('second'),
            Item::create()->title('third'),
        ];
        $xml = Item::toXml($items, false, false, 'https://github.com');

        $firstPos = strpos($xml, '<title>first</title>');
        $secondPos = strpos($xml, '<title>second</title>');
        $thirdPos = strpos($xml, '<title>third</title>');

        $this->assertNotFalse($firstPos);
        $this->assertLessThan($secondPos, $firstPos);
        $this->assertLessThan($thirdPos, $secondPos);
    }
}
