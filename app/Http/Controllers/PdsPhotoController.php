<?php

namespace App\Http\Controllers;

use App\Enums\AuditEvent;
use App\Models\Employee;
use App\Models\Pds\PdsSubmission;
use App\Support\HR\Pds\PdsPhotos;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * HR-2b: the PDS photo (passport size). It's personal data on the private
 * disk, so it's only ever served through these authorized routes — the
 * employee's own, HR's with `pds.view` (uploads with `pds.update`), and a
 * submission's frozen copy to whoever may view that submission.
 */
class PdsPhotoController extends Controller
{
    public function showOwn(Request $request): StreamedResponse
    {
        return $this->serve($this->ownEmployee($request)->pdsProfile?->photo_path);
    }

    public function storeOwn(Request $request, PdsPhotos $photos): RedirectResponse
    {
        return $this->store($request, $this->ownEmployee($request), $photos);
    }

    public function destroyOwn(Request $request, PdsPhotos $photos): RedirectResponse
    {
        return $this->destroy($request, $this->ownEmployee($request), $photos);
    }

    public function showFor(Employee $employee): StreamedResponse
    {
        $this->authorize('viewPds', $employee);

        return $this->serve($employee->pdsProfile?->photo_path);
    }

    public function storeFor(Request $request, Employee $employee, PdsPhotos $photos): RedirectResponse
    {
        return $this->store($request, $employee, $photos);
    }

    public function destroyFor(Request $request, Employee $employee, PdsPhotos $photos): RedirectResponse
    {
        return $this->destroy($request, $employee, $photos);
    }

    public function showSubmission(PdsSubmission $submission): StreamedResponse
    {
        $this->authorize('view', $submission);

        return $this->serve($submission->photo_path);
    }

    private function store(Request $request, Employee $employee, PdsPhotos $photos): RedirectResponse
    {
        $this->authorize('updatePds', $employee);

        $request->validate([
            'photo' => ['required', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120', 'dimensions:min_width=200,min_height=250,max_width=8000,max_height=8000'],
        ]);

        $profile = $employee->pdsProfile()->firstOrNew();
        $profile->photo_path = $photos->store($request->file('photo'), $profile->photo_path);
        $profile->employee()->associate($employee)->save();

        $this->audit()->record(AuditEvent::PdsUpdated, $this->description('Updated the photo', $request, $employee), $employee, [
            'section' => 'photo',
            'changed' => ['photo'],
        ]);

        return back()->with('status', 'The PDS photo was updated.');
    }

    private function destroy(Request $request, Employee $employee, PdsPhotos $photos): RedirectResponse
    {
        $this->authorize('updatePds', $employee);

        $profile = $employee->pdsProfile;

        if ($profile?->photo_path) {
            $photos->delete($profile->photo_path);
            $profile->forceFill(['photo_path' => null])->save();

            $this->audit()->record(AuditEvent::PdsUpdated, $this->description('Removed the photo', $request, $employee), $employee, [
                'section' => 'photo',
                'changed' => ['photo'],
            ]);
        }

        return back()->with('status', 'The PDS photo was removed.');
    }

    private function serve(?string $path): StreamedResponse
    {
        abort_unless(PdsPhotos::exists($path), 404);

        return Storage::disk(PdsPhotos::DISK)->response($path, null, [
            // Personal data: never kept in shared or browser caches.
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    private function ownEmployee(Request $request): Employee
    {
        return $request->user()->employee ?? abort(404);
    }

    private function description(string $action, Request $request, Employee $employee): string
    {
        return $employee->user_id === $request->user()->id
            ? "{$action} in their own PDS"
            : "{$action} in the PDS of {$employee->user->name}";
    }
}
