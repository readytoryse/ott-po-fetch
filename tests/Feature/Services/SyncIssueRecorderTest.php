<?php

namespace Tests\Feature\Services;

use App\Services\SyncIssueRecorder;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SyncIssueRecorderTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_stores_every_detail_of_a_recorded_issue(): void
    {
        $runId = $this->createRun();

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

    public function test_returns_only_the_errors_of_the_requested_run(): void
    {
        $recorder = app(SyncIssueRecorder::class);
        $runId = $this->createRun();
        $otherRunId = $this->createRun();

        $recorder->record($runId, SyncIssueRecorder::STAGE_ORDERWISE_EXPORT, SyncIssueRecorder::SEVERITY_ERROR, 'Export failed.');
        $recorder->record($runId, SyncIssueRecorder::STAGE_SHOPIFY_VARIANT_LOOKUP, SyncIssueRecorder::SEVERITY_WARNING, 'No match.', sku: 'P1');
        $recorder->record($otherRunId, SyncIssueRecorder::STAGE_SHOPIFY_SYNC, SyncIssueRecorder::SEVERITY_ERROR, 'Other run failed.');

        $errors = $recorder->errorsForRun($runId);

        $this->assertSame(['Export failed.'], $errors->pluck('message')->all());
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
