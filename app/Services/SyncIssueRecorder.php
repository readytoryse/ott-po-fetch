<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncIssueRecorder
{
    public const SEVERITY_ERROR = 'error';

    public const SEVERITY_WARNING = 'warning';

    public const STAGE_ORDERWISE_EXPORT = 'orderwise_export';

    public const STAGE_SHOPIFY_SYNC = 'shopify_sync';

    public const STAGE_SHOPIFY_VARIANT_LOOKUP = 'shopify_variant_lookup';

    public const STAGE_SHOPIFY_METAFIELD_UPDATE = 'shopify_metafield_update';

    /**
     * Write the issue to the PO sync log, then store it in po_sync_issues.
     *
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
        Log::channel('po_sync')->log($severity, 'PO sync issue.', [
            'run_id' => $runId,
            'stage' => $stage,
            'sku' => $sku,
            'variant_gid' => $variantGid,
            'message' => $message,
            'details' => $details,
        ]);

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
}
