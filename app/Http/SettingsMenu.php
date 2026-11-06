<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Einstellungen as a column menu (design points 21 to 23): groups, their
 * entries, and which of them the current page belongs to.
 */
final class SettingsMenu
{
    /** group => [icon, entries [key, route, icon, ability (null = everyone), routes that belong to it], leaf = one page] */
    private const GROUPS = [
        'people' => ['users', [
            ['members', 'members.index', 'users', 'members.manage', ['members.*']],
            ['roles', 'roles.index', 'user-shield', 'members.manage', ['roles.*']],
        ], false],
        'bank' => ['building-bank', [
            ['bank', 'bank.import', 'building-bank', 'banking.import', ['bank.import*', 'bank.accounts.*']],
        ], false],
        'tags' => ['tags', [
            ['tags', 'tags.index', 'tags', 'tags.manage', ['tags.*']],
        ], true],
        'folders' => ['folder', [
            ['folders', 'folders.index', 'folder', 'documents.edit', ['folders.*']],
        ], true],
        'scanner' => ['printer', [
            ['scanner', 'scanner.show', 'printer', 'settings.manage', ['scanner.*']],
        ], false],
        'export' => ['file-export', [
            ['export', 'exports.form', 'file-export', 'exports.create', ['exports.*']],
            ['shares', 'shares.index', 'send', 'exports.create', ['shares.*']],
        ], false],
        'update' => ['cloud-download', [
            ['update', 'updates.show', 'cloud-download', 'system.update', ['updates.*']],
            ['phone', 'phone-check.qr', 'device-mobile', null, ['phone-check*']],
        ], false],
    ];

    /**
     * The groups this person may open, each with its allowed entries.
     *
     * @return array<string, array{icon: string, leaf: bool, items: list<array{key: string, route: string, icon: string}>}>
     */
    public static function groups(): array
    {
        $groups = [];
        foreach (self::GROUPS as $key => [$icon, $entries, $leaf]) {
            $items = [];
            foreach ($entries as [$item, $route, $itemIcon, $ability]) {
                if ($ability === null || Gate::allows($ability)) {
                    $items[] = ['key' => $item, 'route' => $route, 'icon' => $itemIcon];
                }
            }
            if ($items !== []) {
                $groups[$key] = ['icon' => $icon, 'leaf' => $leaf, 'items' => $items];
            }
        }

        return $groups;
    }

    /**
     * Group and entry of the current page; null where the route isn't one of them.
     *
     * @return array{?string, ?string}
     */
    public static function current(Request $request): array
    {
        foreach (self::GROUPS as $key => [, $entries]) {
            foreach ($entries as [$item, , , , $routes]) {
                if ($request->routeIs(...$routes)) {
                    return [$key, $item];
                }
            }
        }

        $group = $request->route('group');

        return [is_string($group) && isset(self::GROUPS[$group]) ? $group : null, null];
    }
}
