<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

class PoSyncIssuesDetected extends Mailable
{
    use Queueable, SerializesModels;

    private const MAX_LISTED_ISSUES = 50;

    /**
     * @param  list<object{stage: string, sku: string|null, message: string}>  $issues
     */
    public function __construct(
        public ?int $runId,
        public array $issues,
        public bool $isTest = false,
    ) {}

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->isTest
                ? '[OTT PO Sync] Test email'
                : sprintf('[OTT PO Sync] %d error(s) in run #%d', count($this->issues), $this->runId),
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            markdown: 'mail.po-sync.issues-detected',
            with: [
                'totalCount' => count($this->issues),
                'listedIssues' => array_map(
                    static fn (object $issue): array => [
                        'stage' => $issue->stage,
                        'sku' => $issue->sku ?? '-',
                        'message' => Str::limit(str_replace(['|', "\r", "\n"], ['/', ' ', ' '], $issue->message), 300),
                    ],
                    array_slice($this->issues, 0, self::MAX_LISTED_ISSUES),
                ),
                'detectedAt' => now()->toDayDateTimeString().' ('.config('app.timezone').')',
            ],
        );
    }
}
