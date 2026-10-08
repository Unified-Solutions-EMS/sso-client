<?php

namespace Unified\SsoClient\Tests\Feature\MasterData;

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

class ScheduledResyncTest extends MasterDataTestCase
{
    /**
     * @return list<Event>
     */
    private function resyncEvents(): array
    {
        $this->app->forgetInstance(Schedule::class);

        return array_values(array_filter(
            $this->app->make(Schedule::class)->events(),
            fn (Event $event): bool => str_contains((string) $event->command, 'sso:resync-master-data'),
        ));
    }

    public function test_a_daily_resync_is_scheduled_for_each_enabled_entity(): void
    {
        $events = $this->resyncEvents();

        $this->assertCount(1, $events);
        $this->assertStringContainsString("sso:resync-master-data 'qualifications'", (string) $events[0]->command);
        $this->assertSame('15 3 * * *', $events[0]->expression);
        $this->assertTrue($events[0]->withoutOverlapping);
    }

    public function test_nothing_is_scheduled_when_the_entity_is_disabled(): void
    {
        config(['sso.master_data.qualifications' => false]);

        $this->assertSame([], $this->resyncEvents());
    }

    public function test_the_schedule_can_be_switched_off(): void
    {
        config(['sso.master_data.schedule_resync' => false]);

        $this->assertSame([], $this->resyncEvents());
    }
}
