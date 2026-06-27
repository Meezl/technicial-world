<?php

namespace App\Http\Controllers;

use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves files from storage/app/public/ via PHP instead of relying on
 * the public/storage → storage/app/public symlink. This is required on
 * Railway / FrankenPHP setups where Caddy returns 403 trying to serve
 * symlinked content under a read-only deployment layer.
 *
 * Validates that the requested path stays inside the public disk root
 * to prevent path traversal.
 */
class StorageController extends Controller
{
    public function serve(string $path)
    {
        // Reject path traversal and absolute-path tricks up front
        if (str_contains($path, '..') || str_starts_with($path, '/')) {
            return $this->notFoundResponse($path);
        }

        // Resolve against the public disk; return a clear text 404 if the
        // file is missing — without this the browser tries to save the
        // HTML error page with the requested filename, confusing the user.
        $disk = Storage::disk('public');
        if (!$disk->exists($path)) {
            return $this->notFoundResponse($path);
        }

        // Resolve and double-check the actual filesystem path stays inside
        // the public disk root — defence in depth against any creative
        // input that Storage's exists() check happens to allow through.
        $absolute = realpath($disk->path($path));
        $diskRoot = realpath($disk->path(''));
        if ($absolute === false || $diskRoot === false || !str_starts_with($absolute, $diskRoot . DIRECTORY_SEPARATOR)) {
            return $this->notFoundResponse($path);
        }

        $mime = $disk->mimeType($path) ?: 'application/octet-stream';
        $size = $disk->size($path);

        $response = new BinaryFileResponse($absolute);
        $response->headers->set('Content-Type', $mime);
        $response->headers->set('Content-Length', (string) $size);
        $response->setPublic();
        $response->setMaxAge(60 * 60 * 24 * 7);  // 7 days — images/PDFs rarely change

        // ETag for cache validation
        $response->setEtag('"' . md5_file($absolute) . '"');

        return $response;
    }

    /**
     * Plain-text 404 with a meaningful message, so the browser can't
     * accidentally save an HTML error page using the original filename.
     */
    private function notFoundResponse(string $path): Response
    {
        $message = "File not found: {$path}\n\n" .
            "This file's metadata is on record but the actual file is missing from storage. " .
            "On Railway, this typically means the container's ephemeral filesystem was wiped between deploys. " .
            "Please re-upload, and contact the administrator if the issue persists.\n";

        return new Response($message, 404, [
            'Content-Type' => 'text/plain; charset=utf-8',
            'Cache-Control' => 'no-store',
        ]);
    }
}
