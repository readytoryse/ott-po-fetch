<x-mail::message>
@if ($isTest)
# PO sync test email

This is a test email from the OrderWise to Shopify PO sync. Real error emails will look like the example below.
@else
# PO sync errors

Run #{{ $runId }} had {{ $totalCount }} error(s) while updating data.
@endif

**Detected at:** {{ $detectedAt }}

<x-mail::table>
| Stage | SKU | Error |
| :--- | :--- | :--- |
@foreach ($listedIssues as $issue)
| {{ $issue['stage'] }} | {{ $issue['sku'] }} | {{ $issue['message'] }} |
@endforeach
</x-mail::table>

@if ($totalCount > count($listedIssues))
Showing the first {{ count($listedIssues) }} of {{ $totalCount }} errors.
@endif

Full details are stored in the `po_sync_issues` table @if ($runId !== null)(run_id {{ $runId }}) @endif and in the PO sync log.
</x-mail::message>
