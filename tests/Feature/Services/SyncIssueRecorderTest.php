<?php

namespace Tests\Feature\Services;

use App\Services\SyncIssueRecorder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class SyncIssueRecorderTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_stores_every_detail_of_a_recorded_issue(): void
    {
        $runId = $this->createRun();
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('log')->once();
        Log::shouldReceive('channel')->once()->with('po_sync')->andReturn($logger);

        app(SyncIssueRecorder::class)->record(
            runId: $runId,
            stage: SyncIssueRecorder::STAGE_SHOPIFY_METAFIELD_UPDATE,
            severity: SyncIssueRecorder::SEVERITY_ERROR,
            message: 'Value is invalid JSON.',
            details: ['exception' => 'UnexpectedValueException', 'payload' => [['qty' => 4]]],
            sku: 'P1599',
            variantGid: 'gid://shopify/ProductVariant/50',
        );

        $issue = DB::table('po_sync_issues')->sole();

        $this->assertSame($runId, (int) $issue->run_id);
        $this->assertSame('shopify_metafield_update', $issue->stage);
        $this->assertSame('error', $issue->severity);
        $this->assertSame('P1599', $issue->sku);
        $this->assertSame('gid://shopify/ProductVariant/50', $issue->variant_gid);
        $this->assertSame('Value is invalid JSON.', $issue->message);
        $this->assertSame(
            ['exception' => 'UnexpectedValueException', 'payload' => [['qty' => 4]]],
            json_decode($issue->details, true, flags: JSON_THROW_ON_ERROR),
        );
    }

    public function test_writes_every_recorded_issue_to_the_po_sync_log_at_its_severity(): void
    {
        $runId = $this->createRun();
        $logger = Mockery::mock(LoggerInterface::class);
        $logger->shouldReceive('log')->once()->with('error', 'PO sync issue.', [
            'run_id' => $runId,
            'stage' => 'shopify_metafield_update',
            'sku' => 'P1599',
            'variant_gid' => 'gid://shopify/ProductVariant/50',
            'message' => 'Value is invalid JSON.',
            'details' => ['exception' => 'UnexpectedValueException'],
        ]);
        $logger->shouldReceive('log')->once()->with('warning', 'PO sync issue.', [
            'run_id' => $runId,
            'stage' => 'shopify_variant_lookup',
            'sku' => 'P1',
            'variant_gid' => null,
            'message' => 'No match.',
            'details' => [],
        ]);
        Log::shouldReceive('channel')->twice()->with('po_sync')->andReturn($logger);

        $recorder = app(SyncIssueRecorder::class);
        $recorder->record(
            runId: $runId,
            stage: SyncIssueRecorder::STAGE_SHOPIFY_METAFIELD_UPDATE,
            severity: SyncIssueRecorder::SEVERITY_ERROR,
            message: 'Value is invalid JSON.',
            details: ['exception' => 'UnexpectedValueException'],
            sku: 'P1599',
            variantGid: 'gid://shopify/ProductVariant/50',
        );
        $recorder->record($runId, SyncIssueRecorder::STAGE_SHOPIFY_VARIANT_LOOKUP, SyncIssueRecorder::SEVERITY_WARNING, 'No match.', sku: 'P1');

        $this->assertDatabaseCount('po_sync_issues', 2);
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
}
