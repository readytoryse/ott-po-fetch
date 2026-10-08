<?php

namespace Tests\Feature\Console\Commands;

use App\Services\ShopifyMetafieldSyncService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Tests\TestCase;

class SyncPurchaseOrdersTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_logs_only_lines_with_positive_outstanding_quantity(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        config([
            'services.orderwise.base_url' => 'https://orderwise.test/owapi',
            'services.orderwise.username' => 'test-user',
            'services.orderwise.password' => 'test-password',
            'services.orderwise.export_id' => '49',
            'services.orderwise.token_cache_minutes' => 55,
            'services.orderwise.timeout_seconds' => 30,
            'services.shopify.push_enabled' => false,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://orderwise.test/owapi/token/gettoken' => Http::response(['token' => 'test-token']),
            'https://orderwise.test/owapi/system/export-definition/49' => Http::response([
                ['po_line_id' => 19424, 'sku' => 'P1551', 'qty_outstanding' => 6],
                ['po_line_id' => 19425, 'sku' => 'P1541', 'qty_outstanding' => 0],
            ]),
        ]);

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->once()->with('OrderWise PO export completed.', Mockery::on(
            static fn (array $context): bool => $context['since'] === '2026-10-05'
                && $context['rows_fetched'] === 2
                && $context['rows_outstanding'] === 1
                && $context['rows_skipped'] === 1,
        ));
        $logger->shouldReceive('info')->once()->with('Outstanding PO line.', [
            'po_line_id' => 19424,
            'sku' => 'P1551',
            'qty_outstanding' => 6,
        ]);
        Log::shouldReceive('channel')->once()->with('po_sync')->andReturn($logger);

        $this->artisan('orderwise:sync-po')
            ->expectsOutput('Fetched 2 rows; logged 1 outstanding PO lines.')
            ->assertSuccessful();

        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://orderwise.test/owapi/system/export-definition/49');
        $this->assertDatabaseHas('po_sync_runs', ['status' => 'success', 'rows_fetched' => 2, 'new_count' => 1]);
        $this->assertDatabaseHas('po_lines', ['pol_id' => 19424, 'sku' => 'P1551', 'is_active' => true]);
        $this->assertDatabaseCount('po_line_changes', 1);
        $this->assertDatabaseCount('po_sync_issues', 0);
    }

    public function test_records_failed_runs_and_logs_the_error_when_orderwise_returns_an_error(): void
    {
        config([
            'services.orderwise.base_url' => 'https://orderwise.test/owapi',
            'services.orderwise.username' => 'test-user',
            'services.orderwise.password' => 'test-password',
            'services.orderwise.export_id' => '49',
            'services.orderwise.token_cache_minutes' => 55,
            'services.orderwise.timeout_seconds' => 30,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://orderwise.test/owapi/token/gettoken' => Http::response(['token' => 'test-token']),
            'https://orderwise.test/owapi/system/export-definition/49' => Http::response(['Message' => 'Export failed'], 400),
        ]);

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('log')->once()->with('error', 'PO sync issue.', Mockery::on(
            static fn (array $context): bool => $context['stage'] === 'orderwise_export'
                && $context['sku'] === null
                && is_int($context['run_id'])
                && str_contains($context['message'], 'Export failed'),
        ));
        Log::shouldReceive('channel')->with('po_sync')->andReturn($logger);

        $this->artisan('orderwise:sync-po')
            ->expectsOutput('OrderWise PO export failed. Check the PO sync log.')
            ->assertFailed();

        $this->assertDatabaseHas('po_sync_runs', ['status' => 'failed', 'rows_fetched' => 0]);
        $this->assertDatabaseCount('po_lines', 0);
        $this->assertDatabaseCount('po_line_changes', 0);
        $this->assertDatabaseHas('po_sync_issues', ['stage' => 'orderwise_export', 'severity' => 'error', 'sku' => null]);
    }

    public function test_records_and_logs_the_error_when_the_shopify_sync_throws(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        config([
            'services.orderwise.base_url' => 'https://orderwise.test/owapi',
            'services.orderwise.username' => 'test-user',
            'services.orderwise.password' => 'test-password',
            'services.orderwise.export_id' => '49',
            'services.orderwise.token_cache_minutes' => 55,
            'services.orderwise.timeout_seconds' => 30,
            'services.shopify.push_enabled' => true,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://orderwise.test/owapi/token/gettoken' => Http::response(['token' => 'test-token']),
            'https://orderwise.test/owapi/system/export-definition/49' => Http::response([
                ['po_line_id' => 19424, 'sku' => 'P1551', 'qty_outstanding' => 6],
            ]),
        ]);

        $shopifySyncService = Mockery::mock(ShopifyMetafieldSyncService::class);
        $shopifySyncService->shouldReceive('sync')->once()->andThrow(new RuntimeException('Shopify is unreachable.'));
        $this->app->instance(ShopifyMetafieldSyncService::class, $shopifySyncService);

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('log')->once()->with('error', 'PO sync issue.', Mockery::on(
            static fn (array $context): bool => $context['stage'] === 'shopify_sync'
                && $context['message'] === 'Shopify is unreachable.',
        ));
        Log::shouldReceive('channel')->with('po_sync')->andReturn($logger);

        $this->artisan('orderwise:sync-po')
            ->expectsOutput('Shopify metafield sync failed. Check the PO sync log.')
            ->assertFailed();

        $this->assertDatabaseHas('po_sync_issues', [
            'stage' => 'shopify_sync',
            'severity' => 'error',
            'message' => 'Shopify is unreachable.',
        ]);
    }

    public function test_pushes_only_affected_skus_when_shopify_push_is_enabled(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        config([
            'services.orderwise.base_url' => 'https://orderwise.test/owapi',
            'services.orderwise.username' => 'test-user',
            'services.orderwise.password' => 'test-password',
            'services.orderwise.export_id' => '49',
            'services.orderwise.token_cache_minutes' => 55,
            'services.orderwise.timeout_seconds' => 30,
            'services.shopify.push_enabled' => true,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://orderwise.test/owapi/token/gettoken' => Http::response(['token' => 'test-token']),
            'https://orderwise.test/owapi/system/export-definition/49' => Http::response([
                ['po_line_id' => 19424, 'sku' => 'P1551', 'qty_outstanding' => 6],
            ]),
        ]);

        $shopifySyncService = Mockery::mock(ShopifyMetafieldSyncService::class);
        $shopifySyncService->shouldReceive('sync')->once()->with(['P1551'], Mockery::type('int'))->andReturn([
            'synced_count' => 1,
            'unchanged_count' => 0,
            'unmatched_skus' => [],
            'failed_skus' => [],
        ]);
        $this->app->instance(ShopifyMetafieldSyncService::class, $shopifySyncService);

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->once()->with('OrderWise PO export completed.', Mockery::type('array'));
        $logger->shouldReceive('info')->once()->with('Outstanding PO line.', Mockery::type('array'));
        $logger->shouldReceive('info')->once()->with('Shopify metafield sync completed.', Mockery::on(
            static fn (array $context): bool => $context['synced_count'] === 1
                && $context['unmatched_skus'] === []
                && $context['failed_skus'] === [],
        ));
        Log::shouldReceive('channel')->once()->with('po_sync')->andReturn($logger);

        $this->artisan('orderwise:sync-po')
            ->expectsOutput('Shopify variants synced: 1; unchanged: 0; unmatched: 0; failed: 0.')
            ->assertSuccessful();

        Http::assertSentCount(2);
    }

    public function test_returns_failure_when_shopify_reports_failed_skus(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        config([
            'services.orderwise.base_url' => 'https://orderwise.test/owapi',
            'services.orderwise.username' => 'test-user',
            'services.orderwise.password' => 'test-password',
            'services.orderwise.export_id' => '49',
            'services.orderwise.token_cache_minutes' => 55,
            'services.orderwise.timeout_seconds' => 30,
            'services.shopify.push_enabled' => true,
        ]);

        Http::preventStrayRequests();
        Http::fake([
            'https://orderwise.test/owapi/token/gettoken' => Http::response(['token' => 'test-token']),
            'https://orderwise.test/owapi/system/export-definition/49' => Http::response([
                ['po_line_id' => 19424, 'sku' => 'P1551', 'qty_outstanding' => 6],
            ]),
        ]);

        $shopifySyncService = Mockery::mock(ShopifyMetafieldSyncService::class);
        $shopifySyncService->shouldReceive('sync')->once()->with(['P1551'], Mockery::type('int'))->andReturn([
            'synced_count' => 0,
            'unchanged_count' => 0,
            'unmatched_skus' => [],
            'failed_skus' => ['P1551'],
        ]);
        $this->app->instance(ShopifyMetafieldSyncService::class, $shopifySyncService);

        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('info')->once()->with('OrderWise PO export completed.', Mockery::type('array'));
        $logger->shouldReceive('info')->once()->with('Outstanding PO line.', Mockery::type('array'));
        $logger->shouldReceive('info')->once()->with('Shopify metafield sync completed.', Mockery::type('array'));
        $logger->shouldReceive('error')->once()->with('Shopify metafield sync had failed SKUs.', Mockery::on(
            static fn (array $context): bool => $context['failed_skus'] === ['P1551'],
        ));
        Log::shouldReceive('channel')->once()->with('po_sync')->andReturn($logger);

        $this->artisan('orderwise:sync-po')
            ->expectsOutput('Shopify variants synced: 0; unchanged: 0; unmatched: 0; failed: 1.')
            ->assertFailed();
    }
}
