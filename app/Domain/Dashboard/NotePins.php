<?php

declare(strict_types=1);

namespace App\Domain\Dashboard;

/**
 * Point 233, A395-A397: notes-board columns and notes pinned to Home as their own widgets. The pin lives in the
 * person's dashboard layout (DashboardLayout::PINNED); deleting a column or note takes its widget off every Home of
 * the organisation. Moving a note keeps it (the widget points at the note, not at its column).
 */
final class NotePins
{
    public const string COLUMN = 'note_column';

    public const string NOTE = 'note';

    /**
     * What this person has pinned, as layout keys ("note:01h…").
     *
     * @return array<string, true>
     */
    public static function of(string $userId): array
    {
        $keys = [];
        foreach (UserDashboard::of($userId)->cleanLayout()['widgets'] as $widget) {
            if (isset($widget['ref'])) {
                $keys[DashboardLayout::key($widget['type'], $widget['ref'])] = true;
            }
        }

        return $keys;
    }

    public static function set(string $userId, string $type, string $ref, bool $on): void
    {
        $dashboard = UserDashboard::of($userId);
        $dashboard->layout = DashboardLayout::withPinned($dashboard->cleanLayout(), $type, $ref, $on);
        $dashboard->save();
    }

    /** A deleted column or note leaves every Home of the current organisation (A397). */
    public static function forget(string $type, string $ref): void
    {
        $key = DashboardLayout::key($type, $ref);
        UserDashboard::query()->whereNotNull('layout')->get()->each(static function (UserDashboard $dashboard) use ($type, $ref, $key): void {
            $layout = $dashboard->cleanLayout();
            if (in_array($key, array_map(static fn (array $w): string => DashboardLayout::key($w['type'], $w['ref'] ?? null), $layout['widgets']), true)) {
                $dashboard->layout = DashboardLayout::withPinned($layout, $type, $ref, false);
                $dashboard->save();
            }
        });
    }
}
