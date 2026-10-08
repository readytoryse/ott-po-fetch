<?php

namespace Tests\Feature\Services;

use App\Services\ShopifyMetafieldSyncService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyMetafieldSyncServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_aggregates_po_payloads_clears_empty_skus_and_skips_unchanged_hashes(): void
    {
        $this->configureShopify();
        $this->insertPoLine(19424, 'P1551', 'PO-1001', '24', '2026-09-18 00:00:00');
        $this->insertPoLine(19425, 'P1551', 'PO-1001', '12', '2026-09-18 00:00:00');
        $this->insertPoLine(19426, 'P1551', 'PO-1042', '50', '2026-10-20 00:00:00');

        Http::preventStrayRequests();
        Http::fake([
            'https://ott-test.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'test-access-token',
                'scope' => 'read_products,write_products',
                'expires_in' => 86399,
            ]),
            'https://ott-test.myshopify.com/admin/api/2026-10/graphql.json' => Http::sequence()
                ->push(['data' => ['productVariants' => ['nodes' => [
                    ['id' => 'gid://shopify/ProductVariant/1', 'sku' => 'P1541'],
                    ['id' => 'gid://shopify/ProductVariant/2', 'sku' => 'P1551'],
                ]]]])
                ->push(['data' => ['nodes' => []]])
                ->push(['data' => ['metafieldsSet' => ['userErrors' => []]]]),
        ]);

        $service = app(ShopifyMetafieldSyncService::class);
        $firstResult = $service->sync(['P1551', 'P1541']);
        $secondResult = $service->sync(['P1551', 'P1541']);

        $this->assertSame([
            'synced_count' => 2,
            'unchanged_count' => 0,
            'unmatched_skus' => [],
            'failed_skus' => [],
        ], $firstResult);
        $this->assertSame([
            'synced_count' => 0,
            'unchanged_count' => 2,
            'unmatched_skus' => [],
            'failed_skus' => [],
        ], $secondResult);
        $this->assertSame([
            ['qty' => 36, 'date' => '2026-09-18', 'reference' => 'PO-1001'],
            ['qty' => 50, 'date' => '2026-10-20', 'reference' => 'PO-1042'],
        ], json_decode((string) DB::table('variant_metafield_syncs')->where('sku', 'P1551')->value('last_payload'), true, flags: JSON_THROW_ON_ERROR));
        $this->assertSame('[]', DB::table('variant_metafield_syncs')->where('sku', 'P1541')->value('last_payload'));
        $this->assertDatabaseHas('variant_metafield_syncs', ['sku' => 'P1551', 'status' => 'synced', 'variant_gid' => 'gid://shopify/ProductVariant/2']);
        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => isset($request->data()['variables']['metafields'])
            && $request->data()['variables']['metafields'][0]['value'] === '[]'
            && $request->data()['variables']['metafields'][1]['value'] === '[{"qty":36,"date":"2026-09-18","reference":"PO-1001"},{"qty":50,"date":"2026-10-20","reference":"PO-1042"}]');
    }

    public function test_marks_missing_shopify_skus_as_unmatched_without_writing_metafields(): void
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
                'data' => ['productVariants' => ['nodes' => []]],
            ]),
        ]);

        $result = app(ShopifyMetafieldSyncService::class)->sync(['SKU-NOT-IN-SHOPIFY']);

        $this->assertSame(['SKU-NOT-IN-SHOPIFY'], $result['unmatched_skus']);
        $this->assertSame(0, $result['synced_count']);
        $this->assertDatabaseHas('variant_metafield_syncs', [
            'sku' => 'SKU-NOT-IN-SHOPIFY',
            'status' => 'unmatched',
            'error' => 'No Shopify variant matches this SKU.',
        ]);
        $this->assertDatabaseHas('po_sync_issues', [
            'stage' => 'shopify_variant_lookup',
            'severity' => 'warning',
            'sku' => 'SKU-NOT-IN-SHOPIFY',
            'message' => 'No Shopify variant matches this SKU.',
        ]);
        Http::assertSentCount(2);
        Http::assertNotSent(fn (Request $request): bool => $request->url() === 'https://ott-test.myshopify.com/admin/api/2026-10/graphql.json'
            && str_contains($request->data()['query'], 'metafieldsSet'));
    }

    public function test_first_push_includes_active_skus_already_present_in_the_po_database(): void
    {
        $this->configureShopify();
        $this->insertPoLine(19450, 'P1599', 'PO-500', '4', '2026-11-01 00:00:00');

        Http::preventStrayRequests();
        Http::fake([
            'https://ott-test.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'test-access-token',
                'scope' => 'read_products,write_products',
                'expires_in' => 86399,
            ]),
            'https://ott-test.myshopify.com/admin/api/2026-10/graphql.json' => Http::sequence()
                ->push(['data' => ['productVariants' => ['nodes' => [
                    ['id' => 'gid://shopify/ProductVariant/50', 'sku' => 'P1599'],
                ]]]])
                ->push(['data' => ['nodes' => []]])
                ->push(['data' => ['metafieldsSet' => ['userErrors' => []]]]),
        ]);

        $result = app(ShopifyMetafieldSyncService::class)->sync([]);

        $this->assertSame(1, $result['synced_count']);
        $this->assertDatabaseHas('variant_metafield_syncs', [
            'sku' => 'P1599',
            'status' => 'synced',
            'variant_gid' => 'gid://shopify/ProductVariant/50',
        ]);
        Http::assertSentCount(4);
    }

    public function test_sends_metafield_updates_in_batches_of_twenty_five(): void
    {
        $this->configureShopify();
        $now = now();
        $skus = [];

        for ($index = 1; $index <= 26; $index++) {
            $sku = 'SKU-'.$index;
            $skus[] = $sku;
            DB::table('variant_metafield_syncs')->insert([
                'sku' => $sku,
                'variant_gid' => 'gid://shopify/ProductVariant/'.$index,
                'status' => 'pending',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Http::preventStrayRequests();
        Http::fake([
            'https://ott-test.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'test-access-token',
                'scope' => 'read_products,write_products',
                'expires_in' => 86399,
            ]),
            'https://ott-test.myshopify.com/admin/api/2026-10/graphql.json' => Http::sequence()
                ->push(['data' => ['nodes' => []]])
                ->push(['data' => ['metafieldsSet' => ['userErrors' => []]]])
                ->push(['data' => ['nodes' => []]])
                ->push(['data' => ['metafieldsSet' => ['userErrors' => []]]]),
        ]);

        $result = app(ShopifyMetafieldSyncService::class)->sync($skus);

        $this->assertSame(26, $result['synced_count']);
        Http::assertSentCount(5);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ott-test.myshopify.com/admin/api/2026-10/graphql.json'
            && count($request->data()['variables']['metafields'] ?? []) === 25);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ott-test.myshopify.com/admin/api/2026-10/graphql.json'
            && count($request->data()['variables']['metafields'] ?? []) === 1);
        $this->assertDatabaseCount('variant_metafield_syncs', 26);
    }

    public function test_records_the_previous_and_new_metafield_value_for_each_update(): void
    {
        $this->travelTo('2026-10-08 09:00:00');
        $this->configureShopify();
        $this->insertPoLine(19450, 'P1599', 'PO-500', '4', '2026-11-01 00:00:00');
        $runId = $this->createRun();

        Http::preventStrayRequests();
        Http::fake([
            'https://ott-test.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'test-access-token',
                'scope' => 'read_products,write_products',
                'expires_in' => 86399,
            ]),
            'https://ott-test.myshopify.com/admin/api/2026-10/graphql.json' => Http::sequence()
                ->push(['data' => ['productVariants' => ['nodes' => [
                    ['id' => 'gid://shopify/ProductVariant/50', 'sku' => 'P1599'],
                ]]]])
                ->push(['data' => ['nodes' => [[
                    'id' => 'gid://shopify/ProductVariant/50',
                    'metafield' => ['value' => '[{"qty":9,"date":"2026-10-01","reference":"PO-400"}]'],
                ]]]])
                ->push(['data' => ['metafieldsSet' => ['userErrors' => []]]]),
        ]);

        app(ShopifyMetafieldSyncService::class)->sync([], $runId);

        $this->assertDatabaseCount('variant_metafield_updates', 1);
        $this->assertDatabaseHas('variant_metafield_updates', [
            'run_id' => $runId,
            'sku' => 'P1599',
            'variant_gid' => 'gid://shopify/ProductVariant/50',
            'old_value' => '[{"qty":9,"date":"2026-10-01","reference":"PO-400"}]',
            'new_value' => '[{"qty":4,"date":"2026-11-01","reference":"PO-500"}]',
            'metafield_updated_at' => '2026-10-08 09:00:00',
        ]);
        $this->assertDatabaseCount('po_sync_issues', 0);
    }

    public function test_records_an_error_issue_and_no_update_history_when_shopify_rejects_the_metafield(): void
    {
        $this->configureShopify();
        $this->insertPoLine(19450, 'P1599', 'PO-500', '4', '2026-11-01 00:00:00');
        $runId = $this->createRun();

        Http::preventStrayRequests();
        Http::fake([
            'https://ott-test.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'test-access-token',
                'scope' => 'read_products,write_products',
                'expires_in' => 86399,
            ]),
            'https://ott-test.myshopify.com/admin/api/2026-10/graphql.json' => Http::sequence()
                ->push(['data' => ['productVariants' => ['nodes' => [
                    ['id' => 'gid://shopify/ProductVariant/50', 'sku' => 'P1599'],
                ]]]])
                ->push(['data' => ['nodes' => []]])
                ->push(['data' => ['metafieldsSet' => ['userErrors' => [
                    ['field' => ['metafields', '0', 'value'], 'message' => 'Value is invalid JSON.'],
                ]]]]),
        ]);

        $result = app(ShopifyMetafieldSyncService::class)->sync([], $runId);

        $this->assertSame(['P1599'], $result['failed_skus']);
        $this->assertDatabaseHas('variant_metafield_syncs', ['sku' => 'P1599', 'status' => 'error']);
        $this->assertDatabaseHas('po_sync_issues', [
            'run_id' => $runId,
            'stage' => 'shopify_metafield_update',
            'severity' => 'error',
            'sku' => 'P1599',
            'variant_gid' => 'gid://shopify/ProductVariant/50',
            'message' => 'Value is invalid JSON.',
        ]);
        $this->assertDatabaseCount('variant_metafield_updates', 0);
    }

    public function test_clears_a_synced_metafield_whose_po_lines_ended_in_a_run_that_did_not_push(): void
    {
        $this->configureShopify();
        $now = now();
        DB::table('variant_metafield_syncs')->insert([
            'sku' => 'P1599',
            'variant_gid' => 'gid://shopify/ProductVariant/50',
            'last_payload' => '[{"qty":4,"date":"2026-10-07","reference":"PO-500"}]',
            'payload_hash' => hash('sha256', '[{"qty":4,"date":"2026-10-07","reference":"PO-500"}]'),
            'status' => 'synced',
            'synced_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://ott-test.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'test-access-token',
                'scope' => 'read_products,write_products',
                'expires_in' => 86399,
            ]),
            'https://ott-test.myshopify.com/admin/api/2026-10/graphql.json' => Http::sequence()
                ->push(['data' => ['nodes' => [[
                    'id' => 'gid://shopify/ProductVariant/50',
                    'metafield' => ['value' => '[{"qty":4,"date":"2026-10-07","reference":"PO-500"}]'],
                ]]]])
                ->push(['data' => ['metafieldsSet' => ['userErrors' => []]]]),
        ]);

        $result = app(ShopifyMetafieldSyncService::class)->sync([]);

        $this->assertSame(1, $result['synced_count']);
        $this->assertDatabaseHas('variant_metafield_updates', [
            'sku' => 'P1599',
            'old_value' => '[{"qty":4,"date":"2026-10-07","reference":"PO-500"}]',
            'new_value' => '[]',
        ]);
        Http::assertSent(fn (Request $request): bool => ($request->data()['variables']['metafields'][0]['value'] ?? null) === '[]');
    }

    public function test_does_not_repeat_the_unmatched_warning_while_a_sku_stays_unmatched(): void
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
                'data' => ['productVariants' => ['nodes' => []]],
            ]),
        ]);

        $service = app(ShopifyMetafieldSyncService::class);
        $service->sync(['SKU-NOT-IN-SHOPIFY']);
        $service->sync(['SKU-NOT-IN-SHOPIFY']);

        $this->assertDatabaseCount('po_sync_issues', 1);
    }

    private function createRun(): int
    {
        $now = now();

        return DB::table('po_sync_runs')->insertGetId([
            'started_at' => $now,
            'status' => 'running',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
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

    private function insertPoLine(int $polId, string $sku, string $reference, string $quantity, string $promisedDate): void
    {
        $now = now();

        DB::table('po_lines')->insert([
            'pol_id' => $polId,
            'poh_order_number' => $reference,
            'sku' => $sku,
            'qty_ordered' => $quantity,
            'qty_received' => 0,
            'qty_outstanding' => $quantity,
            'supplier_promised_date' => $promisedDate,
            'row_hash' => hash('sha256', $polId.'|'.$quantity),
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'is_active' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
