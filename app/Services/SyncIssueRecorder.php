<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class SyncIssueRecorder
{
    public const SEVERITY_ERROR = 'error';

    public const SEVERITY_WARNING = 'warning';

    public const STAGE_ORDERWISE_EXPORT = 'orderwise_export';

    public const STAGE_SHOPIFY_SYNC = 'shopify_sync';

    public const STAGE_SHOPIFY_VARIANT_LOOKUP = 'shopify_variant_lookup';

    public const STAGE_SHOPIFY_METAFIELD_UPDATE = 'shopify_metafield_update';

    /**
     * @param  array<string, mixed>  $details
     */
    public function record(
        ?int $runId,
        string $stage,
        string $severity,
        string $message,
        array $details = [],
        ?string $sku = null,
        ?string $variantGid = null,
    ): void {
        $now = now();

        DB::table('po_sync_issues')->insert([
            'run_id' => $runId,
            'stage' => $stage,
            'severity' => $severity,
            'sku' => $sku,
            'variant_gid' => $variantGid,
            'message' => mb_substr($message, 0, 60000),
            'details' => $details === [] ? null : json_encode($details, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * @return Collection<int, object{id: int, run_id: int|null, stage: string, severity: string, sku: string|null, variant_gid: string|null, message: string, details: string|null, created_at: string}>
     */
    public function errorsForRun(int $runId): Collection
    {
        return DB::table('po_sync_issues')
            ->where('run_id', $runId)
            ->where('severity', self::SEVERITY_ERROR)
            ->orderBy('id')
            ->get();
    }
}
