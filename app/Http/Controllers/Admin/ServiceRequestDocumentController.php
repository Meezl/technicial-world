<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceRequest;
use App\Models\ServiceRequestDocument;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Documents held against a job for its whole life — the case analysis
 * produced before quoting, the sample report a ticket generated, a signed
 * approval. Previously the only files a REQ could hold were whatever the
 * client attached when filing, so the reasoning behind a job lived in email.
 *
 * Everything uploaded here is internal until somebody deliberately shares it.
 */
class ServiceRequestDocumentController extends Controller
{
    public function store(Request $request, ServiceRequest $serviceRequest)
    {
        $data = $request->validate([
            'file'  => 'required|file|mimes:pdf,doc,docx,xls,xlsx,jpg,jpeg,png,webp,heic,heif|max:20480',
            'kind'  => ['required', Rule::in(ServiceRequestDocument::KINDS)],
            'title' => 'required|string|max:200',
            'notes' => 'nullable|string|max:2000',
            // Absent means internal. Sharing with the client is always an
            // explicit act, never a default.
            'is_client_visible' => 'nullable|boolean',
            'ticket_id' => [
                'nullable',
                Rule::exists('tickets', 'id')->where('service_request_id', $serviceRequest->id),
            ],
        ]);

        $file = $request->file('file');
        $path = $file->store('job-documents/' . $serviceRequest->request_id, 'public');

        $document = ServiceRequestDocument::create([
            'service_request_id' => $serviceRequest->id,
            'ticket_id'          => $data['ticket_id'] ?? null,
            'kind'               => $data['kind'],
            'title'              => $data['title'],
            'notes'              => $data['notes'] ?? null,
            'path'               => $path,
            'original_name'      => $file->getClientOriginalName(),
            'mime'               => $file->getClientMimeType(),
            'size_bytes'         => $file->getSize(),
            'is_client_visible'  => $request->boolean('is_client_visible'),
            'uploaded_by'        => $request->user()->id,
        ]);

        return back()->with('success', "\"{$document->title}\" added to {$serviceRequest->request_id}.");
    }

    /**
     * Show or hide a document from the client without re-uploading it.
     */
    public function updateVisibility(Request $request, ServiceRequest $serviceRequest, ServiceRequestDocument $document)
    {
        abort_unless($document->service_request_id === $serviceRequest->id, 404);

        $request->validate(['is_client_visible' => 'required|boolean']);

        $document->update(['is_client_visible' => $request->boolean('is_client_visible')]);

        return back()->with('success', $document->is_client_visible
            ? 'Document is now visible to the client.'
            : 'Document is now internal only.');
    }

    public function destroy(ServiceRequest $serviceRequest, ServiceRequestDocument $document)
    {
        abort_unless($document->service_request_id === $serviceRequest->id, 404);

        Storage::disk('public')->delete($document->path);
        $document->delete();

        return back()->with('success', 'Document removed.');
    }
}
