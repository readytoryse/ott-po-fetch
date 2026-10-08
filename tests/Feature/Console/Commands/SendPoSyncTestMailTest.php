<?php

namespace Tests\Feature\Console\Commands;

use App\Mail\PoSyncIssuesDetected;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SendPoSyncTestMailTest extends TestCase
{
    public function test_sends_a_test_email_to_every_configured_recipient(): void
    {
        Mail::fake();
        config(['services.po_sync.mail_recipients' => ['dev@example.test', 'ops@example.test']]);

        $this->artisan('orderwise:send-test-mail')
            ->expectsOutput('Test email sent to dev@example.test, ops@example.test using the "array" mailer.')
            ->assertSuccessful();

        Mail::assertSent(PoSyncIssuesDetected::class, fn (PoSyncIssuesDetected $mail): bool => $mail->isTest
            && $mail->hasTo('dev@example.test')
            && $mail->hasTo('ops@example.test'));
    }

    public function test_fails_without_sending_when_no_recipient_is_configured(): void
    {
        Mail::fake();
        config(['services.po_sync.mail_recipients' => []]);

        $this->artisan('orderwise:send-test-mail')
            ->expectsOutput('PO_SYNC_MAIL_RECIPIENTS is empty.')
            ->assertFailed();

        Mail::assertNothingSent();
    }
}
