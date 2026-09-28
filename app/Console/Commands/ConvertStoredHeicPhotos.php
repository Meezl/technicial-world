<?php

namespace App\Console\Commands;

use App\Models\JobPhoto;
use App\Models\Technician;
use App\Support\StoredImage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Re-encode the HEIC photographs already on disk so they can be seen.
 *
 * Uploads are converted on the way in now, but everything sent before that
 * shipped is still a file no browser but Safari will draw — which is what the
 * office reported: a job carrying six photographs and showing six grey boxes.
 *
 * Rewrites the file beside the original, repoints the record, and leaves the
 * original alone. Nothing is deleted: a photograph is evidence about a client's
 * property, and the cost of keeping an unviewable copy is a few megabytes
 * against the cost of destroying the only one there was.
 */
class ConvertStoredHeicPhotos extends Command
{
    protected $signature = 'photos:convert-heic {--dry-run : List what would be converted and change nothing}';

    protected $description = 'Re-encode stored HEIC photographs as JPEG so browsers can display them';

    public function handle(): int
    {
        if (!StoredImage::canConvert()) {
            $this->error('No HEIC decoder on this machine — install the imagick extension built against libheif.');
            $this->line('Nothing has been changed.');

            return self::FAILURE;
        }

        $dryRun = $this->option('dry-run');
        $converted = 0;
        $failed = 0;

        foreach ($this->targets() as [$label, $model, $column]) {
            $rows = $model::query()
                ->where($column, 'like', '%.heic')
                ->orWhere($column, 'like', '%.heif')
                ->get();

            if ($rows->isEmpty()) {
                continue;
            }

            $this->info(sprintf('%s: %d to convert', $label, $rows->count()));

            foreach ($rows as $row) {
                $path = $row->{$column};

                if ($dryRun) {
                    $this->line('  would convert ' . $path);
                    continue;
                }

                $newPath = $this->convert($path);

                if ($newPath === null) {
                    $this->warn('  could not convert ' . $path);
                    $failed++;
                    continue;
                }

                $row->forceFill([$column => $newPath])->save();
                $this->line('  ' . $path . ' -> ' . $newPath);
                $converted++;
            }
        }

        $this->newLine();
        $this->info($dryRun
            ? 'Dry run finished — nothing was changed.'
            : sprintf('%d converted, %d left as they were.', $converted, $failed));

        return self::SUCCESS;
    }

    /**
     * Everywhere a photograph's path is kept.
     *
     * Progress-report photographs are JobPhoto rows too — they hang off the
     * report by a morph — so the one table covers both the evidence panel and
     * the field updates.
     *
     * @return array<int, array{0: string, 1: class-string, 2: string}>
     */
    private function targets(): array
    {
        return [
            ['Job and progress photos', JobPhoto::class, 'file_path'],
            ['Technician photos', Technician::class, 'profile_photo_path'],
        ];
    }

    private function convert(string $path): ?string
    {
        $disk = Storage::disk('public');

        if (!$disk->exists($path)) {
            return null;
        }

        try {
            $image = new \Imagick($disk->path($path));
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(85);
            // Applied before the metadata is stripped, or the photograph ends
            // up sideways — an iPhone rotates by metadata, not by pixels.
            $image->autoOrient();
            $image->stripImage();

            $newPath = preg_replace('/\.(heic|heif)$/i', '.jpg', $path);
            if ($newPath === $path) {
                $newPath = $path . '.jpg';
            }

            $disk->put($newPath, $image->getImageBlob());
            $image->clear();

            return $newPath;
        } catch (\Throwable $e) {
            $this->warn('  ' . $e->getMessage());

            return null;
        }
    }
}
