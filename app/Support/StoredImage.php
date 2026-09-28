<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Store an uploaded photograph in a format a browser will actually draw.
 *
 * iPhones shoot HEIC by default, and HEIC uploads were deliberately allowed
 * rather than rejected — see the `mimes` rules and issue #27, where refusing
 * them meant technicians on site could not send a photograph at all. What was
 * never done is the other half: Chrome, Firefox and Edge cannot decode HEIC, so
 * the file stored perfectly and rendered as a broken image everywhere except
 * Safari. Six photographs on one job, every one of them a grey box.
 *
 * Converting at rest rather than on the way out is deliberate. A photograph is
 * written once and looked at repeatedly — by the office, by the client, in a
 * PDF, from a phone on site — and a conversion on each read would have to be
 * right in every one of those places.
 *
 * Degrades rather than fails. Where no decoder is installed the original is
 * stored exactly as before, which is no worse than today and never loses the
 * upload; it just stays unviewable until the extension is available.
 */
class StoredImage
{
    /** Formats a browser will not draw, and which are therefore converted. */
    public const UNVIEWABLE_EXTENSIONS = ['heic', 'heif'];

    /**
     * Store the upload, converting it first if a browser could not show it.
     *
     * @return string The path on the disk, as `store()` would have returned.
     */
    public static function put(UploadedFile $file, string $directory, string $disk = 'public'): string
    {
        if (!self::needsConversion($file)) {
            return $file->store($directory, $disk);
        }

        $converted = self::toJpeg($file);

        if ($converted === null) {
            // No decoder here. The upload is kept as it arrived — losing a
            // technician's photograph because the server cannot re-encode it
            // would be a far worse failure than one that will not render.
            return $file->store($directory, $disk);
        }

        return $file->storeAs($directory, $converted, $disk);
    }

    /** Is this an upload a browser would refuse to draw? */
    public static function needsConversion(UploadedFile $file): bool
    {
        $extension = strtolower($file->getClientOriginalExtension());
        $mime = strtolower((string) $file->getMimeType());

        return in_array($extension, self::UNVIEWABLE_EXTENSIONS, true)
            || str_contains($mime, 'heic')
            || str_contains($mime, 'heif');
    }

    /** Can anything on this machine read a HEIC? */
    public static function canConvert(): bool
    {
        if (!class_exists(\Imagick::class)) {
            return false;
        }

        try {
            // Installed is not the same as able: ImageMagick reads HEIC only
            // when it was built against libheif, and asking is the only way to
            // find out.
            return \Imagick::queryFormats('HEI*') !== [];
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Rewrite the upload in place as a JPEG.
     *
     * @return string|null The new filename, or null if it could not be read.
     */
    private static function toJpeg(UploadedFile $file): ?string
    {
        if (!self::canConvert()) {
            return null;
        }

        try {
            $image = new \Imagick($file->getRealPath());
            $image->setImageFormat('jpeg');
            // Good enough that nobody can tell on a site photograph, small
            // enough that a gallery of them still loads on site signal.
            $image->setImageCompressionQuality(85);
            // An iPhone writes the orientation as metadata rather than rotating
            // the pixels, and dropping the metadata without applying it first
            // is how a photograph ends up sideways.
            $image->autoOrient();
            $image->stripImage();

            $name = Str::random(40) . '.jpg';
            $written = $image->writeImage($file->getRealPath());
            $image->clear();

            if (!$written) {
                return null;
            }

            return $name;
        } catch (\Throwable $e) {
            Log::warning('HEIC conversion failed; storing the original', [
                'original' => $file->getClientOriginalName(),
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }
}
