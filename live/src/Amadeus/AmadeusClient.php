<?php

declare(strict_types=1);

namespace NeuronBook\TripPlannerLive\Amadeus;

use NeuronBook\TripPlannerLive\Api\ApiUnavailable;
use NeuronBook\TripPlannerLive\Api\Http;

/**
 * The Amadeus Self-Service API: OAuth2 client credentials, then bearer calls.
 *
 * The token is kept in a small file until shortly before it expires, so a
 * trip that makes a dozen calls authenticates once, and so do the several
 * processes a resumed trip may run in.
 *
 * Two environments exist: "test" (free keys, cached and limited data) and
 * "production". The default is test, on purpose: nobody should be spending
 * real quota by copying an .env file.
 */
final class AmadeusClient
{
    public function __construct(
        private readonly Http $http,
        private readonly string $clientId,
        private readonly string $clientSecret,
        private readonly string $tokenFile,
        private readonly string $environment = 'test',
    ) {
    }

    /**
     * @param array<string, scalar|null> $query
     * @return array<string, mixed>
     * @throws ApiUnavailable
     */
    public function get(string $path, array $query = []): array
    {
        return $this->http->getJson($this->base() . $path, $query, $this->auth());
    }

    /**
     * @param array<string, mixed> $body
     * @return array<string, mixed>
     * @throws ApiUnavailable
     */
    public function post(string $path, array $body): array
    {
        return $this->http->postJson($this->base() . $path, $body, $this->auth());
    }

    private function base(): string
    {
        return $this->environment === 'production' ? 'https://api.amadeus.com' : 'https://test.api.amadeus.com';
    }

    /**
     * @return array<string, string>
     */
    private function auth(): array
    {
        return ['Authorization' => 'Bearer ' . $this->token()];
    }

    private function token(): string
    {
        if (\is_file($this->tokenFile)) {
            /** @var array{token: string, expires_at: int}|null $saved */
            $saved = \json_decode((string) \file_get_contents($this->tokenFile), true);

            if (\is_array($saved) && $saved['expires_at'] > \time() + 30) {
                return $saved['token'];
            }
        }

        $data = $this->http->postForm($this->base() . '/v1/security/oauth2/token', [
            'grant_type' => 'client_credentials',
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
        ]);

        $token = (string) ($data['access_token'] ?? '');

        if ($token === '') {
            throw new ApiUnavailable('Amadeus did not return an access token. Check AMADEUS_CLIENT_ID and AMADEUS_CLIENT_SECRET.');
        }

        if (!\is_dir(\dirname($this->tokenFile))) {
            \mkdir(\dirname($this->tokenFile), 0775, true);
        }

        \file_put_contents(
            $this->tokenFile,
            \json_encode(['token' => $token, 'expires_at' => \time() + (int) ($data['expires_in'] ?? 1799)], \JSON_THROW_ON_ERROR),
            \LOCK_EX,
        );
        @\chmod($this->tokenFile, 0600);

        return $token;
    }
}
