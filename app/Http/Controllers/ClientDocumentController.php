<?php

namespace App\Http\Controllers;

use App\Models\ServiceRequest;
use App\Models\ServiceRequestDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Documents a client attaches to their own job — a drawing, a sketch, a spec
 * in PDF. In construction these are often how the work is actually described,
 * so the client needs the same "share it on the job" affordance they already
 * have for photos, not just a way to send images.
 *
 * These live in the same ServiceRequestDocument store as the files ops hold
 * against a job, so they reach the technician and the office through the
 * channels that already exist. A client upload is client-visible by nature —
 * the client is deliberately sharing it — which is the opposite default to an
 * ops upload, where anything client-facing is an explicit decision.
 */
class ClientDocumentController extends Controller
{
    /** Matches the client photo cap and PHP's max_file_uploads. */
    private const MAX_PER_REQUEST = 6;

    /** Drawings and specs run large; 20 MB matches the ops document limit. */
    private const MAX_KB = 20480;

    public function store(Request $request, ServiceRequest $serviceRequest)
    {
        if ($serviceRequest->user_id !== $request->user()->id) {
            return back()->with('error', 'You can only attach documents to your own request.');
        }

        $request->validate([
            'documents'   => 'required|array|max:' . self::MAX_PER_REQUEST,
            // PDF only. Photos have their own path; a client reaching for the
            // document uploader wants to share a drawing or a spec.
            'documents.*' => 'required|file|mimes:pdf|max:' . self::MAX_KB,
        ], [
            'documents.*.mimes' => 'Only PDF files can be attached here. For a photo, use the photo uploader above.',
            'documents.*.max'   => 'Each PDF must be 20 MB or smaller.',
        ]);

        foreach ($request->file('documents', []) as $file) {
            $path = $file->store('job-documents/' . $serviceRequest->request_id, 'public');

            ServiceRequestDocument::create([
                'service_request_id' => $serviceRequest->id,
                'kind'               => ServiceRequestDocument::KIND_CLIENT_UPLOAD,
                // The client doesn't name their upload; the file's own name is
                // the most meaningful label, minus the extension we already
                // know from the mime.
                'title'              => $this->titleFrom($file->getClientOriginalName()),
                'path'               => $path,
                'original_name'      => $file->getClientOriginalName(),
                'mime'               => $file->getClientMimeType(),
                'size_bytes'         => $file->getSize(),
                'is_client_visible'  => true,
                'uploaded_by'        => $request->user()->id,
            ]);
        }

        $count = count($request->file('documents', []));

        return back()->with('success', $count . ' document' . ($count === 1 ? '' : 's') . ' shared on this job.');
    }

    public function destroy(Request $request, ServiceRequest $serviceRequest, ServiceRequestDocument $document)
    {
        // A client can withdraw a file they attached by mistake, but only
        // their own — never a drawing or approval the team put on the job.
        $ownsRequest = $serviceRequest->user_id === $request->user()->id;
        $ownsDocument = $document->service_request_id === $serviceRequest->id
            && $document->uploaded_by === $request->user()->id
            && $document->isClientUpload();

        if (!$ownsRequest || !$ownsDocument) {
            return back()->with('error', 'You can only remove a document you attached yourself.');
        }

        Storage::disk('public')->delete($document->path);
        $document->delete();

        return back()->with('success', 'Document removed.');
    }

    private function titleFrom(string $originalName): string
    {
        $base = pathinfo($originalName, PATHINFO_FILENAME);
        $base = trim($base) !== '' ? trim($base) : 'Client document';

        return mb_substr($base, 0, 200);
    }
}
