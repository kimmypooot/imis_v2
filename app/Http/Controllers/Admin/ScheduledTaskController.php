<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AuditEvent;
use App\Http\Controllers\Controller;
use App\Models\ScheduledTaskPause;
use App\Support\System\ScheduledTasks;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Admin-3, Admin → Scheduled Tasks (`/admin/scheduled-tasks`): every task
 * the scheduler runs, with pause, resume and run now (ScheduledTasks).
 * Viewing needs `settings.view`; the controls `scheduled_tasks.manage`.
 * Each control is audited.
 */
class ScheduledTaskController extends Controller
{
    public function index(Request $request, ScheduledTasks $tasks): Response
    {
        $this->authorize('viewAny', ScheduledTaskPause::class);

        return Inertia::render('Admin/ScheduledTasks', [
            'tasks' => $tasks->all(),
            'canManage' => $request->user()->can('manage', ScheduledTaskPause::class),
            'lastRun' => $request->session()->get('task_run'),
        ]);
    }

    public function pause(Request $request, ScheduledTasks $tasks): RedirectResponse
    {
        $this->authorize('manage', ScheduledTaskPause::class);
        $data = $request->validate(['task' => ['required', 'string', 'max:191'], 'reason' => ['nullable', 'string', 'max:255']]);

        $tasks->pause($this->known($tasks, $data['task']), $request->user(), $data['reason'] ?? null);
        $this->audit()->record(AuditEvent::ScheduledTaskPaused, "Paused the scheduled task {$data['task']}", null, ['task' => $data['task'], 'reason' => $data['reason'] ?? null]);

        return back()->with('status', "Paused {$data['task']}. It won't run until you resume it.");
    }

    public function resume(Request $request, ScheduledTasks $tasks): RedirectResponse
    {
        $this->authorize('manage', ScheduledTaskPause::class);
        $data = $request->validate(['task' => ['required', 'string', 'max:191']]);

        $tasks->resume($this->known($tasks, $data['task']));
        $this->audit()->record(AuditEvent::ScheduledTaskResumed, "Resumed the scheduled task {$data['task']}", null, ['task' => $data['task']]);

        return back()->with('status', "Resumed {$data['task']}.");
    }

    public function run(Request $request, ScheduledTasks $tasks): RedirectResponse
    {
        $this->authorize('manage', ScheduledTaskPause::class);
        $data = $request->validate(['task' => ['required', 'string', 'max:191']]);

        $result = $tasks->run($this->known($tasks, $data['task']), $request->user());
        $this->audit()->record(AuditEvent::ScheduledTaskRun, "Ran the scheduled task {$data['task']} now", null, ['task' => $data['task'], 'succeeded' => $result['ok']]);

        return back()
            ->with('task_run', ['task' => $data['task'], ...$result])
            ->with($result['ok'] ? 'status' : 'error', $result['ok'] ? "{$data['task']} finished." : "{$data['task']} failed — see its output.");
    }

    /** Only a task the scheduler actually has; anything else is a 404. */
    private function known(ScheduledTasks $tasks, string $name): string
    {
        abort_unless(collect($tasks->all())->contains('name', $name), 404);

        return $name;
    }
}
