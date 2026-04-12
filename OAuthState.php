<?php

class OAuthState
{
    public static function encode(string $label): string
    {
        return base64_encode(json_encode(['label' => $label, 'v' => 1]));
    }

    public static function decode(string $state): ?array
    {
        if ('' === $state || 'm' === $state) {
            return null;
        }
        $json = base64_decode($state, true);
        if (false === $json) {
            return null;
        }
        $decoded = json_decode($json, true);
        if (!is_array($decoded) || !isset($decoded['label'], $decoded['v'])) {
            return null;
        }

        return $decoded;
    }
}
