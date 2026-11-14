<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Dashboard\UserDashboard;
use App\Domain\Org\CurrentOrg;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;

/**
 * The Home widget "Foto" (A349): the person's own picture, on the private disk, served only to its owner.
 */
final class DashboardPhotoController
{
    /** @var array<string, string> picture types we take, by their detected type */
    private const array TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

    public function show(CurrentOrg $org): Response
    {
        $path = UserDashboard::of($org->membership()->user_id)->photo_path;
        abort_if($path === null || ! Storage::disk('local')->exists($path), 404);
        $type = array_search(pathinfo($path, PATHINFO_EXTENSION), self::TYPES, true);

        return Storage::disk('local')->response($path, null, [
            'Content-Type' => is_string($type) ? $type : 'application/octet-stream',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=86400',
        ]);
    }

    public function store(Request $request, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $request->validate(['photo' => ['required', 'file', 'max:15360']]);
        $file = $request->file('photo');
        // Checked by its content, not its name (PLAN §11.2).
        $info = $file instanceof UploadedFile ? @getimagesize($file->getRealPath()) : false;
        $extension = is_array($info) ? (self::TYPES[$info['mime']] ?? null) : null;
        if (! $file instanceof UploadedFile || $extension === null) {
            return $request->expectsJson()
                ? response()->json(['message' => __('dashboard.photo_type'), 'errors' => ['photo' => [__('dashboard.photo_type')]]], 422)
                : back()->withErrors(['photo' => __('dashboard.photo_type')]);
        }

        $dashboard = UserDashboard::of($org->membership()->user_id);
        $old = $dashboard->photo_path;
        $path = 'dashboard/'.$org->id().'/'.$dashboard->user_id.'-'.bin2hex(random_bytes(4)).'.'.$extension;
        Storage::disk('local')->putFileAs(dirname($path), $file, basename($path));
        $dashboard->photo_path = $path;
        $dashboard->layout ??= $dashboard->cleanLayout();
        $dashboard->save();
        if ($old !== null && $old !== $path) {
            Storage::disk('local')->delete($old);
        }

        return $this->answer($request, __('dashboard.photo_saved'), route('dashboard.photo', ['v' => $dashboard->updated_at?->getTimestamp()]));
    }

    public function destroy(Request $request, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $dashboard = UserDashboard::of($org->membership()->user_id);
        if ($dashboard->photo_path !== null) {
            Storage::disk('local')->delete($dashboard->photo_path);
            $dashboard->photo_path = null;
            $dashboard->save();
        }

        return $this->answer($request, __('dashboard.photo_removed'), null);
    }

    private function answer(Request $request, string $message, ?string $url): JsonResponse|RedirectResponse
    {
        return $request->expectsJson() ? response()->json(['message' => $message, 'url' => $url]) : redirect()->route('home')->with('status', $message);
    }
}
