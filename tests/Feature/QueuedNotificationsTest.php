<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ExecutiveAlert;
use App\Notifications\HrUpdate;
use App\Notifications\TestEmail;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\SendQueuedNotifications;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

/**
 * Bell and email notifications go through the queue, so a page never waits
 * on the mail server, and the scheduler runs the worker.
 */
class QueuedNotificationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_hr_and_executive_notifications_are_queued_not_sent_during_the_request(): void
    {
        Queue::fake();
        $user = User::factory()->create();

        $user->notify(new HrUpdate('Payslip approved', 'Your payslip is ready.', '/my/payslips'));
        $user->notify(new ExecutiveAlert('A figure is behind', 'Leave awaiting action.'));

        Queue::assertPushed(SendQueuedNotifications::class, 2);
        $this->assertSame(0, $user->notifications()->count());
    }

    public function test_a_queued_notification_still_reaches_the_bell_when_the_worker_runs(): void
    {
        $user = User::factory()->create();

        $user->notify(new HrUpdate('Payslip approved', 'Your payslip is ready.', '/my/payslips'));

        $this->assertSame('Payslip approved', $user->notifications()->first()->data['title']);
    }

    public function test_the_test_email_is_not_queued_so_the_sender_sees_the_result_at_once(): void
    {
        $this->assertNotInstanceOf(ShouldQueue::class, new TestEmail(new MailMessage));
    }

    public function test_the_scheduler_runs_the_queue_worker_every_minute(): void
    {
        $events = collect($this->app->make(Schedule::class)->events());
        $worker = $events->first(fn (Event $event) => str_contains($event->command, 'queue:work'));

        $this->assertNotNull($worker);
        $this->assertSame('* * * * *', $worker->expression);
        $this->assertStringContainsString('--stop-when-empty', $worker->command);
    }
}
