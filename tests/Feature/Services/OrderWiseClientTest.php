<?php

namespace Tests\Feature\Services;

use App\Services\OrderWiseClient;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderWiseClientTest extends TestCase
{
    public function test_posts_todays_date_as_since_with_basic_auth_and_caches_the_token(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
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
            'https://orderwise.test/owapi/system/export-definition/49' => Http::sequence()
                ->push([['po_line_id' => 19424, 'qty_outstanding' => 6]])
                ->push([['po_line_id' => 19424, 'qty_outstanding' => 6]]),
        ]);

        $client = app(OrderWiseClient::class);
        $rows = $client->fetchOutstandingPurchaseOrderLines();
        $client->fetchOutstandingPurchaseOrderLines();

        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://orderwise.test/owapi/token/gettoken'
            && $request->method() === 'GET'
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('test-user:test-password')));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://orderwise.test/owapi/system/export-definition/49'
            && $request->method() === 'POST'
            && $request->hasHeader('Authorization', 'Bearer test-token')
            && $request->data() === [
                ['name' => '@since', 'value' => '2026-10-05'],
            ]);
        $this->assertSame([['po_line_id' => 19424, 'qty_outstanding' => 6]], $rows);
    }

    public function test_refreshes_the_cached_token_once_after_an_unauthorized_export(): void
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
            'https://orderwise.test/owapi/token/gettoken' => Http::sequence()
                ->push(['token' => 'expired-token'])
                ->push(['token' => 'fresh-token']),
            'https://orderwise.test/owapi/system/export-definition/49' => Http::sequence()
                ->push(['message' => 'Unauthorized'], 401)
                ->push([['po_line_id' => 19424, 'qty_outstanding' => 6]]),
        ]);

        $rows = app(OrderWiseClient::class)->fetchOutstandingPurchaseOrderLines();

        Http::assertSentCount(4);
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://orderwise.test/owapi/system/export-definition/49'
            && $request->hasHeader('Authorization', 'Bearer expired-token'));
        Http::assertSent(fn (Request $request): bool => $request->url() === 'https://orderwise.test/owapi/system/export-definition/49'
            && $request->hasHeader('Authorization', 'Bearer fresh-token'));
        $this->assertSame([['po_line_id' => 19424, 'qty_outstanding' => 6]], $rows);
    }
}
