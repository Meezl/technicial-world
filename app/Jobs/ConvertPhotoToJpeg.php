<?php

namespace App\Jobs;

use App\Support\StoredImage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Re-encode one stored photograph, away from whoever uploaded it.
 *
 * Converting inside the request meant the person who sent the photograph paid
 * for it: a web worker held for the length of a decode, and several uploads
 * landing together decoding at the same moment. Neither is something the office
 * should feel, and the second is how a burst of photographs from a site becomes
 * a slow app for everybody else.
 *
 * The upload is stored untouched and returns immediately. This runs afterwards
 * on the queue worker, one at a time, and repoints the record when it succeeds.
 * Until then the photograph is exactly what it was before any of this existed —
 * present, recorded, and not yet viewable in most browsers — so a failure here
 * costs nothing that was not already the case.
 */
class ConvertPhotoToJpeg implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** A decode is seconds; anything beyond this has gone wrong. */
    public int $timeout = 120;

    public int $tries = 2;

    public function __construct(
        private Model $photo,
        private string $column = 'file_path',
        private string $disk = 'public',
    ) {
    }

    /**
     * Only worth queueing when there is something to convert and something to
     * convert it with. Checked before dispatch so a JPEG upload — which is most
     * of them — never touches the queue at all.
     */
    public static function dispatchIfNeeded(Model $photo, string $column = 'file_path', string $disk = 'public'): void
    {
        $path = (string) $photo->{$column};

        if ($path === '' || !self::isUnviewable($path)) {
            return;
        }

        if (!StoredImage::canConvert()) {
            return;
        }

        self::dispatch($photo, $column, $disk);
    }

    private static function isUnviewable(string $path): bool
    {
        return in_array(
            strtolower(pathinfo($path, PATHINFO_EXTENSION)),
            StoredImage::UNVIEWABLE_EXTENSIONS,
            true
        );
    }

    public function handle(): void
    {
        $photo = $this->photo->fresh();

        if (!$photo) {
            return;
        }

        $path = (string) $photo->{$this->column};

        // Somebody may have replaced or removed it between the upload and this
        // running. Nothing to do, and nothing to complain about.
        if ($path === '' || !self::isUnviewable($path)) {
            return;
        }

        $disk = Storage::disk($this->disk);

        if (!$disk->exists($path)) {
            return;
        }

        if ($disk->size($path) > StoredImage::MAX_SOURCE_BYTES) {
            Log::warning('Photograph too large to convert; left as it is', ['path' => $path]);

            return;
        }

        StoredImage::constrainImagick();

        $newPath = preg_replace('/\.(heic|heif)$/i', '.jpg', $path) ?: $path . '.jpg';
        if ($newPath === $path) {
            $newPath = $path . '.jpg';
        }

        $image = null;

        try {
            $image = new \Imagick($disk->path($path));
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(85);
            // Before the metadata goes, or the picture ends up sideways: an
            // iPhone rotates by metadata rather than by pixels.
            $image->autoOrient();
            $image->stripImage();
            $image->writeImage($disk->path($newPath));
        } catch (\Throwable $e) {
            Log::warning('Photograph conversion failed; left as it is', [
                'path' => $path,
                'error' => $e->getMessage(),
            ]);

            return;
        } finally {
            $image?->clear();
        }

        // Repointed only once the new file is actually on disk. The original
        // stays: it is evidence about a client's property, and the cost of a
        // spare copy is nothing against the cost of being wrong about this.
        $photo->forceFill([$this->column => $newPath])->save();
    }
}
