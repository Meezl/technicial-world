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
    protected $signature = 'photos:convert-heic
        {--dry-run : List what would be converted and change nothing}
        {--limit=25 : How many photographs to convert in this run}';

    protected $description = 'Re-encode stored HEIC photographs as JPEG so browsers can display them';

    public function handle(): int
    {
        if (!StoredImage::canConvert()) {
            $this->error('No HEIC decoder on this machine — install the imagick extension built against libheif.');
            $this->line('Nothing has been changed.');

            return self::FAILURE;
        }

        $dryRun = $this->option('dry-run');
        $limit = max(1, (int) $this->option('limit'));
        $converted = 0;
        $failed = 0;
        $remaining = 0;

        // Held to a budget, and to one thread. ImageMagick allocates outside
        // PHP's memory_limit, so nothing bounded it before and a long run took
        // the container with it rather than failing a photograph.
        StoredImage::constrainImagick();

        foreach ($this->targets() as [$label, $model, $column]) {
            $pending = $model::query()
                ->where(fn ($q) => $q->where($column, 'like', '%.heic')->orWhere($column, 'like', '%.heif'));

            $total = (clone $pending)->count();
            if ($total === 0) {
                continue;
            }

            $budget = max(0, $limit - $converted - $failed);
            if ($budget === 0) {
                $remaining += $total;
                continue;
            }

            $rows = $pending->orderBy('id')->limit($budget)->get();
            $remaining += $total - $rows->count();

            $this->info(sprintf('%s: %d of %d this run', $label, $rows->count(), $total));

            foreach ($rows as $row) {
                $path = $row->{$column};

                if ($dryRun) {
                    $this->line('  would convert ' . $path);
                    $converted++;
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

                // One picture's worth of memory freed before the next is
                // decoded, rather than at the end of a run that may not get
                // there.
                gc_collect_cycles();
            }
        }

        $this->newLine();
        $this->info($dryRun
            ? 'Dry run finished — nothing was changed.'
            : sprintf('%d converted, %d left as they were.', $converted, $failed));

        if ($remaining > 0) {
            $this->comment(sprintf(
                '%d still to do. Run it again — it works through them a batch at a time on purpose.',
                $remaining
            ));
        }

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

        if ($disk->size($path) > StoredImage::MAX_SOURCE_BYTES) {
            $this->warn('  too large to convert safely: ' . $path);

            return null;
        }

        $image = null;

        try {
            $newPath = preg_replace('/\.(heic|heif)$/i', '.jpg', $path);
            if ($newPath === $path) {
                $newPath = $path . '.jpg';
            }

            $image = new \Imagick($disk->path($path));
            $image->setImageFormat('jpeg');
            $image->setImageCompressionQuality(85);
            // Applied before the metadata is stripped, or the photograph ends
            // up sideways — an iPhone rotates by metadata, not by pixels.
            $image->autoOrient();
            $image->stripImage();

            // Straight to its destination. getImageBlob() would hold a second
            // complete copy of the picture in PHP memory beside the one
            // ImageMagick already has.
            $image->writeImage($disk->path($newPath));

            return $newPath;
        } catch (\Throwable $e) {
            $this->warn('  ' . $e->getMessage());

            return null;
        } finally {
            $image?->clear();
        }
    }
}
