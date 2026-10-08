<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Throwable;

class ShopifyMetafieldSyncService
{
    private const BATCH_SIZE = 25;

    private const METAFIELD_NAMESPACE = 'custom';

    private const METAFIELD_KEY = 'incoming_purchase_orders';

    public function __construct(
        private ShopifyClient $shopifyClient,
        private SyncIssueRecorder $issueRecorder,
    ) {}

    /**
     * @param  list<string>  $changedSkus
     * @return array{synced_count: int, unchanged_count: int, unmatched_skus: list<string>, failed_skus: list<string>}
     */
    public function sync(array $changedSkus, ?int $runId = null): array
    {
        $skus = $this->candidateSkus($changedSkus);
        sort($skus, SORT_STRING);

        $result = [
            'synced_count' => 0,
            'unchanged_count' => 0,
            'unmatched_skus' => [],
            'failed_skus' => [],
        ];

        if ($skus === []) {
            return $result;
        }

        $payloads = $this->payloadsForSkus($skus);
        $existingSyncs = DB::table('variant_metafield_syncs')
            ->whereIn('sku', $skus)
            ->get()
            ->keyBy('sku');
        $pending = [];
        $lookupSkus = [];

        foreach ($skus as $sku) {
            $payload = $payloads[$sku] ?? [];
            $payloadHash = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION));
            $existingSync = $existingSyncs->get($sku);

            if ($existingSync?->status === 'synced' && hash_equals((string) $existingSync->payload_hash, $payloadHash)) {
                $result['unchanged_count']++;

                continue;
            }

            $pending[$sku] = [
                'payload' => $payload,
                'payload_hash' => $payloadHash,
                'variant_gid' => $existingSync?->variant_gid,
            ];

            if (! is_string($existingSync?->variant_gid) || $existingSync->variant_gid === '') {
                $lookupSkus[] = $sku;
            }
        }

        foreach (array_chunk($lookupSkus, self::BATCH_SIZE) as $lookupBatch) {
            try {
                $matches = $this->shopifyClient->findVariantsBySku($lookupBatch);
            } catch (Throwable $exception) {
                foreach ($lookupBatch as $sku) {
                    $this->storeFailure($runId, SyncIssueRecorder::STAGE_SHOPIFY_VARIANT_LOOKUP, $sku, $pending[$sku], $exception);
                    $result['failed_skus'][] = $sku;
                    unset($pending[$sku]);
                }

                continue;
            }

            foreach ($lookupBatch as $sku) {
                $variantGids = $matches[$sku] ?? [];

                if (count($variantGids) !== 1) {
                    $message = $variantGids === []
                        ? 'No Shopify variant matches this SKU.'
                        : 'More than one Shopify variant matches this SKU.';
                    $status = $variantGids === [] ? 'unmatched' : 'ambiguous';
                    $this->storeState($sku, [
                        'variant_gid' => null,
                        'last_payload' => json_encode($pending[$sku]['payload'], JSON_THROW_ON_ERROR),
                        'payload_hash' => $pending[$sku]['payload_hash'],
                        'status' => $status,
                        'error' => $message,
                        'synced_at' => null,
                    ]);

                    if ($existingSyncs->get($sku)?->status !== $status) {
                        $this->issueRecorder->record(
                            runId: $runId,
                            stage: SyncIssueRecorder::STAGE_SHOPIFY_VARIANT_LOOKUP,
                            severity: SyncIssueRecorder::SEVERITY_WARNING,
                            message: $message,
                            sku: $sku,
                        );
                    }

                    $result['unmatched_skus'][] = $sku;
                    unset($pending[$sku]);

                    continue;
                }

                $pending[$sku]['variant_gid'] = $variantGids[0];
            }
        }

        $metafieldBatches = [];

        foreach ($pending as $sku => $entry) {
            $metafieldBatches[] = [
                'sku' => $sku,
                'variant_gid' => $entry['variant_gid'],
                'payload' => $entry['payload'],
                'payload_hash' => $entry['payload_hash'],
                'metafield' => [
                    'ownerId' => $entry['variant_gid'],
                    'namespace' => self::METAFIELD_NAMESPACE,
                    'key' => self::METAFIELD_KEY,
                    'type' => 'json',
                    'value' => json_encode($entry['payload'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                ],
            ];
        }

        foreach (array_chunk($metafieldBatches, self::BATCH_SIZE) as $batch) {
            try {
                $previousValues = $this->shopifyClient->findMetafieldValues(
                    array_column($batch, 'variant_gid'),
                    self::METAFIELD_NAMESPACE,
                    self::METAFIELD_KEY,
                );
                $this->shopifyClient->setMetafields(array_column($batch, 'metafield'));
            } catch (Throwable $exception) {
                foreach ($batch as $entry) {
                    $this->storeFailure($runId, SyncIssueRecorder::STAGE_SHOPIFY_METAFIELD_UPDATE, $entry['sku'], $entry, $exception, $entry['variant_gid']);
                    $result['failed_skus'][] = $entry['sku'];
                }

                continue;
            }

            foreach ($batch as $entry) {
                $this->storeState($entry['sku'], [
                    'variant_gid' => $entry['variant_gid'],
                    'last_payload' => json_encode($entry['payload'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
                    'payload_hash' => $entry['payload_hash'],
                    'status' => 'synced',
                    'error' => null,
                    'synced_at' => now(),
                ]);
                $this->recordUpdate($runId, $entry['sku'], $entry['variant_gid'], $previousValues[$entry['variant_gid']] ?? null, $entry['metafield']['value']);
                $result['synced_count']++;
            }
        }

        return $result;
    }

    /**
     * @param  list<string>  $skus
     * @return array<string, list<array{qty: int|float, date: string, reference: string}>>
     */
    private function payloadsForSkus(array $skus): array
    {
        $payloads = array_fill_keys($skus, []);
        $groups = [];

        foreach (array_chunk($skus, 500) as $skuBatch) {
            $lines = DB::table('po_lines')
                ->where('is_active', true)
                ->whereIn('sku', $skuBatch)
                ->whereNotNull('supplier_promised_date')
                ->get(['sku', 'poh_order_number', 'qty_outstanding', 'supplier_promised_date']);

            foreach ($lines as $line) {
                $date = substr((string) $line->supplier_promised_date, 0, 10);
                $reference = (string) $line->poh_order_number;
                $groupKey = json_encode([$line->sku, $date, $reference], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
                $groups[$groupKey] ??= [
                    'sku' => (string) $line->sku,
                    'date' => $date,
                    'reference' => $reference,
                    'qty' => 0.0,
                ];
                $groups[$groupKey]['qty'] += (float) $line->qty_outstanding;
            }
        }

        foreach ($groups as $group) {
            $quantity = round($group['qty'], 4);
            $payloads[$group['sku']][] = [
                'qty' => floor($quantity) === $quantity ? (int) $quantity : $quantity,
                'date' => $group['date'],
                'reference' => $group['reference'],
            ];
        }

        foreach ($payloads as &$payload) {
            usort($payload, static fn (array $first, array $second): int => strcmp($first['date'], $second['date'])
                ?: strcmp($first['reference'], $second['reference']));
        }
        unset($payload);

        return $payloads;
    }

    /**
     * @param  list<string>  $changedSkus
     * @return list<string>
     */
    private function candidateSkus(array $changedSkus): array
    {
        $skus = array_map(static fn (mixed $sku): string => is_scalar($sku) ? trim((string) $sku) : '', $changedSkus);
        $activeSkus = DB::table('po_lines')
            ->where('is_active', true)
            ->whereNotNull('sku')
            ->where('sku', '<>', '')
            ->distinct()
            ->pluck('sku')
            ->all();
        $trackedSkus = DB::table('variant_metafield_syncs')->pluck('sku')->all();
        $skus = array_values(array_unique(array_filter(
            [...$skus, ...$activeSkus, ...$trackedSkus],
            static fn (mixed $sku): bool => is_string($sku) && trim($sku) !== '',
        )));
        sort($skus, SORT_STRING);

        return $skus;
    }

    /**
     * @param  array{payload: list<array{qty: int|float, date: string, reference: string}>, payload_hash: string}  $entry
     */
    private function storeFailure(?int $runId, string $stage, string $sku, array $entry, Throwable $exception, ?string $variantGid = null): void
    {
        $this->storeState($sku, [
            'variant_gid' => $variantGid,
            'last_payload' => json_encode($entry['payload'], JSON_THROW_ON_ERROR | JSON_PRESERVE_ZERO_FRACTION),
            'payload_hash' => $entry['payload_hash'],
            'status' => 'error',
            'error' => mb_substr($exception->getMessage(), 0, 2000),
            'synced_at' => null,
        ]);
        $this->issueRecorder->record(
            runId: $runId,
            stage: $stage,
            severity: SyncIssueRecorder::SEVERITY_ERROR,
            message: $exception->getMessage(),
            details: ['exception' => $exception::class, 'payload' => $entry['payload']],
            sku: $sku,
            variantGid: $variantGid,
        );
    }

    private function recordUpdate(?int $runId, string $sku, string $variantGid, ?string $oldValue, string $newValue): void
    {
        $now = now();

        DB::table('variant_metafield_updates')->insert([
            'run_id' => $runId,
            'sku' => $sku,
            'variant_gid' => $variantGid,
            'old_value' => $oldValue,
            'new_value' => $newValue,
            'metafield_updated_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    private function storeState(string $sku, array $values): void
    {
        $now = now();
        $sync = DB::table('variant_metafield_syncs')->where('sku', $sku);

        if ($sync->exists()) {
            $sync->update([...$values, 'updated_at' => $now]);

            return;
        }

        DB::table('variant_metafield_syncs')->insert([
            'sku' => $sku,
            ...$values,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
