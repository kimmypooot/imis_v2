<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Http\Requests\Committee\StoreCommitteeMemberRequest;
use App\Http\Requests\Committee\UpdateCommitteeMemberRequest;
use App\Http\Requests\Committee\UpdateCommitteeMemberStatusRequest;
use App\Models\Committee;
use App\Models\CommitteeMember;
use Illuminate\Http\RedirectResponse;

class CommitteeMemberController extends Controller
{
    public function store(StoreCommitteeMemberRequest $request, Committee $committee): RedirectResponse
    {
        $member = $committee->members()->create($request->validated());

        $this->recordMembership(
            AuditEvent::CommitteeMemberAdded,
            "Added {$member->user->name} to committee {$committee->name} as {$member->position}",
            $committee,
            $member,
        );

        return back()->with('status', 'Member added.');
    }

    public function update(
        UpdateCommitteeMemberRequest $request,
        Committee $committee,
        CommitteeMember $member,
    ): RedirectResponse {
        $this->abortUnlessBelongsToCommittee($committee, $member);

        $member->update($request->validated());

        $this->recordMembership(
            AuditEvent::CommitteeMemberUpdated,
            "Updated {$member->user->name}'s membership in committee {$committee->name}",
            $committee,
            $member,
            onlyIfChanged: true,
        );

        return back()->with('status', 'Membership saved.');
    }

    public function updateStatus(
        UpdateCommitteeMemberStatusRequest $request,
        Committee $committee,
        CommitteeMember $member,
    ): RedirectResponse {
        $this->abortUnlessBelongsToCommittee($committee, $member);

        $member->update($request->validated());

        $verb = $member->status->value === 'active' ? 'Reactivated' : 'Ended';
        $this->recordMembership(
            AuditEvent::CommitteeMemberStatusChanged,
            "{$verb} {$member->user->name}'s membership in committee {$committee->name}",
            $committee,
            $member,
            onlyIfChanged: true,
        );

        return back()->with('status', 'Membership updated.');
    }

    /**
     * Membership events target the committee (so a committee's history
     * reads as one timeline), with the membership itself in metadata.
     */
    private function recordMembership(
        AuditEvent $event,
        string $description,
        Committee $committee,
        CommitteeMember $member,
        bool $onlyIfChanged = false,
    ): void {
        $changes = $this->audit()->changes($member);

        if ($onlyIfChanged && $changes === []) {
            return;
        }

        $this->audit()->record($event, $description, $committee, array_filter([
            'member_id' => $member->id,
            'member_user_id' => $member->user_id,
            'member_name' => $member->user->name,
            'position' => $member->position,
            'changes' => $onlyIfChanged ? $changes : null,
        ]));
    }

    private function abortUnlessBelongsToCommittee(Committee $committee, CommitteeMember $member): void
    {
        abort_unless($member->committee_id === $committee->id, 404);
    }
}
