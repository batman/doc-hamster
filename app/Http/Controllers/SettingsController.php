<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Http\SettingsMenu;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;

/**
 * Einstellungen: the groups, then a group's entries (design point 21). The
 * pages themselves come from their own controllers in layouts/settings.
 */
final class SettingsController
{
    public function index(): View
    {
        return view('settings.index');
    }

    public function group(string $group): View|RedirectResponse
    {
        $groups = SettingsMenu::groups();
        abort_unless(isset($groups[$group]), 404);

        // A leaf group, or a group with one entry, is a single page: open it right away (point 37).
        return $groups[$group]['leaf'] || count($groups[$group]['items']) === 1 ? redirect()->route($groups[$group]['items'][0]['route']) : view('settings.index');
    }
}
