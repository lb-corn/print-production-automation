<?php

namespace App\Services\Shopify;

use App\Exceptions\ShopifyAuthenticationException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

class ShopifyTokenService
{
    public function getAccessToken(): string
    {
        $cached = Cache::get($this->cacheKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        return $this->refreshAccessToken();
    }

    public function refreshAccessToken(): string
    {
        $this->ensureConfigured();

        $response = Http::asForm()
            ->timeout(20)
            ->post($this->tokenUrl(), [
                'grant_type' => 'client_credentials',
                'client_id' => config('services.shopify.client_id'),
                'client_secret' => config('services.shopify.client_secret'),
            ]);

        if ($response->failed()) {
            throw new ShopifyAuthenticationException('Shopify authentication failed with status '.$response->status().'.');
        }

        $payload = $response->json();
        $token = $payload['access_token'] ?? null;

        if (! is_string($token) || $token === '') {
            throw new ShopifyAuthenticationException('Shopify authentication response did not include an access token.');
        }

        $expiresIn = max(60, (int) ($payload['expires_in'] ?? 3600) - 60);
        Cache::put($this->cacheKey(), $token, now()->addSeconds($expiresIn));

        return $token;
    }

    public function forgetAccessToken(): void
    {
        Cache::forget($this->cacheKey());
    }

    public function storeDomain(): string
    {
        $store = trim((string) config('services.shopify.store'));
        $store = Str::of($store)->replaceStart('https://', '')->replaceStart('http://', '')->trim('/')->toString();

        if ($store === '') {
            throw new ShopifyAuthenticationException('SHOPIFY_STORE is not configured.');
        }

        return $store;
    }

    private function tokenUrl(): string
    {
        return 'https://'.$this->storeDomain().'/admin/oauth/access_token';
    }

    private function cacheKey(): string
    {
        return 'shopify.access_token.'.sha1($this->storeDomain().'|'.(string) config('services.shopify.client_id'));
    }

    private function ensureConfigured(): void
    {
        foreach (['store', 'client_id', 'client_secret'] as $key) {
            if (blank(config('services.shopify.'.$key))) {
                throw new ShopifyAuthenticationException('Shopify authentication is not fully configured.');
            }
        }
    }
}
