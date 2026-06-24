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
            abort(404);
        }

        // Resolve against the public disk; bail if Storage doesn't see it
        $disk = Storage::disk('public');
        if (!$disk->exists($path)) {
            abort(404);
        }

        // Resolve and double-check the actual filesystem path stays inside
        // the public disk root — defence in depth against any creative
        // input that Storage's exists() check happens to allow through.
        $absolute = realpath($disk->path($path));
        $diskRoot = realpath($disk->path(''));
        if ($absolute === false || $diskRoot === false || !str_starts_with($absolute, $diskRoot . DIRECTORY_SEPARATOR)) {
            abort(404);
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
}
