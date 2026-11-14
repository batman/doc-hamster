<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Backup\Backup;
use App\Domain\Dashboard\DashboardLayout;
use App\Domain\Dashboard\DashboardWidgets;
use App\Domain\Dashboard\FirstSteps;
use App\Domain\Dashboard\UserDashboard;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Permission;
use App\Http\Badges;
use App\Infrastructure\Environment\OcrProgress;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Home (points 175, 176, A349-A353): a dashboard window with widgets, laid out per person. The pencil switches the
 * edit mode (add, remove, reorder, size); every change saves at once. "Auf Home anheften" in any window's ⋯ menu
 * pins that window here (A351). The notes board moved to /notes (HomeController).
 */
final class DashboardController
{
    private ?DashboardWidgets $widgets = null;

    public function index(CurrentOrg $org): View
    {
        $dashboard = UserDashboard::of($this->userId($org));
        $layout = $dashboard->cleanLayout();
        $available = $this->available();
        $widgets = array_values(array_filter($layout['widgets'], static fn (array $w): bool => in_array($w['type'], $available, true)));

        return view('dashboard.index', [
            'widgets' => array_map(fn (array $w): array => $w + ['data' => $this->data($w['type'], $org, $dashboard)], $widgets),
            'available' => $available,
            'addable' => array_values(array_diff($available, array_column($widgets, 'type'))),
            'backupHint' => $this->backupHint($org),
            'firstSteps' => $this->firstSteps($dashboard),
        ]);
    }

    /** A372: "Letztes Backup vor N Tagen" for those who may make one, after 90 days or never (-1 = never). */
    private function backupHint(CurrentOrg $org): ?int
    {
        if (! Gate::allows(Permission::CreateBackup->value)) {
            return null;
        }
        $last = Backup::lastAt($org->id());
        if ($last === null) {
            return -1;
        }
        $days = (int) $last->diffInDays(now());

        return $days > 90 ? $days : null;
    }

    /**
     * F31: "Erste Schritte" – the steps this person may do, ticked from the data; null = no card.
     *
     * @return list<array{key: string, done: bool}>|null
     */
    private function firstSteps(UserDashboard $dashboard): ?array
    {
        if ($dashboard->first_steps_hidden_at !== null) {
            return null;
        }
        $allowed = [
            ...(Gate::allows(Permission::ImportBanking->value) ? ['account', 'import'] : []),
            ...(Gate::allows(Permission::UploadDocuments->value) ? ['upload', 'phone'] : []),
        ];

        return $allowed === [] ? null : FirstSteps::card(FirstSteps::done(), $allowed);
    }

    /** F31: the X on "Erste Schritte" – hidden for this person for good. */
    public function hideFirstSteps(Request $request, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $dashboard = UserDashboard::of($this->userId($org));
        $dashboard->layout = $dashboard->cleanLayout();
        $dashboard->first_steps_hidden_at = now();
        $dashboard->save();

        return $this->answer($request, __('empty.first_steps.hidden'));
    }

    /** A350: order, sizes and removals – the whole list of widgets as the person sees it now. */
    public function layout(Request $request, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $request->validate(['widgets' => ['present', 'array'], 'widgets.*.type' => ['required', 'string'], 'widgets.*.size' => ['nullable', 'string']]);
        $dashboard = UserDashboard::of($this->userId($org));
        $layout = $dashboard->cleanLayout();
        // Widgets the person may not see right now (a right taken away) keep their place at the end.
        $hidden = array_values(array_filter($layout['widgets'], fn (array $w): bool => ! in_array($w['type'], $this->available(), true)));
        $sent = array_values(array_filter(DashboardLayout::widgets($request->input('widgets')), fn (array $w): bool => in_array($w['type'], $this->available(), true)));
        $dashboard->layout = ['widgets' => DashboardLayout::widgets([...$sent, ...$hidden]), 'pins' => $layout['pins']];
        $dashboard->save();

        return $this->answer($request, __('dashboard.saved'));
    }

    /** "+ Baustein": adds one widget at the end and answers it drawn (in edit mode). */
    public function add(Request $request, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['type' => ['required', 'string', Rule::in($this->available())]]);
        $dashboard = UserDashboard::of($this->userId($org));
        $layout = $dashboard->cleanLayout();
        if (! in_array($data['type'], array_column($layout['widgets'], 'type'), true)) {
            $layout['widgets'][] = ['type' => $data['type'], 'size' => DashboardLayout::WIDGETS[$data['type']]['size']];
            $dashboard->layout = $layout;
            $dashboard->save();
        }
        $widget = collect($layout['widgets'])->firstWhere('type', $data['type']);
        if (! $request->expectsJson()) {
            return redirect()->route('home');
        }

        return response()->json([
            'message' => __('dashboard.added'),
            'html' => view('dashboard._widget', ['widget' => $widget + ['data' => $this->data($data['type'], $org, $dashboard)]])->render(),
        ]);
    }

    /** A351: "Auf Home anheften" from a window's ⋯ menu, or "+ Link" in Schnellzugriff (an https address). */
    public function pin(Request $request, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $request->validate(['url' => ['required', 'string', 'max:'.DashboardLayout::URL_MAX], 'title' => ['nullable', 'string', 'max:200'], 'icon' => ['nullable', 'string', 'max:40']]);
        $pin = DashboardLayout::pin($request->input('url'), $request->input('title'), $request->input('icon'), $request->getSchemeAndHttpHost());
        if ($pin === null) {
            return $request->expectsJson()
                ? response()->json(['message' => __('dashboard.pin_invalid'), 'errors' => ['url' => [__('dashboard.pin_invalid')]]], 422)
                : back()->withErrors(['url' => __('dashboard.pin_invalid')]);
        }
        $dashboard = UserDashboard::of($this->userId($org));
        $layout = $dashboard->cleanLayout();
        if (in_array($pin['url'], array_column($layout['pins'], 'url'), true)) {
            return $this->answer($request, __('dashboard.pin_exists', ['title' => $pin['title']]));
        }
        $layout['pins'][] = $pin;
        // The tiles need their widget: a person who removed Schnellzugriff gets it back on top.
        if (! in_array('quick', array_column($layout['widgets'], 'type'), true)) {
            array_unshift($layout['widgets'], ['type' => 'quick', 'size' => DashboardLayout::WIDGETS['quick']['size']]);
        }
        $dashboard->layout = $layout;
        $dashboard->save();

        return $this->answer($request, __('dashboard.pinned', ['title' => $pin['title']]));
    }

    /** The X on a tile (edit mode): the pin with this address goes. */
    public function unpin(Request $request, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['url' => ['required', 'string']]);
        $dashboard = UserDashboard::of($this->userId($org));
        $layout = $dashboard->cleanLayout();
        $layout['pins'] = array_values(array_filter($layout['pins'], static fn (array $pin): bool => $pin['url'] !== $data['url']));
        $dashboard->layout = $layout;
        $dashboard->save();

        return $this->answer($request, __('dashboard.unpinned'));
    }

    /**
     * The widget types this person may use (rights of the area they show).
     *
     * @return list<string>
     */
    private function available(): array
    {
        return array_values(array_filter(array_keys(DashboardLayout::WIDGETS), static fn (string $type): bool => match ($type) {
            'inbox', 'drop' => Gate::allows(Permission::UploadDocuments->value),
            'bookings' => Gate::allows(Permission::ImportBanking->value),
            default => true,
        }));
    }

    /**
     * @return array<string, mixed>
     */
    private function data(string $type, CurrentOrg $org, UserDashboard $dashboard): array
    {
        // One instance per request: the property ids are read once for every widget.
        $widgets = $this->widgets ??= new DashboardWidgets($this->userId($org), Gate::allows(Permission::ViewAllDocuments->value));

        return match ($type) {
            'photo' => ['photo' => $dashboard->photo_path !== null ? route('dashboard.photo', ['v' => $dashboard->updated_at?->getTimestamp()]) : null],
            'greeting' => $this->greeting($org, $widgets),
            'quick' => ['pins' => $dashboard->cleanLayout()['pins']],
            'inbox' => ['documents' => $widgets->inbox()],
            'due' => ['rows' => $widgets->due()],
            'bookings' => ['rows' => $widgets->bookings()],
            'activity' => ['rows' => $widgets->activity($org->id())],
            'notes' => ['notes' => $widgets->notes()],
            'ocr' => OcrProgress::open($org->id()),
            default => [],
        };
    }

    /**
     * "Guten Morgen, Max", the date and one line of what waits.
     *
     * @return array<string, mixed>
     */
    private function greeting(CurrentOrg $org, DashboardWidgets $widgets): array
    {
        $now = Carbon::now();
        $parts = [];
        // The menu's counts (kept a few seconds in the session): new in the Posteingang, open bookings (A274).
        $badges = Badges::counts($org);
        if (Gate::allows(Permission::UploadDocuments->value)) {
            $new = $badges[Badges::INBOX] ?? 0;
            $parts[] = trans_choice('dashboard.summary.new', $new, ['count' => $new]);
        }
        $due = $widgets->dueSoon();
        $parts[] = trans_choice('dashboard.summary.due', $due, ['count' => $due]);
        if (Gate::allows(Permission::ImportBanking->value)) {
            $open = $badges[Badges::BANK] ?? 0;
            $parts[] = trans_choice('dashboard.summary.open', $open, ['count' => $open]);
        }

        $now->locale(app()->getLocale());

        return [
            'hello' => __('dashboard.hello.'.($now->hour < 11 ? 'morning' : ($now->hour < 18 ? 'day' : 'evening')), ['name' => $org->membership()->user->display_name ?? '']),
            'date' => $now->isoFormat('dddd, D. MMMM YYYY'),
            'summary' => implode(' · ', $parts),
        ];
    }

    private function answer(Request $request, string $message): JsonResponse|RedirectResponse
    {
        return $request->expectsJson() ? response()->json(['message' => $message]) : redirect()->route('home')->with('status', $message);
    }

    private function userId(CurrentOrg $org): string
    {
        return $org->membership()->user_id;
    }
}
