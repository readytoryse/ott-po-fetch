<?php

namespace App\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use UnexpectedValueException;

class PurchaseOrderSyncService
{
    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array{rows_fetched: int, new_count: int, updated_count: int, removed_count: int, unchanged_count: int, affected_skus: list<string>}
     */
    public function apply(int $runId, array $rows): array
    {
        $incomingLines = $this->normalizeRows($rows);

        return DB::transaction(function () use ($runId, $rows, $incomingLines): array {
            $run = DB::table('po_sync_runs')->where('id', $runId)->lockForUpdate()->first();

            if ($run === null || $run->status !== 'running') {
                throw new UnexpectedValueException('PO sync run is missing or is not running.');
            }

            $now = now();
            $counts = [
                'rows_fetched' => count($rows),
                'new_count' => 0,
                'updated_count' => 0,
                'removed_count' => 0,
                'unchanged_count' => 0,
            ];
            $affectedSkus = [];

            $existingLines = collect();

            foreach (array_chunk(array_keys($incomingLines), 500) as $polIds) {
                $existingLines = $existingLines->concat(
                    DB::table('po_lines')->whereIn('pol_id', $polIds)->get(),
                );
            }

            $existingByPolId = $existingLines->keyBy('pol_id');

            foreach ($incomingLines as $polId => $incomingLine) {
                $existingLine = $existingByPolId->get($polId);

                if ($existingLine === null) {
                    DB::table('po_lines')->insert([
                        'pol_id' => $polId,
                        ...$incomingLine['fields'],
                        'row_hash' => $incomingLine['row_hash'],
                        'first_seen_at' => $now,
                        'last_seen_at' => $now,
                        'is_active' => true,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ]);

                    $counts['new_count']++;
                    $this->addAffectedSku($affectedSkus, $incomingLine['fields']['sku']);
                    $this->recordChange($runId, $polId, 'new', null, $this->changeValues($polId, $incomingLine['fields'], true), $now);

                    continue;
                }

                $wasActive = (bool) $existingLine->is_active;

                if ($existingLine->row_hash === $incomingLine['row_hash'] && $wasActive) {
                    DB::table('po_lines')->where('id', $existingLine->id)->update([
                        'last_seen_at' => $now,
                        'updated_at' => $now,
                    ]);
                    $counts['unchanged_count']++;

                    continue;
                }

                DB::table('po_lines')->where('id', $existingLine->id)->update([
                    ...$incomingLine['fields'],
                    'row_hash' => $incomingLine['row_hash'],
                    'last_seen_at' => $now,
                    'is_active' => true,
                    'updated_at' => $now,
                ]);

                $counts['updated_count']++;
                $this->addAffectedSku($affectedSkus, $existingLine->sku);
                $this->addAffectedSku($affectedSkus, $incomingLine['fields']['sku']);
                $this->recordChange(
                    $runId,
                    $polId,
                    'updated',
                    $this->changeValues($polId, $this->storedFields($existingLine), $wasActive),
                    $this->changeValues($polId, $incomingLine['fields'], true),
                    $now,
                );
            }

            DB::table('po_lines')
                ->where('is_active', true)
                ->orderBy('id')
                ->chunkById(500, function ($activeLines) use ($incomingLines, $runId, $now, &$counts, &$affectedSkus): void {
                    foreach ($activeLines as $activeLine) {
                        $polId = (int) $activeLine->pol_id;

                        if (array_key_exists($polId, $incomingLines)) {
                            continue;
                        }

                        DB::table('po_lines')->where('id', $activeLine->id)->update([
                            'is_active' => false,
                            'updated_at' => $now,
                        ]);

                        $counts['removed_count']++;
                        $this->addAffectedSku($affectedSkus, $activeLine->sku);
                        $this->recordChange(
                            $runId,
                            $polId,
                            'removed',
                            $this->changeValues($polId, $this->storedFields($activeLine), true),
                            $this->changeValues($polId, $this->storedFields($activeLine), false),
                            $now,
                        );
                    }
                });

            DB::table('po_sync_runs')->where('id', $runId)->update([
                ...$counts,
                'status' => 'success',
                'finished_at' => $now,
                'updated_at' => $now,
            ]);

            $affectedSkus = array_keys($affectedSkus);
            sort($affectedSkus, SORT_STRING);

            return [...$counts, 'affected_skus' => $affectedSkus];
        });
    }

    /**
     * @param  array<string, true>  $affectedSkus
     */
    private function addAffectedSku(array &$affectedSkus, ?string $sku): void
    {
        $sku = trim((string) $sku);

        if ($sku !== '') {
            $affectedSkus[$sku] = true;
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<int, array{fields: array<string, mixed>, row_hash: string}>
     */
    private function normalizeRows(array $rows): array
    {
        $incomingLines = [];
        $seenPolIds = [];
        $today = now()->toDateString();

        foreach ($rows as $row) {
            if (! is_array($row) || ! isset($row['po_line_id']) || filter_var($row['po_line_id'], FILTER_VALIDATE_INT) === false) {
                throw new UnexpectedValueException('OrderWise export contains a row without a valid po_line_id.');
            }

            $polId = (int) $row['po_line_id'];

            if ($polId <= 0 || isset($seenPolIds[$polId])) {
                throw new UnexpectedValueException('OrderWise export contains an invalid or duplicate po_line_id.');
            }

            $seenPolIds[$polId] = true;

            if (! array_key_exists('qty_outstanding', $row)) {
                throw new UnexpectedValueException('OrderWise export row is missing qty_outstanding.');
            }

            $fields = [
                'poh_order_number' => $this->nullableString($row['poh_order_number'] ?? null),
                'po_placed_datetime' => $this->nullableDateTime($row['po_placed_datetime'] ?? null),
                'supplier' => $this->nullableString($row['supplier'] ?? null),
                'sku' => $this->nullableString($row['sku'] ?? null),
                'description' => $this->nullableString($row['description'] ?? null),
                'qty_ordered' => $this->decimal($row['qty_ordered'] ?? 0),
                'qty_received' => $this->decimal($row['qty_received'] ?? 0),
                'qty_outstanding' => $this->decimal($row['qty_outstanding']),
                'supplier_promised_date' => $this->nullableDateTime($row['supplier_promised_date'] ?? null),
                'date_required' => $this->nullableDateTime($row['date_required'] ?? null),
                'poh_datetime' => $this->nullableDateTime($row['poh_datetime'] ?? null),
            ];

            if ((float) $fields['qty_outstanding'] <= 0) {
                continue;
            }

            if ($fields['supplier_promised_date'] !== null && substr($fields['supplier_promised_date'], 0, 10) < $today) {
                continue;
            }

            $hash = hash('sha256', json_encode(
                ['pol_id' => $polId, ...$fields],
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
            ));

            $incomingLines[$polId] = [
                'fields' => $fields,
                'row_hash' => $hash,
            ];
        }

        return $incomingLines;
    }

    private function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_scalar($value)) {
            throw new UnexpectedValueException('OrderWise export contains a non-scalar text value.');
        }

        return (string) $value;
    }

    private function nullableDateTime(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (! is_string($value)) {
            throw new UnexpectedValueException('OrderWise export contains an invalid date value.');
        }

        return CarbonImmutable::parse($value, config('app.timezone'))->format('Y-m-d H:i:s');
    }

    private function decimal(mixed $value): string
    {
        if (! is_numeric($value) || ! is_finite((float) $value)) {
            throw new UnexpectedValueException('OrderWise export contains an invalid quantity.');
        }

        return number_format((float) $value, 4, '.', '');
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private function changeValues(int $polId, array $fields, bool $isActive): array
    {
        return [
            'pol_id' => $polId,
            ...$fields,
            'is_active' => $isActive,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function storedFields(object $line): array
    {
        return [
            'poh_order_number' => $line->poh_order_number,
            'po_placed_datetime' => $line->po_placed_datetime,
            'supplier' => $line->supplier,
            'sku' => $line->sku,
            'description' => $line->description,
            'qty_ordered' => $line->qty_ordered,
            'qty_received' => $line->qty_received,
            'qty_outstanding' => $line->qty_outstanding,
            'supplier_promised_date' => $line->supplier_promised_date,
            'date_required' => $line->date_required,
            'poh_datetime' => $line->poh_datetime,
        ];
    }

    /**
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    private function recordChange(int $runId, int $polId, string $changeType, ?array $oldValues, ?array $newValues, mixed $now): void
    {
        DB::table('po_line_changes')->insert([
            'run_id' => $runId,
            'pol_id' => $polId,
            'change_type' => $changeType,
            'old_values' => $oldValues === null ? null : json_encode($oldValues, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'new_values' => $newValues === null ? null : json_encode($newValues, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
}
