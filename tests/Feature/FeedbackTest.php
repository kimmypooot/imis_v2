<?php

namespace Tests\Feature;

use App\Enums\AuditEvent;
use App\Enums\FeedbackStatus;
use App\Models\AuditLog;
use App\Models\Feedback;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class FeedbackTest extends TestCase
{
    use RefreshDatabase;

    private const PAYLOAD = [
        'type' => 'suggestion',
        'subject' => 'Dark mode',
        'message' => 'It would be easier on the eyes in the evening.',
        'page_path' => '/dashboard?tab=1#top',
    ];

    public function test_guests_cannot_send_feedback(): void
    {
        $this->post('/feedback', self::PAYLOAD)->assertRedirect('/login');
        $this->assertDatabaseCount('feedback', 0);
    }

    public function test_anyone_signed_in_can_send_feedback_and_only_the_page_path_is_kept(): void
    {
        $sender = User::factory()->create();

        $this->actingAs($sender)->post('/feedback', self::PAYLOAD)->assertSessionHasNoErrors();

        $feedback = Feedback::query()->sole();
        $this->assertSame($sender->id, $feedback->user_id);
        $this->assertSame(FeedbackStatus::New, $feedback->status);
        $this->assertSame('/dashboard', $feedback->page_path);
    }

    public function test_an_outside_address_is_not_kept_as_the_page(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/feedback', [...self::PAYLOAD, 'page_path' => '//evil.example/x'])
            ->assertSessionHasNoErrors();

        $this->assertNull(Feedback::query()->sole()->page_path);
    }

    public function test_feedback_is_validated(): void
    {
        $this->actingAs(User::factory()->create())
            ->post('/feedback', ['type' => 'rant', 'subject' => '', 'message' => 'short'])
            ->assertSessionHasErrors(['type', 'subject', 'message']);
    }

    public function test_sending_is_rate_limited(): void
    {
        $sender = User::factory()->create();

        foreach (range(1, 5) as $ignored) {
            $this->actingAs($sender)->post('/feedback', self::PAYLOAD)->assertSessionHasNoErrors();
        }

        $this->actingAs($sender)->post('/feedback', self::PAYLOAD)->assertTooManyRequests();
    }

    public function test_those_who_read_feedback_are_notified_of_new_feedback(): void
    {
        $reader = $this->userWith('feedback.view');
        $bystander = User::factory()->create();

        $this->actingAs(User::factory()->create())->post('/feedback', self::PAYLOAD);

        $this->assertSame(1, $reader->notifications()->count());
        $this->assertSame(0, $bystander->notifications()->count());
    }

    public function test_my_feedback_shows_only_my_own_with_replies_but_not_private_notes(): void
    {
        $me = User::factory()->create();
        $mine = Feedback::factory()->for($me)->create();
        $mine->replies()->make(['body' => 'We will look into it.', 'is_internal' => false])->forceFill(['user_id' => null])->save();
        $mine->replies()->make(['body' => 'Sender sounds annoyed.', 'is_internal' => true])->forceFill(['user_id' => null])->save();
        Feedback::factory()->create();

        $this->actingAs($me)->get('/my/feedback')->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Portal/MyFeedback')
            ->has('feedback', 1)
            ->has('feedback.0.replies', 1)
            ->where('feedback.0.replies.0.body', 'We will look into it.'));
    }

    public function test_the_admin_list_requires_permission_and_filters(): void
    {
        Feedback::factory()->create(['subject' => 'Printing is slow', 'type' => 'feedback']);
        Feedback::factory()->create(['subject' => 'Add a calendar', 'type' => 'suggestion', 'status' => FeedbackStatus::Closed]);

        $this->actingAs(User::factory()->create())->get('/admin/feedback')->assertForbidden();

        $this->actingAs($this->userWith('feedback.view'))->get('/admin/feedback?type=suggestion')
            ->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Admin/Feedback/Index')
            ->has('feedback.data', 1)
            ->where('feedback.data.0.subject', 'Add a calendar'));

        $this->actingAs($this->userWith('feedback.view'))->get('/admin/feedback?search=Printing')
            ->assertInertia(fn (Assert $page) => $page->has('feedback.data', 1));

        $this->actingAs($this->userWith('feedback.view'))->get('/admin/feedback?status=closed')
            ->assertInertia(fn (Assert $page) => $page->has('feedback.data', 1));
    }

    public function test_the_sidebar_count_is_new_feedback_and_only_for_those_who_can_read_it(): void
    {
        Feedback::factory()->count(2)->create();
        Feedback::factory()->create(['status' => FeedbackStatus::InReview]);

        $this->actingAs($this->userWith('feedback.view'))->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('badges.feedback', 2));

        $this->actingAs(User::factory()->create())->get('/dashboard')
            ->assertInertia(fn (Assert $page) => $page->where('badges.feedback', 0));
    }

    public function test_replying_notifies_the_sender_and_moves_new_feedback_under_review(): void
    {
        $sender = User::factory()->create();
        $feedback = Feedback::factory()->for($sender)->create();
        $admin = $this->userWith('feedback.manage');

        $this->actingAs($admin)->post("/admin/feedback/{$feedback->id}/replies", ['body' => 'Thanks, noted.'])
            ->assertSessionHasNoErrors();

        $this->assertSame(FeedbackStatus::InReview, $feedback->fresh()->status);
        $this->assertSame($admin->id, $feedback->fresh()->handled_by);
        $this->assertSame(1, $sender->notifications()->count());
        $this->assertSame('feedback', DatabaseNotification::query()->sole()->data['kind']);
        $this->assertTrue(AuditLog::query()->where('event', AuditEvent::FeedbackReplied)->exists());
    }

    public function test_a_private_note_is_not_sent_to_the_sender(): void
    {
        $sender = User::factory()->create();
        $feedback = Feedback::factory()->for($sender)->create();

        $this->actingAs($this->userWith('feedback.manage'))
            ->post("/admin/feedback/{$feedback->id}/replies", ['body' => 'Check with ICT.', 'is_internal' => true]);

        $this->assertSame(0, $sender->notifications()->count());
        $this->assertSame(FeedbackStatus::New, $feedback->fresh()->status);
        $this->assertTrue($feedback->replies()->sole()->is_internal);
    }

    public function test_replying_and_changing_status_require_manage(): void
    {
        $feedback = Feedback::factory()->create();
        $viewer = $this->userWith('feedback.view');

        $this->actingAs($viewer)->post("/admin/feedback/{$feedback->id}/replies", ['body' => 'Hi'])->assertForbidden();
        $this->actingAs($viewer)->patch("/admin/feedback/{$feedback->id}/status", ['status' => 'closed'])->assertForbidden();
        $this->actingAs($viewer)->get("/admin/feedback/{$feedback->id}")->assertOk();
    }

    public function test_opening_feedback_clears_the_badge_but_keeps_the_status(): void
    {
        $feedback = Feedback::factory()->create();
        $viewer = $this->userWith('feedback.view');

        $this->actingAs($viewer)->get('/admin/feedback')
            ->assertInertia(fn (Assert $page) => $page->where('badges.feedback', 1));

        $this->actingAs($viewer)->get("/admin/feedback/{$feedback->id}")
            ->assertInertia(fn (Assert $page) => $page->where('badges.feedback', 0));

        $this->assertNotNull($feedback->fresh()->seen_at);
        $this->assertSame(FeedbackStatus::New, $feedback->fresh()->status);
    }

    public function test_marking_feedback_applied_notifies_the_sender(): void
    {
        $feedback = Feedback::factory()->create();

        $this->actingAs($this->userWith('feedback.manage'))
            ->patch("/admin/feedback/{$feedback->id}/status", ['status' => 'applied'])
            ->assertSessionHasNoErrors();

        $this->assertSame(FeedbackStatus::Applied, $feedback->fresh()->status);
        $this->assertSame(1, DatabaseNotification::query()->where('notifiable_id', $feedback->user_id)->count());
    }

    public function test_status_changes_are_audited(): void
    {
        $feedback = Feedback::factory()->create();

        $this->actingAs($this->userWith('feedback.manage'))
            ->patch("/admin/feedback/{$feedback->id}/status", ['status' => 'resolved'])
            ->assertSessionHasNoErrors();

        $this->assertSame(FeedbackStatus::Resolved, $feedback->fresh()->status);
        $this->assertTrue(AuditLog::query()->where('event', AuditEvent::FeedbackStatusChanged)->exists());

        $this->actingAs($this->userWith('feedback.manage'))
            ->patch("/admin/feedback/{$feedback->id}/status", ['status' => 'bogus'])
            ->assertSessionHasErrors('status');
    }

    private function userWith(string $permission): User
    {
        $user = User::factory()->create();
        $role = Role::factory()->create();
        $role->permissions()->attach(
            Permission::query()->firstOrCreate(['name' => $permission], ['display_name' => $permission]),
        );
        $user->roles()->attach($role);

        return $user;
    }
}
