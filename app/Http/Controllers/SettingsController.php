<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\Gate;

/**
 * Einstellungen: one page that leads to everything besides receipts and bank
 * (ui-redesign point 12). A person sees only the links they may open.
 */
final class SettingsController
{
    /** group => list of [key, route, icon, ability (null = everyone logged in)] */
    private const GROUPS = [
        'access' => [
            ['members', 'members.index', 'users', 'members.manage'],
            ['roles', 'roles.index', 'user-shield', 'members.manage'],
        ],
        'documents' => [
            ['tags', 'tags.index', 'tags', 'tags.manage'],
        ],
        'bank' => [
            ['bank', 'bank.import', 'building-bank', 'banking.import'],
        ],
        'export' => [
            ['export', 'exports.form', 'file-export', 'exports.create'],
        ],
        'system' => [
            ['update', 'updates.show', 'cloud-download', 'settings.manage'],
            ['phone', 'phone-check.qr', 'device-mobile', null],
        ],
    ];

    public function index(): View
    {
        $groups = [];
        foreach (self::GROUPS as $group => $links) {
            $allowed = array_values(array_filter($links, static fn (array $link): bool => $link[3] === null || Gate::allows($link[3])));
            if ($allowed !== []) {
                $groups[$group] = $allowed;
            }
        }

        return view('settings.index', ['groups' => $groups]);
    }
}
