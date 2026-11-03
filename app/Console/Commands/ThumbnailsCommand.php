<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Documents\DocumentFile;
use App\Domain\Documents\Thumbnail;
use App\Domain\Org\OrgScope;
use Illuminate\Console\Command;

/** Makes the missing thumbnails of files uploaded before design point 14. Runs again safely. */
final class ThumbnailsCommand extends Command
{
    protected $signature = 'dochamster:thumbnails';

    protected $description = 'Create the missing thumbnails of receipts';

    public function handle(): int
    {
        $made = 0;
        DocumentFile::query()->withoutGlobalScope(OrgScope::class)->whereNull('thumb_path')->each(function (DocumentFile $file) use (&$made): void {
            $path = Thumbnail::make($file);
            if ($path !== null) {
                $file->update(['thumb_path' => $path]);
                $made++;
            }
        });
        $this->info("{$made} thumbnails created.");

        return self::SUCCESS;
    }
}
