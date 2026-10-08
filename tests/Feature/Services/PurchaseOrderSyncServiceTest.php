<?php

namespace Tests\Feature\Services;

use App\Services\PurchaseOrderSyncService;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PurchaseOrderSyncServiceTest extends TestCase
{
    use LazilyRefreshDatabase;

    public function test_tracks_new_updated_removed_and_unchanged_po_lines_by_line_id(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $service = app(PurchaseOrderSyncService::class);
        $firstRunId = $this->createRun();
        $firstCounts = $service->apply($firstRunId, [
            $this->poLine(19424, 'P1551', '6'),
            $this->poLine(19425, 'P1541', '4'),
            $this->poLine(19426, 'P1500', '0'),
        ]);

        $this->assertSame([
            'rows_fetched' => 3,
            'new_count' => 2,
            'updated_count' => 0,
            'removed_count' => 0,
            'unchanged_count' => 0,
            'affected_skus' => ['P1541', 'P1551'],
        ], $firstCounts);
        $this->assertDatabaseHas('po_sync_runs', ['id' => $firstRunId, 'status' => 'success', 'rows_fetched' => 3]);
        $this->assertDatabaseHas('po_lines', ['pol_id' => 19424, 'sku' => 'P1551', 'is_active' => true]);
        $this->assertDatabaseMissing('po_lines', ['pol_id' => 19426]);

        $secondRunId = $this->createRun();
        $secondCounts = $service->apply($secondRunId, [
            $this->poLine(19424, 'P1551', '8'),
            $this->poLine(19427, 'P1502', '3'),
        ]);

        $this->assertSame([
            'rows_fetched' => 2,
            'new_count' => 1,
            'updated_count' => 1,
            'removed_count' => 1,
            'unchanged_count' => 0,
            'affected_skus' => ['P1502', 'P1541', 'P1551'],
        ], $secondCounts);
        $this->assertDatabaseHas('po_lines', ['pol_id' => 19424, 'qty_outstanding' => '8.0000', 'is_active' => true]);
        $this->assertDatabaseHas('po_lines', ['pol_id' => 19425, 'is_active' => false]);
        $this->assertDatabaseHas('po_line_changes', ['run_id' => $secondRunId, 'pol_id' => 19425, 'change_type' => 'removed']);

        $thirdRunId = $this->createRun();
        $thirdCounts = $service->apply($thirdRunId, [
            $this->poLine(19424, 'P1551', '8'),
            $this->poLine(19427, 'P1502', '3'),
        ]);

        $this->assertSame([
            'rows_fetched' => 2,
            'new_count' => 0,
            'updated_count' => 0,
            'removed_count' => 0,
            'unchanged_count' => 2,
            'affected_skus' => [],
        ], $thirdCounts);
        $this->assertDatabaseHas('po_sync_runs', ['id' => $thirdRunId, 'status' => 'success', 'unchanged_count' => 2]);
        $this->assertDatabaseCount('po_line_changes', 5);
    }

    public function test_skips_lines_promised_before_today_and_removes_them_once_the_date_has_passed(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $service = app(PurchaseOrderSyncService::class);
        $overdueLine = [...$this->poLine(19425, 'P1541', '4'), 'supplier_promised_date' => '2026-10-04T00:00:00'];

        $firstCounts = $service->apply($this->createRun(), [$this->poLine(19424, 'P1551', '6'), $overdueLine]);

        $this->assertSame(1, $firstCounts['new_count']);
        $this->assertSame(['P1551'], $firstCounts['affected_skus']);
        $this->assertDatabaseHas('po_lines', ['pol_id' => 19424, 'is_active' => true]);
        $this->assertDatabaseMissing('po_lines', ['pol_id' => 19425]);

        $this->travelTo('2026-10-06 10:00:00');

        $secondCounts = $service->apply($this->createRun(), [$this->poLine(19424, 'P1551', '6'), $overdueLine]);

        $this->assertSame(1, $secondCounts['removed_count']);
        $this->assertSame(['P1551'], $secondCounts['affected_skus']);
        $this->assertDatabaseHas('po_lines', ['pol_id' => 19424, 'is_active' => false]);
    }

    public function test_marks_both_old_and_new_skus_affected_when_a_po_line_changes_sku(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $service = app(PurchaseOrderSyncService::class);
        $initialRunId = $this->createRun();
        $service->apply($initialRunId, [$this->poLine(19424, 'P1551', '6')]);
        $updateRunId = $this->createRun();
        $updatedLine = $this->poLine(19424, 'P1541', '6');

        $result = $service->apply($updateRunId, [$updatedLine]);

        $this->assertSame(['P1541', 'P1551'], $result['affected_skus']);
    }

    public function test_rejects_invalid_rows_before_marking_existing_lines_removed(): void
    {
        $this->travelTo('2026-10-05 10:00:00');
        $service = app(PurchaseOrderSyncService::class);
        $initialRunId = $this->createRun();
        $service->apply($initialRunId, [$this->poLine(19424, 'P1551', '6')]);
        $invalidRunId = $this->createRun();

        $this->expectException(\UnexpectedValueException::class);

        try {
            $service->apply($invalidRunId, [['sku' => 'P1541', 'qty_outstanding' => 2]]);
        } finally {
            $this->assertDatabaseHas('po_lines', ['pol_id' => 19424, 'is_active' => true]);
            $this->assertDatabaseCount('po_line_changes', 1);
        }
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

    /**
     * @return array<string, mixed>
     */
    private function poLine(int $polId, string $sku, string $qtyOutstanding): array
    {
        return [
            'po_line_id' => $polId,
            'poh_order_number' => 'PO-'.$polId,
            'po_placed_datetime' => '2026-09-28T18:36:51.577',
            'supplier' => 'Example Supplier',
            'sku' => $sku,
            'description' => 'Test PO line',
            'qty_ordered' => $qtyOutstanding,
            'qty_received' => 0,
            'qty_outstanding' => $qtyOutstanding,
            'supplier_promised_date' => '2026-10-05T00:00:00',
            'date_required' => '2026-10-05T00:00:00',
            'poh_datetime' => '2026-09-28T18:36:51.577',
        ];
    }
}
