<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Backup\Backup;
use App\Domain\Backup\BackupBuilder;
use App\Domain\Backup\BackupEstimate;
use App\Domain\Backup\BackupOptions;
use App\Domain\Backup\JobSecret;
use App\Domain\Backup\Zip\StreamZip;
use App\Domain\Org\CurrentOrg;
use App\Http\Badges;
use App\Jobs\Local\BuildBackup;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\Response;

/**
 * Einstellungen → Backup (point 206, D-023, PLAN §4.8): Admins download all data of the organisation as a ZIP.
 * Starting and downloading need the sign-in password again (passkey step-up does not exist yet) and are in the
 * audit log. Small backups stream at once; large ones (or any with a password above the limit) are built by a job.
 */
final class BackupController
{
    public function index(CurrentOrg $org): View
    {
        // Cleanup does not rely on cron alone (D-023).
        Backup::prune();
        $options = new BackupOptions;

        return view('settings.backup', [
            'estimate' => BackupEstimate::of($org->id(), $options),
            'limit' => self::limit(),
            'backups' => Backup::ofOrg($org->id())->where('created_by', $org->membership()->user_id)->where('status', '!=', Backup::STREAMED)
                ->latest()->limit(5)->get(),
            'last' => Backup::lastAt($org->id()),
            'hasPassword' => $org->membership()->user?->hasPassword() === true,
        ]);
    }

    /** The expected size for the current choice (AK-4.8.3), asked again whenever the form changes. */
    public function estimate(Request $request, CurrentOrg $org): JsonResponse
    {
        $estimate = BackupEstimate::of($org->id(), BackupOptions::from($this->choice($request)));
        $background = self::background($estimate);

        return response()->json([
            'bytes' => $estimate['bytes'],
            'size' => __('backup.size_value', ['size' => self::human($estimate['bytes'])]),
            'background' => $background,
            'note' => $background ? __('backup.size_background', ['limit' => self::human(self::limit())]) : __('backup.size_now'),
        ]);
    }

    public function store(Request $request, CurrentOrg $org, AuditLog $audit): Response
    {
        $request->validate([
            'range' => ['nullable', Rule::in(['', 'period'])],
            'from' => ['nullable', 'required_if:range,period', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'derived' => ['nullable', 'boolean'],
            'parts' => ['nullable', 'array'],
            'parts.*' => [Rule::in(BackupOptions::SECTIONS)],
            'zip_password' => ['nullable', 'string', 'min:12', 'max:200', 'confirmed'],
            'password' => ['required', 'current_password'],
        ]);
        Backup::prune();
        $orgId = $org->id();
        $userId = $org->membership()->user_id;
        if (Backup::ofOrg($orgId)->whereIn('status', [Backup::QUEUED, Backup::RUNNING])->exists()) {
            return back()->withErrors(['backup' => __('backup.running')]);
        }

        $options = BackupOptions::from($this->choice($request));
        $estimate = BackupEstimate::of($orgId, $options);
        $zipPassword = $request->filled('zip_password') ? (string) $request->input('zip_password') : null;
        $background = self::background($estimate);
        if ($background && $zipPassword === null) {
            return back()->withInput($request->except('password', 'zip_password', 'zip_password_confirmation'))->withErrors(['zip_password' => __('backup.password_required')]);
        }

        $backup = Backup::query()->create([
            'org_id' => $orgId, 'created_by' => $userId, 'status' => $background ? Backup::QUEUED : Backup::STREAMED,
            'options' => $options->toArray(), 'estimate' => $estimate['bytes'],
        ]);
        $meta = ['background' => $background, 'protected' => $zipPassword !== null, 'full' => $options->isFull()];
        $audit->record('backup.started', orgId: $orgId, userId: $userId, subject: $backup, meta: $meta, ip: $request->ip());

        if ($background) {
            BuildBackup::dispatch($backup->id, JobSecret::seal($backup->id, (string) $zipPassword));

            return redirect()->route('backup.index')->with('status', __('backup.queued'));
        }

        $audit->record('backup.downloaded', orgId: $orgId, userId: $userId, subject: $backup, meta: $meta, ip: $request->ip());
        $backup->forceFill(['downloaded_at' => now(), 'finished_at' => now()])->save();
        $name = self::fileName();
        if ($zipPassword !== null) {
            // The streaming writer can't encrypt: a protected ZIP is built as a file and deleted once sent.
            [$path, $size] = BackupBuilder::toFile($backup->id, $orgId, $options, $userId, $zipPassword);
            $backup->forceFill(['size' => $size])->save();

            return response()->download(Storage::disk('local')->path($path), $name, self::headers())->deleteFileAfterSend();
        }

        return response()->streamDownload(static fn () => BackupBuilder::stream($orgId, $options, $userId), $name, self::headers());
    }

    /** Only the person who started it, still allowed, within the keep time, with the password again (D-023). */
    public function download(Request $request, string $id, CurrentOrg $org, AuditLog $audit): Response
    {
        $request->validate(['password' => ['required', 'current_password']]);
        $userId = $org->membership()->user_id;
        $backup = Backup::ofOrg($org->id())->whereKey($id)->where('created_by', $userId)->first();
        if ($backup === null || ! $backup->isReady() || ! Storage::disk('local')->exists((string) $backup->path)) {
            return redirect()->route('backup.index')->withErrors(['backup' => __('backup.gone')]);
        }
        $audit->record('backup.downloaded', orgId: $org->id(), userId: $userId, subject: $backup, ip: $request->ip());
        $backup->forceFill(['downloaded_at' => now()])->save();
        Badges::forget();

        return response()->download(Storage::disk('local')->path((string) $backup->path), self::fileName($backup->created_at->toDateString()), self::headers());
    }

    /**
     * @return array<string, mixed>
     */
    private function choice(Request $request): array
    {
        return [
            'range' => $request->input('range', ''),
            'from' => $request->input('from'),
            'to' => $request->input('to'),
            'derived' => $request->input('derived', false),
            'parts' => $request->has('parts') || $request->has('range') ? (array) $request->input('parts', []) : BackupOptions::SECTIONS,
        ];
    }

    /**
     * @param  array{bytes: int, files: int}  $estimate
     */
    private static function background(array $estimate): bool
    {
        return $estimate['bytes'] > self::limit() || $estimate['files'] > StreamZip::MAX_ENTRIES - 100;
    }

    private static function limit(): int
    {
        return (int) config('dochamster.backup.stream_limit');
    }

    /**
     * @return array<string, string>
     */
    private static function headers(): array
    {
        return ['Cache-Control' => 'no-store', 'Content-Type' => 'application/zip', 'X-Accel-Buffering' => 'no'];
    }

    private static function fileName(?string $date = null): string
    {
        return 'docHamster-Backup-'.($date ?? now()->toDateString()).'.zip';
    }

    public static function human(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $size = (float) $bytes;
        $unit = 0;
        while ($size >= 1024 && $unit < count($units) - 1) {
            $size /= 1024;
            $unit++;
        }

        return str_replace('.', ',', (string) round($size, $unit === 0 ? 0 : 1)).' '.$units[$unit];
    }
}
