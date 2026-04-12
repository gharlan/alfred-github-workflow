<?php

use PHPUnit\Framework\TestCase;

final class OAuthStateTest extends TestCase
{
    public function testEncodeAndDecodeRoundTrip(): void
    {
        $encoded = OAuthState::encode('work');
        $decoded = OAuthState::decode($encoded);

        $this->assertSame('work', $decoded['label']);
        $this->assertSame(1, $decoded['v']);
    }

    public function testDecodeReturnsNullOnEmptyString(): void
    {
        $this->assertNull(OAuthState::decode(''));
    }

    public function testDecodeReturnsNullOnLegacyMarker(): void
    {
        $this->assertNull(OAuthState::decode('m'));
    }

    public function testDecodeReturnsNullOnGarbageBase64(): void
    {
        $this->assertNull(OAuthState::decode('not-valid-base64!!!'));
    }

    public function testDecodeReturnsNullOnValidBase64ButNotJson(): void
    {
        $this->assertNull(OAuthState::decode(base64_encode('plain text')));
    }

    public function testDecodeReturnsNullOnJsonMissingLabel(): void
    {
        $this->assertNull(OAuthState::decode(base64_encode(json_encode(['v' => 1]))));
    }

    public function testDecodeReturnsNullOnJsonMissingVersion(): void
    {
        $this->assertNull(OAuthState::decode(base64_encode(json_encode(['label' => 'x']))));
    }
}
