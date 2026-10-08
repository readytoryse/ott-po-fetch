<?php

namespace App\Services;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use UnexpectedValueException;

class ShopifyClient
{
    private const MAX_ATTEMPTS = 4;

    /**
     * @param  list<string>  $skus
     * @return array<string, list<string>>
     */
    public function findVariantsBySku(array $skus): array
    {
        if ($skus === []) {
            return [];
        }

        $searchTerms = array_map(
            static fn (string $sku): string => 'sku:"'.addcslashes($sku, '\\"').'"',
            $skus,
        );

        $response = $this->graphql(<<<'GRAPHQL'
            query variantsBySku($searchQuery: String!) {
              productVariants(first: 250, query: $searchQuery) {
                nodes {
                  id
                  sku
                }
              }
            }
            GRAPHQL, [
            'searchQuery' => implode(' OR ', $searchTerms),
        ]);

        $nodes = data_get($response, 'data.productVariants.nodes');

        if (! is_array($nodes)) {
            throw new UnexpectedValueException('Shopify variant search response was missing variant nodes.');
        }

        $matches = array_fill_keys($skus, []);

        foreach ($nodes as $node) {
            if (! is_array($node) || ! is_string($node['sku'] ?? null) || ! is_string($node['id'] ?? null)) {
                continue;
            }

            if (array_key_exists($node['sku'], $matches)) {
                $matches[$node['sku']][] = $node['id'];
            }
        }

        return $matches;
    }

    /**
     * @param  list<string>  $ownerIds
     * @return array<string, string|null>
     */
    public function findMetafieldValues(array $ownerIds, string $namespace, string $key): array
    {
        if ($ownerIds === []) {
            return [];
        }

        $response = $this->graphql(<<<'GRAPHQL'
            query currentMetafieldValues($ownerIds: [ID!]!, $namespace: String!, $key: String!) {
              nodes(ids: $ownerIds) {
                ... on ProductVariant {
                  id
                  metafield(namespace: $namespace, key: $key) {
                    value
                  }
                }
              }
            }
            GRAPHQL, [
            'ownerIds' => $ownerIds,
            'namespace' => $namespace,
            'key' => $key,
        ]);

        $nodes = data_get($response, 'data.nodes');

        if (! is_array($nodes)) {
            throw new UnexpectedValueException('Shopify metafield lookup response was missing nodes.');
        }

        $values = array_fill_keys($ownerIds, null);

        foreach ($nodes as $node) {
            if (is_array($node) && is_string($node['id'] ?? null) && array_key_exists($node['id'], $values)) {
                $value = data_get($node, 'metafield.value');
                $values[$node['id']] = is_string($value) ? $value : null;
            }
        }

        return $values;
    }

    /**
     * @param  list<array{ownerId: string, namespace: string, key: string, type: string, value: string}>  $metafields
     */
    public function setMetafields(array $metafields): void
    {
        $response = $this->graphql(<<<'GRAPHQL'
            mutation setIncomingPurchaseOrders($metafields: [MetafieldsSetInput!]!) {
              metafieldsSet(metafields: $metafields) {
                userErrors {
                  field
                  message
                }
              }
            }
            GRAPHQL, [
            'metafields' => $metafields,
        ]);

        $userErrors = data_get($response, 'data.metafieldsSet.userErrors');

        if (! is_array($userErrors)) {
            throw new UnexpectedValueException('Shopify metafieldsSet response was missing user errors.');
        }

        if ($userErrors !== []) {
            $messages = array_map(
                static fn (array $error): string => (string) ($error['message'] ?? 'Unknown metafield error.'),
                array_filter($userErrors, 'is_array'),
            );

            throw new UnexpectedValueException(implode('; ', $messages));
        }
    }

    /**
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     */
    private function graphql(string $query, array $variables): array
    {
        $domain = trim((string) config('services.shopify.store_domain'));
        $apiVersion = trim((string) config('services.shopify.api_version'));

        if ($domain === '' || $apiVersion === '') {
            throw new UnexpectedValueException('Shopify store domain and API version must be configured.');
        }

        $domain = preg_replace('#^https?://#i', '', $domain) ?? $domain;
        $endpoint = 'https://'.rtrim($domain, '/').'/admin/api/'.rawurlencode($apiVersion).'/graphql.json';
        $tokenRefreshAttempted = false;

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $response = Http::acceptJson()
                ->withHeaders(['X-Shopify-Access-Token' => $this->accessToken()])
                ->timeout(30)
                ->post($endpoint, [
                    'query' => $query,
                    'variables' => $variables,
                ]);

            if ($response->unauthorized() && ! $tokenRefreshAttempted) {
                Cache::forget($this->tokenCacheKey());
                $tokenRefreshAttempted = true;

                continue;
            }

            if ($response->status() === 429 && $attempt < self::MAX_ATTEMPTS) {
                $this->wait($this->retryAfterSeconds($response->header('Retry-After')));

                continue;
            }

            $response->throw();
            $payload = $response->json();

            if (! is_array($payload)) {
                throw new UnexpectedValueException('Shopify returned an invalid GraphQL response.');
            }

            $errors = $payload['errors'] ?? [];
            $throttled = is_array($errors) && collect($errors)->contains(
                static fn (mixed $error): bool => data_get($error, 'extensions.code') === 'THROTTLED',
            );

            if ($throttled && $attempt < self::MAX_ATTEMPTS) {
                $this->wait($this->throttleWaitSeconds($payload));

                continue;
            }

            if (is_array($errors) && $errors !== []) {
                $messages = array_map(
                    static fn (array $error): string => (string) ($error['message'] ?? 'Unknown GraphQL error.'),
                    array_filter($errors, 'is_array'),
                );

                throw new UnexpectedValueException(implode('; ', $messages));
            }

            $this->waitForAvailableCost($payload);

            return $payload;
        }

        throw new UnexpectedValueException('Shopify GraphQL request exceeded the retry limit.');
    }

    private function accessToken(): string
    {
        $cacheKey = $this->tokenCacheKey();
        $cachedToken = Cache::get($cacheKey);

        if (is_string($cachedToken) && $cachedToken !== '') {
            return $cachedToken;
        }

        $domain = trim((string) config('services.shopify.store_domain'));
        $clientId = trim((string) config('services.shopify.client_id'));
        $clientSecret = trim((string) config('services.shopify.client_secret'));

        if ($domain === '' || $clientId === '' || $clientSecret === '') {
            throw new UnexpectedValueException('Shopify store domain, client ID, and client secret must be configured.');
        }

        $domain = preg_replace('#^https?://#i', '', $domain) ?? $domain;
        $response = Http::acceptJson()
            ->asForm()
            ->connectTimeout(10)
            ->timeout(30)
            ->post('https://'.rtrim($domain, '/').'/admin/oauth/access_token', [
                'grant_type' => 'client_credentials',
                'client_id' => $clientId,
                'client_secret' => $clientSecret,
            ]);

        $response->throw();

        $payload = $response->json();

        if (! is_array($payload) || ! is_string($payload['access_token'] ?? null) || $payload['access_token'] === '') {
            throw new UnexpectedValueException('Shopify token response did not contain an access token.');
        }

        $grantedScopes = preg_split('/[\s,]+/', trim((string) ($payload['scope'] ?? '')), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        if (in_array('write_products', $grantedScopes, true)) {
            $grantedScopes[] = 'read_products';
        }

        $missingScopes = array_diff(['read_products', 'write_products'], $grantedScopes);

        if ($missingScopes !== []) {
            throw new UnexpectedValueException('Shopify app token is missing required scopes: '.implode(', ', $missingScopes).'. Publish the app version with these scopes and approve the update in Shopify.');
        }

        $expiresIn = (int) ($payload['expires_in'] ?? 0);

        if ($expiresIn <= 60) {
            throw new UnexpectedValueException('Shopify token response has an invalid expiry time.');
        }

        Cache::put($cacheKey, $payload['access_token'], now()->addSeconds($expiresIn - 60));

        return $payload['access_token'];
    }

    private function tokenCacheKey(): string
    {
        $identity = implode('|', [
            (string) config('services.shopify.store_domain'),
            (string) config('services.shopify.client_id'),
            hash('sha256', (string) config('services.shopify.client_secret')),
        ]);

        return 'shopify.admin_token.'.hash('sha256', $identity);
    }

    private function retryAfterSeconds(?string $retryAfter): int
    {
        return max(1, (int) ceil(is_numeric($retryAfter) ? (float) $retryAfter : 1));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function throttleWaitSeconds(array $payload): int
    {
        $restoreRate = max(1.0, (float) data_get($payload, 'extensions.cost.throttleStatus.restoreRate', 50));
        $requestedCost = max(1.0, (float) data_get($payload, 'extensions.cost.requestedQueryCost', 100));

        return max(1, (int) ceil($requestedCost / $restoreRate));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function waitForAvailableCost(array $payload): void
    {
        $available = data_get($payload, 'extensions.cost.throttleStatus.currentlyAvailable');
        $restoreRate = (float) data_get($payload, 'extensions.cost.throttleStatus.restoreRate', 0);

        if (is_numeric($available) && $available < 100 && $restoreRate > 0) {
            $this->wait(max(1, (int) ceil((100 - (float) $available) / $restoreRate)));
        }
    }

    private function wait(int $seconds): void
    {
        Sleep::for($seconds)->seconds();
    }
}
