<?php

namespace Tests\Feature\Services;

use App\Services\ShopifyClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyClientTest extends TestCase
{
    public function test_exchanges_client_credentials_and_caches_the_token_for_variant_queries(): void
    {
        $this->configureShopify();

        Http::preventStrayRequests();
        Http::fake([
            'https://ott-test.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'test-access-token',
                'scope' => 'read_products,write_products',
                'expires_in' => 86399,
            ]),
            'https://ott-test.myshopify.com/admin/api/2026-10/graphql.json' => Http::response([
                'data' => [
                    'productVariants' => [
                        'nodes' => [
                            ['id' => 'gid://shopify/ProductVariant/1', 'sku' => 'P1551'],
                            ['id' => 'gid://shopify/ProductVariant/2', 'sku' => 'P1541'],
                            ['id' => 'gid://shopify/ProductVariant/3', 'sku' => 'OTHER'],
                        ],
                    ],
                ],
            ]),
        ]);

        $client = app(ShopifyClient::class);
        $matches = $client->findVariantsBySku(['P1551', 'P1541']);
        $client->findVariantsBySku(['P1551']);

        $this->assertSame([
            'P1551' => ['gid://shopify/ProductVariant/1'],
            'P1541' => ['gid://shopify/ProductVariant/2'],
        ], $matches);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ott-test.myshopify.com/admin/oauth/access_token'
            && $request->method() === 'POST'
            && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
            && str_contains($request->body(), 'grant_type=client_credentials')
            && str_contains($request->body(), 'client_id=test-client-id')
            && str_contains($request->body(), 'client_secret=test-client-secret'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ott-test.myshopify.com/admin/api/2026-10/graphql.json'
            && $request->hasHeader('X-Shopify-Access-Token', 'test-access-token'));
    }

    public function test_sends_the_variant_metafield_mutation(): void
    {
        $this->configureShopify();

        Http::preventStrayRequests();
        Http::fake([
            'https://ott-test.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'test-access-token',
                'scope' => 'read_products,write_products',
                'expires_in' => 86399,
            ]),
            'https://ott-test.myshopify.com/admin/api/2026-10/graphql.json' => Http::response([
                'data' => ['metafieldsSet' => ['userErrors' => []]],
            ]),
        ]);

        $metafields = [[
            'ownerId' => 'gid://shopify/ProductVariant/1',
            'namespace' => 'custom',
            'key' => 'incoming_purchase_orders',
            'type' => 'json',
            'value' => '[]',
        ]];

        app(ShopifyClient::class)->setMetafields($metafields);

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ott-test.myshopify.com/admin/api/2026-10/graphql.json'
            && $request->hasHeader('X-Shopify-Access-Token', 'test-access-token')
            && str_contains($request->data()['query'], 'metafieldsSet')
            && $request->data()['variables']['metafields'] === $metafields);
    }

    public function test_returns_current_metafield_values_keyed_by_variant_with_null_for_variants_without_one(): void
    {
        $this->configureShopify();

        Http::preventStrayRequests();
        Http::fake([
            'https://ott-test.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'test-access-token',
                'scope' => 'read_products,write_products',
                'expires_in' => 86399,
            ]),
            'https://ott-test.myshopify.com/admin/api/2026-10/graphql.json' => Http::response([
                'data' => ['nodes' => [
                    ['id' => 'gid://shopify/ProductVariant/1', 'metafield' => ['value' => '[]']],
                    ['id' => 'gid://shopify/ProductVariant/2', 'metafield' => null],
                    null,
                ]],
            ]),
        ]);

        $values = app(ShopifyClient::class)->findMetafieldValues([
            'gid://shopify/ProductVariant/1',
            'gid://shopify/ProductVariant/2',
            'gid://shopify/ProductVariant/3',
        ], 'custom', 'incoming_purchase_orders');

        $this->assertSame([
            'gid://shopify/ProductVariant/1' => '[]',
            'gid://shopify/ProductVariant/2' => null,
            'gid://shopify/ProductVariant/3' => null,
        ], $values);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ott-test.myshopify.com/admin/api/2026-10/graphql.json'
            && $request->data()['variables']['namespace'] === 'custom'
            && $request->data()['variables']['key'] === 'incoming_purchase_orders');
    }

    public function test_refreshes_an_expired_access_token_after_a_401(): void
    {
        $this->configureShopify();

        Http::preventStrayRequests();
        Http::fake([
            'https://ott-test.myshopify.com/admin/oauth/access_token' => Http::sequence()
                ->push(['access_token' => 'expired-token', 'scope' => 'read_products,write_products', 'expires_in' => 86399])
                ->push(['access_token' => 'refreshed-token', 'scope' => 'read_products,write_products', 'expires_in' => 86399]),
            'https://ott-test.myshopify.com/admin/api/2026-10/graphql.json' => Http::sequence()
                ->push(['errors' => [['message' => 'Unauthorized']]], 401)
                ->push(['data' => ['productVariants' => ['nodes' => [
                    ['id' => 'gid://shopify/ProductVariant/1', 'sku' => 'P1551'],
                ]]]]),
        ]);

        $matches = app(ShopifyClient::class)->findVariantsBySku(['P1551']);

        $this->assertSame(['P1551' => ['gid://shopify/ProductVariant/1']], $matches);
        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ott-test.myshopify.com/admin/api/2026-10/graphql.json'
            && $request->hasHeader('X-Shopify-Access-Token', 'expired-token'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ott-test.myshopify.com/admin/api/2026-10/graphql.json'
            && $request->hasHeader('X-Shopify-Access-Token', 'refreshed-token'));
    }

    public function test_accepts_a_token_whose_scope_list_has_write_products_without_read_products(): void
    {
        $this->configureShopify();

        Http::preventStrayRequests();
        Http::fake([
            'https://ott-test.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'write-scope-token',
                'scope' => 'write_product_feeds,write_products',
                'expires_in' => 86399,
            ]),
            'https://ott-test.myshopify.com/admin/api/2026-10/graphql.json' => Http::response([
                'data' => ['productVariants' => ['nodes' => [
                    ['id' => 'gid://shopify/ProductVariant/1', 'sku' => 'P1551'],
                ]]],
            ]),
        ]);

        $matches = app(ShopifyClient::class)->findVariantsBySku(['P1551']);

        $this->assertSame(['P1551' => ['gid://shopify/ProductVariant/1']], $matches);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ott-test.myshopify.com/admin/api/2026-10/graphql.json'
            && $request->hasHeader('X-Shopify-Access-Token', 'write-scope-token'));
    }

    public function test_rejects_tokens_missing_required_product_scopes(): void
    {
        $this->configureShopify();

        Http::preventStrayRequests();
        Http::fake([
            'https://ott-test.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'read-only-token',
                'scope' => 'read_products',
                'expires_in' => 86399,
            ]),
        ]);

        try {
            app(ShopifyClient::class)->findVariantsBySku(['P1551']);
            $this->fail('A token without write_products should be rejected.');
        } catch (\UnexpectedValueException $exception) {
            $this->assertStringContainsString('write_products', $exception->getMessage());
        }

        Http::assertSentCount(1);
    }

    private function configureShopify(): void
    {
        Cache::flush();

        config([
            'services.shopify.store_domain' => 'ott-test.myshopify.com',
            'services.shopify.client_id' => 'test-client-id',
            'services.shopify.client_secret' => 'test-client-secret',
            'services.shopify.api_version' => '2026-10',
        ]);
    }
}
