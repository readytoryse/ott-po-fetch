<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduleTest extends TestCase
{
    public function test_runs_the_po_sync_every_three_hours_without_overlapping(): void
    {
        $events = array_values(array_filter(
            app(Schedule::class)->events(),
            static fn (Event $event): bool => str_contains((string) $event->command, 'orderwise:sync-po'),
        ));

        $this->assertCount(1, $events);
        $this->assertSame('0 */3 * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
    }
}
