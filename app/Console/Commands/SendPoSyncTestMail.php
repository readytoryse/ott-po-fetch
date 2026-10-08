<?php

namespace App\Console\Commands;

use App\Mail\PoSyncIssuesDetected;
use App\Services\SyncIssueRecorder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

#[Signature('orderwise:send-test-mail')]
#[Description('Send a test PO sync error email to the configured recipients')]
class SendPoSyncTestMail extends Command
{
    public function handle(): int
    {
        $recipients = config('services.po_sync.mail_recipients');

        if ($recipients === []) {
            $this->error('PO_SYNC_MAIL_RECIPIENTS is empty.');

            return self::FAILURE;
        }

        $sampleIssue = (object) [
            'stage' => SyncIssueRecorder::STAGE_SHOPIFY_METAFIELD_UPDATE,
            'sku' => 'TEST-SKU',
            'message' => 'Example error message. No real error occurred.',
        ];

        try {
            Mail::to($recipients)->send(new PoSyncIssuesDetected(null, [$sampleIssue], isTest: true));
        } catch (Throwable $exception) {
            $this->error('Test email could not be sent: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'Test email sent to %s using the "%s" mailer.',
            implode(', ', $recipients),
            config('mail.default'),
        ));

        return self::SUCCESS;
    }
}
