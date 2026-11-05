<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\User;
use App\Http\Middleware\SetLocale;
use App\Http\SettingsMenu;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

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

    /** The display language of the signed-in person (SetLocale::SUPPORTED). */
    public function language(Request $request): RedirectResponse
    {
        $data = $request->validate(['locale' => ['required', Rule::in(SetLocale::SUPPORTED)]]);
        $user = $request->user();
        abort_unless($user instanceof User, 403);
        $user->forceFill(['locale' => $data['locale']])->save();

        return redirect()->route('settings.index')->with('status', __('settings.language.saved'));
    }

    public function group(string $group): View|RedirectResponse
    {
        $groups = SettingsMenu::groups();
        abort_unless(isset($groups[$group]), 404);

        // A leaf group is a single page: open it right away.
        return $groups[$group]['leaf'] ? redirect()->route($groups[$group]['items'][0]['route']) : view('settings.index');
    }
}
