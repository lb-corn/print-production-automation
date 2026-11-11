<?php

namespace App\Services\Shopify;
use App\Services\PartnerContext;

use App\Exceptions\ShopifyGraphqlException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class ShopifyGraphqlClient
{
    public function __construct(private readonly ShopifyTokenService $tokens) {}

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    public function query(string $query, array $variables = []): array
    {
        $response = $this->send($query, $variables, $this->tokens->getAccessToken());

        if ($this->isAuthenticationFailure($response)) {
            $this->tokens->forgetAccessToken();
            $response = $this->send($query, $variables, $this->tokens->refreshAccessToken());
        }

        if (! $response->successful()) {
            throw new ShopifyGraphqlException('Shopify GraphQL request failed with status '.$response->status().'.');
        }

        $payload = $response->json();

        if (! is_array($payload)) {
            throw new ShopifyGraphqlException('Shopify GraphQL response was not valid JSON.');
        }

        $errors = $payload['errors'] ?? null;

        if (is_array($errors) && $errors !== []) {
            throw new ShopifyGraphqlException('Shopify GraphQL returned an error: '.$this->summarizeErrors($errors));
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $variables
     */
    private function send(string $query, array $variables, string $token): Response
    {
        return Http::withHeaders([
            'X-Shopify-Access-Token' => $token,
            'Accept' => 'application/json',
        ])
            ->timeout(30)
            ->post($this->endpoint(), [
                'query' => $query,
                'variables' => $variables,
            ]);
    }

    private function endpoint(): string
    {
        $version = trim((string) PartnerContext::shopify('api_version'));

        if ($version === '') {
            throw new ShopifyGraphqlException('SHOPIFY_API_VERSION is not configured.');
        }

        return 'https://'.$this->tokens->storeDomain().'/admin/api/'.$version.'/graphql.json';
    }

    private function isAuthenticationFailure(Response $response): bool
    {
        if (in_array($response->status(), [401, 403], true)) {
            return true;
        }

        $errors = $response->json('errors');

        if (! is_array($errors)) {
            return false;
        }

        foreach ($errors as $error) {
            $message = strtolower((string) ($error['message'] ?? ''));
            $code = strtoupper((string) data_get($error, 'extensions.code', ''));

            if (in_array($code, ['ACCESS_DENIED', 'UNAUTHENTICATED', 'TOKEN_EXPIRED'], true)) {
                return true;
            }

            if (str_contains($message, 'access token') || str_contains($message, 'unauthorized') || str_contains($message, 'authentication')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $errors
     */
    private function summarizeErrors(array $errors): string
    {
        return collect($errors)
            ->map(fn (mixed $error): string => is_array($error) ? (string) ($error['message'] ?? 'Unknown error') : 'Unknown error')
            ->filter()
            ->take(3)
            ->implode('; ');
    }
}
