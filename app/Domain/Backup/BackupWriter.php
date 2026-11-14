<?php

declare(strict_types=1);

namespace App\Domain\Backup;

use App\Domain\Backup\Zip\ZipSink;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Writes one organisation's backup into a ZIP sink (point 206.6): a JSON file per table under data/, CSV for
 * people under csv/, the receipts under dokumente/JJJJ-MM/ (derived files under abgeleitet/), LIESMICH.txt and
 * manifest.json last – app and schema version, the choice, and size + SHA-256 of every other entry.
 */
final class BackupWriter
{
    public const string FORMAT = 'dochamster-backup';

    public const int FORMAT_VERSION = 1;

    /** @var list<array{path: string, size: int, sha256: string, type: string, file_id?: string}> */
    private array $files = [];

    /** @var list<string> */
    private array $temporary = [];

    /** @var list<string> */
    private array $missing = [];

    /**
     * @param  (Closure(int, int): void)|null  $progress  done / total steps
     */
    public function __construct(
        private readonly ZipSink $zip,
        private readonly string $orgId,
        private readonly BackupOptions $options,
        private readonly ?string $userId = null,
        private readonly ?Closure $progress = null,
    ) {}

    public function write(): void
    {
        try {
            $tables = BackupTables::chosen($this->options);
            $files = $this->options->has(BackupOptions::DOCUMENTS) ? $this->fileRows() : [];
            $total = count($tables) + count($files) * ($this->options->derived ? 2 : 1) + 3;
            $done = 0;
            $step = function () use (&$done, $total): void {
                if ($this->progress !== null) {
                    ($this->progress)(++$done, $total);
                }
            };

            foreach ($tables as $table) {
                $this->add('data/'.$table.'.json', $this->tableJson($table), 'data');
                $step();
            }
            foreach ($files as $file) {
                $this->addStored($file, 'path', 'original');
                $step();
                if ($this->options->derived) {
                    $this->addStored($file, 'thumb_path', 'derived');
                    $step();
                }
            }
            if ($this->options->has(BackupOptions::DOCUMENTS)) {
                $this->addString('csv/dokumente.csv', BackupCsv::documents($this->orgId, $this->options), 'csv');
            }
            $step();
            if ($this->options->has(BackupOptions::BANK)) {
                $this->addString('csv/buchungen.csv', BackupCsv::transactions($this->orgId, $this->options), 'csv');
            }
            $step();
            $this->addString('LIESMICH.txt', (string) __('backup.readme', ['app' => config('app.name')]), 'readme');
            $this->zip->addString('manifest.json', (string) json_encode($this->manifest(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            $step();
            $this->zip->finish();
        } finally {
            foreach ($this->temporary as $path) {
                @unlink($path);
            }
        }
    }

    /** The latest migration that ran here – a backup from a newer schema is refused by the restore. */
    public static function schemaVersion(): string
    {
        return (string) DB::table('migrations')->max('migration');
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(): array
    {
        return [
            'format' => self::FORMAT,
            'format_version' => self::FORMAT_VERSION,
            'app_version' => (string) config('dochamster.version'),
            'schema_version' => self::schemaVersion(),
            'created_at' => now()->toIso8601String(),
            'created_by' => $this->userId,
            'org_id' => $this->orgId,
            'full' => $this->options->isFull(),
            'options' => $this->options->toArray(),
            'missing' => $this->missing,
            'files' => $this->files,
        ];
    }

    /** The table's rows as a JSON array, written to a temporary file row by row (OCR text can be large). */
    private function tableJson(string $table): string
    {
        $path = (string) tempnam(Backup::workDir(), 'dhb');
        $this->temporary[] = $path;
        $out = fopen($path, 'wb');
        if ($out === false) {
            throw new RuntimeException('No temporary file.');
        }
        fwrite($out, '[');
        $first = true;
        foreach (BackupTables::query($table, $this->orgId, $this->options)->cursor() as $row) {
            fwrite($out, ($first ? "\n" : ",\n").json_encode((array) $row, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_THROW_ON_ERROR));
            $first = false;
        }
        fwrite($out, "\n]\n");
        fclose($out);

        return $path;
    }

    /**
     * @return list<object{id: string, path: string, thumb_path: ?string, title: string, doc_date: ?string, created_at: ?string}>
     */
    private function fileRows(): array
    {
        /** @var list<object{id: string, path: string, thumb_path: ?string, title: string, doc_date: ?string, created_at: ?string}> */
        return DB::table('document_files as f')->join('documents as d', 'd.id', '=', 'f.document_id')
            ->where('f.org_id', $this->orgId)->whereIn('f.document_id', BackupTables::documentIds($this->orgId, $this->options))
            ->orderBy('f.id')->get(['f.id', 'f.path', 'f.thumb_path', 'd.title', 'd.doc_date', 'd.created_at'])->all();
    }

    /**
     * @param  object{id: string, path: string, thumb_path: ?string, title: string, doc_date: ?string, created_at: ?string}  $file
     */
    private function addStored(object $file, string $column, string $type): void
    {
        $stored = $file->{$column};
        if (! is_string($stored) || $stored === '') {
            return;
        }
        $disk = Storage::disk('local');
        if (! $disk->exists($stored)) {
            $this->missing[] = $file->id.($type === 'derived' ? ':derived' : '');

            return;
        }
        $extension = Str::lower(pathinfo($stored, PATHINFO_EXTENSION));
        $extension = preg_match('/^[a-z0-9]{1,8}$/', $extension) === 1 ? '.'.$extension : '';
        $month = substr((string) ($file->doc_date ?? $file->created_at ?? ''), 0, 7);
        $month = preg_match('/^\d{4}-\d{2}$/', $month) === 1 ? $month : 'ohne-datum';
        $name = $type === 'derived'
            ? 'abgeleitet/'.$file->id.'_vorschau'.$extension
            : 'dokumente/'.$month.'/'.(Str::limit(Str::slug($file->title), 40, '') ?: 'dokument').'_'.$file->id.$extension;

        [$size, $sha] = $this->zip->addFile($name, $disk->path($stored));
        $this->files[] = ['path' => $name, 'size' => $size, 'sha256' => $sha, 'type' => $type, 'file_id' => $file->id];
    }

    private function add(string $name, string $path, string $type): void
    {
        [$size, $sha] = $this->zip->addFile($name, $path);
        $this->files[] = ['path' => $name, 'size' => $size, 'sha256' => $sha, 'type' => $type];
    }

    private function addString(string $name, string $contents, string $type): void
    {
        [$size, $sha] = $this->zip->addString($name, $contents);
        $this->files[] = ['path' => $name, 'size' => $size, 'sha256' => $sha, 'type' => $type];
    }
}
