<?php

namespace Tests\Feature\Mail;

use App\Mail\PoSyncIssuesDetected;
use Tests\TestCase;

class PoSyncIssuesDetectedTest extends TestCase
{
    public function test_lists_each_error_with_its_stage_sku_and_message(): void
    {
        $mail = new PoSyncIssuesDetected(12, [
            (object) ['stage' => 'orderwise_export', 'sku' => null, 'message' => "HTTP 400:\n{\"Message\":\"Must provide parameter @since\"}"],
            (object) ['stage' => 'shopify_metafield_update', 'sku' => 'P1599', 'message' => 'Value is invalid JSON.'],
        ]);

        $mail->assertHasSubject('[OTT PO Sync] 2 error(s) in run #12');
        $mail->assertSeeInOrderInText([
            'Run #12 had 2 error(s) while updating data.',
            'orderwise_export',
            'Must provide parameter @since',
            'shopify_metafield_update',
            'P1599',
            'Value is invalid JSON.',
        ]);
    }

    public function test_marks_a_test_email_clearly_in_the_subject_and_body(): void
    {
        $mail = new PoSyncIssuesDetected(null, [
            (object) ['stage' => 'shopify_metafield_update', 'sku' => 'TEST-SKU', 'message' => 'Example error message.'],
        ], isTest: true);

        $mail->assertHasSubject('[OTT PO Sync] Test email');
        $mail->assertSeeInText('This is a test email');
        $mail->assertDontSeeInText('Run #');
    }

    public function test_lists_at_most_fifty_errors_and_states_the_total(): void
    {
        $issues = [];

        for ($index = 1; $index <= 51; $index++) {
            $issues[] = (object) ['stage' => 'shopify_metafield_update', 'sku' => 'SKU-'.$index, 'message' => 'Failed.'];
        }

        $mail = new PoSyncIssuesDetected(12, $issues);

        $mail->assertSeeInText('SKU-50');
        $mail->assertDontSeeInText('SKU-51');
        $mail->assertSeeInText('Showing the first 50 of 51 errors.');
    }
}
