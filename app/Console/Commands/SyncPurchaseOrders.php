<?php

namespace App\Console\Commands;

use App\Services\OrderWiseClient;
use App\Services\PurchaseOrderSyncService;
use App\Services\ShopifyMetafieldSyncService;
use App\Services\SyncIssueRecorder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('orderwise:sync-po')]
#[Description('Fetch outstanding OrderWise purchase order lines and log them')]
class SyncPurchaseOrders extends Command
{
    public function handle(
        OrderWiseClient $client,
        PurchaseOrderSyncService $syncService,
        ShopifyMetafieldSyncService $shopifySyncService,
        SyncIssueRecorder $issueRecorder,
    ): int {
        $logger = Log::channel('po_sync');
        $shopifyFailed = false;
        $startedAt = now();
        $runId = DB::table('po_sync_runs')->insertGetId([
            'started_at' => $startedAt,
            'status' => 'running',
            'created_at' => $startedAt,
            'updated_at' => $startedAt,
        ]);
        $rows = null;

        try {
            $sinceDate = $client->sinceDate();
            $rows = $client->fetchOutstandingPurchaseOrderLines();
            $counts = $syncService->apply($runId, $rows);
            $affectedSkus = $counts['affected_skus'];
            unset($counts['affected_skus']);
        } catch (Throwable $exception) {
            DB::table('po_sync_runs')->where('id', $runId)->where('status', 'running')->update([
                'status' => 'failed',
                'rows_fetched' => is_array($rows) ? count($rows) : 0,
                'finished_at' => now(),
                'error' => mb_substr($exception->getMessage(), 0, 60000),
                'updated_at' => now(),
            ]);

            $issueRecorder->record(
                runId: $runId,
                stage: SyncIssueRecorder::STAGE_ORDERWISE_EXPORT,
                severity: SyncIssueRecorder::SEVERITY_ERROR,
                message: $exception->getMessage(),
                details: ['exception' => $exception::class, 'rows_fetched' => is_array($rows) ? count($rows) : 0],
            );
            $this->error('OrderWise PO export failed. Check the PO sync log.');

            return self::FAILURE;
        }

        if (config('services.shopify.push_enabled')) {
            try {
                $shopifyResult = $shopifySyncService->sync($affectedSkus, $runId);
            } catch (Throwable $exception) {
                $issueRecorder->record(
                    runId: $runId,
                    stage: SyncIssueRecorder::STAGE_SHOPIFY_SYNC,
                    severity: SyncIssueRecorder::SEVERITY_ERROR,
                    message: $exception->getMessage(),
                    details: ['exception' => $exception::class],
                );
                $this->error('Shopify metafield sync failed. Check the PO sync log.');

                return self::FAILURE;
            }

            $logger->info('Shopify metafield sync completed.', [
                'run_id' => $runId,
                'synced_count' => $shopifyResult['synced_count'],
                'unchanged_count' => $shopifyResult['unchanged_count'],
                'unmatched_skus' => $shopifyResult['unmatched_skus'],
                'failed_skus' => $shopifyResult['failed_skus'],
            ]);
            $shopifyFailed = $shopifyResult['failed_skus'] !== [];

            if ($shopifyFailed) {
                $logger->error('Shopify metafield sync had failed SKUs.', [
                    'run_id' => $runId,
                    'failed_skus' => $shopifyResult['failed_skus'],
                ]);
            }

            $this->info(sprintf(
                'Shopify variants synced: %d; unchanged: %d; unmatched: %d; failed: %d.',
                $shopifyResult['synced_count'],
                $shopifyResult['unchanged_count'],
                count($shopifyResult['unmatched_skus']),
                count($shopifyResult['failed_skus']),
            ));
        }

        $today = now()->toDateString();
        $outstandingRows = array_filter(
            $rows,
            static fn (mixed $row): bool => is_array($row)
                && (float) ($row['qty_outstanding'] ?? 0) > 0
                && (! is_string($row['supplier_promised_date'] ?? null) || substr($row['supplier_promised_date'], 0, 10) >= $today),
        );

        $logger->info('OrderWise PO export completed.', [
            'run_id' => $runId,
            'since' => $sinceDate,
            ...$counts,
            'rows_outstanding' => count($outstandingRows),
            'rows_skipped' => count($rows) - count($outstandingRows),
        ]);

        foreach ($outstandingRows as $row) {
            $logger->info('Outstanding PO line.', $row);
        }

        $this->info(sprintf(
            'Fetched %d rows; logged %d outstanding PO lines.',
            count($rows),
            count($outstandingRows),
        ));

        return $shopifyFailed ? self::FAILURE : self::SUCCESS;
    }
}
