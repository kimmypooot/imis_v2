<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Http\Requests\Committee\StoreCommitteeRequest;
use App\Http\Requests\Committee\UpdateCommitteeRequest;
use App\Http\Requests\Committee\UpdateCommitteeStatusRequest;
use App\Models\Committee;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CommitteeController extends Controller
{
    public function index(Request $request): Response
    {
        $this->authorize('viewAny', Committee::class);

        $committees = Committee::query()
            ->withCount(['activeMembers as active_members_count'])
            ->when($request->string('search')->trim()->value(), function ($query, $search) {
                $query->where(function ($query) use ($search) {
                    $query->where('name', 'like', "%{$search}%")
                        ->orWhere('short_name', 'like', "%{$search}%");
                });
            })
            ->when($request->string('status')->value(), fn ($query, $status) => $query->where('status', $status))
            ->orderBy('name')
            ->get();

        return Inertia::render('Admin/Committees/Index', [
            'committees' => $committees,
            'filters' => $request->only(['search', 'status']),
            'statusOptions' => Status::options(),
        ]);
    }

    public function store(StoreCommitteeRequest $request): RedirectResponse
    {
        $committee = Committee::create($request->validated());

        $this->audit()->record(AuditEvent::CommitteeCreated, "Created committee {$committee->name}", $committee);

        return to_route('admin.committees.show', $committee)->with('status', "Committee {$committee->name} was added. Add its members next.");
    }

    public function show(Committee $committee): Response
    {
        $this->authorize('view', $committee);

        return Inertia::render('Admin/Committees/Show', [
            'committee' => $committee,
            'members' => $committee->members()
                ->with('user:id,name,email,status')
                ->orderByDesc('status')
                ->orderBy('effective_from', 'desc')
                ->get(),
            'userOptions' => User::query()
                ->where('status', 'active')
                ->whereNotIn('id', $committee->activeMembers()->pluck('user_id'))
                ->orderBy('name')
                ->get(['id', 'name', 'email']),
            'statusOptions' => Status::options(),
        ]);
    }

    public function update(UpdateCommitteeRequest $request, Committee $committee): RedirectResponse
    {
        $committee->update($request->validated());

        $this->audit()->recordChanges(AuditEvent::CommitteeUpdated, "Updated committee {$committee->name}", $committee);

        return back()->with('status', "Committee {$committee->name} was saved.");
    }

    public function updateStatus(UpdateCommitteeStatusRequest $request, Committee $committee): RedirectResponse
    {
        $committee->update($request->validated());

        $this->audit()->recordChanges(
            AuditEvent::CommitteeStatusChanged,
            "{$this->activationVerb($committee->status)} committee {$committee->name}",
            $committee,
        );

        return back()->with('status', "Committee {$committee->name} was ".strtolower($this->activationVerb($committee->status)).'.');
    }
}
