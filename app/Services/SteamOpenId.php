<?php

namespace App\Services;

use RuntimeException;

class SteamOpenId
{
    private const ENDPOINT = 'https://steamcommunity.com/openid/login/';

    public function redirectUrl(string $returnTo, string $realm): string
    {
        return self::ENDPOINT.'?'.http_build_query([
            'openid.ns' => 'http://specs.openid.net/auth/2.0',
            'openid.mode' => 'checkid_setup',
            'openid.return_to' => $this->httpsUrl($returnTo),
            'openid.realm' => rtrim($this->httpsUrl($realm), '/'),
            'openid.identity' => 'http://specs.openid.net/auth/2.0/identifier_select',
            'openid.claimed_id' => 'http://specs.openid.net/auth/2.0/identifier_select',
        ], '', '&', PHP_QUERY_RFC3986);
    }

    public function validate(array $payload): string
    {
        $check = $payload;
        $check['openid_mode'] = 'check_authentication';

        $body = http_build_query($this->restoreOpenIdKeys($check));

        $context = stream_context_create([
            'http' => [
                'method' => 'POST',
                'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
                'content' => $body,
                'timeout' => 15,
            ],
        ]);

        $response = file_get_contents(self::ENDPOINT, false, $context);

        if (! is_string($response) || ! str_contains($response, 'is_valid:true')) {
            throw new RuntimeException('Steam could not validate this login.');
        }

        $claimedId = $payload['openid_claimed_id'] ?? $payload['openid.claimed_id'] ?? '';

        if (! preg_match('#^https?://steamcommunity\.com/openid/id/(\d+)$#', $claimedId, $matches)) {
            throw new RuntimeException('Steam did not return a valid SteamID64.');
        }

        return $matches[1];
    }

    private function restoreOpenIdKeys(array $payload): array
    {
        $restored = [];

        foreach ($payload as $key => $value) {
            $restored[str_replace('_', '.', $key)] = $value;
        }

        return $restored;
    }

    private function httpsUrl(string $url): string
    {
        if (str_starts_with($url, 'http://')) {
            return 'https://'.substr($url, 7);
        }

        return $url;
    }
}
