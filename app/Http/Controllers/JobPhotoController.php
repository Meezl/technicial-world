<?php

namespace App\Http\Controllers;

use App\Models\JobPhoto;
use App\Models\ServiceRequest;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Photo evidence attached to a job itself, rather than to a progress report.
 *
 * This is the path a client uses to show a snag or a leak that came back, and
 * the one a technician uses for evidence that isn't part of a formal progress
 * submission. Progress-report photos still go through ProgressService.
 */
class JobPhotoController extends Controller
{
    /** Matches the technician progress form, and PHP's max_file_uploads. */
    private const MAX_PER_REQUEST = 6;

    public function store(Request $request, ServiceRequest $serviceRequest)
    {
        $user = auth()->user();

        if (!$this->canUpload($user, $serviceRequest)) {
            return back()->with('error', 'You are not allowed to add photos to this job.');
        }

        $request->validate([
            'photos'   => 'required|array|max:' . self::MAX_PER_REQUEST,
            // No `image` rule: it calls getimagesize(), which cannot read
            // HEIC/HEIF and would reject iPhone photos outright (#27).
            'photos.*' => 'required|file|mimes:jpg,jpeg,png,webp,heic,heif|max:10240',
            'caption'  => 'nullable|string|max:200',
        ]);

        $clientVisible = $this->defaultVisibilityFor($user);

        foreach ($request->file('photos', []) as $file) {
            $path = $file->store('job-photos/' . $serviceRequest->id, 'public');

            $serviceRequest->photos()->create([
                'service_request_id' => $serviceRequest->id,
                'file_path'          => $path,
                'caption'            => $request->input('caption'),
                'added_by'           => $user->id,
                'uploader_role'      => $user->role,
                'original_filename'  => $file->getClientOriginalName(),
                'mime_type'          => $file->getMimeType(),
                'size_bytes'         => $file->getSize(),
                'client_visible'     => $clientVisible,
            ]);
        }

        $count = count($request->file('photos', []));

        return back()->with('success', $count . ' photo' . ($count === 1 ? '' : 's') . ' added to this job.');
    }

    public function destroy(JobPhoto $jobPhoto)
    {
        $user = auth()->user();

        // Uploaders can remove their own; ops can remove anything. A client
        // cannot delete a technician's evidence, or vice versa.
        $isOwner = $jobPhoto->added_by === $user->id;
        $isOps   = $user->hasRole(User::ROLE_ADMIN, User::ROLE_PROJECT_MANAGER);

        if (!$isOwner && !$isOps) {
            return back()->with('error', 'You are not allowed to remove this photo.');
        }

        // Photos on a progress report are evidence of what was submitted —
        // a PM excludes them from approval rather than destroying them.
        if ($jobPhoto->photoable_type !== ServiceRequest::class) {
            return back()->with('error', 'Progress report photos cannot be deleted. Remove them from approval instead.');
        }

        Storage::disk('public')->delete($jobPhoto->file_path);
        $jobPhoto->delete();

        return back()->with('success', 'Photo removed.');
    }

    /**
     * Who may attach photos to this job: the client who owns it, a technician
     * assigned to it (as lead, as the main technician, or on a sub-task), and
     * ops.
     */
    private function canUpload(User $user, ServiceRequest $serviceRequest): bool
    {
        if ($user->hasRole(User::ROLE_ADMIN, User::ROLE_PROJECT_MANAGER, User::ROLE_FOREMAN)) {
            return true;
        }

        if ($user->isClient()) {
            return $serviceRequest->user_id === $user->id;
        }

        if ($user->isTechnician()) {
            $technicianId = $user->technician?->id;
            if (!$technicianId) return false;

            return $serviceRequest->technician_id === $technicianId
                || $serviceRequest->lead_technician_id === $technicianId
                || $serviceRequest->subTasks()->where('technician_id', $technicianId)->exists();
        }

        return false;
    }

    /**
     * Client and technician photos are evidence of the job and are meant to
     * be seen by both sides. Ops photos are internal until somebody
     * deliberately shares them — the same default as job documents.
     */
    private function defaultVisibilityFor(User $user): bool
    {
        return $user->isClient() || $user->isTechnician();
    }
}
