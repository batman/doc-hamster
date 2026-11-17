<?php

declare(strict_types=1);

namespace App\Http;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

/**
 * Einstellungen as menu windows (design points 21 to 23, 156): groups, their
 * entries, and which of them the current page belongs to.
 */
final class SettingsMenu
{
    /** group => [icon, entries [key, route, icon, ability (null = everyone), routes that belong to it], leaf = one page] */
    private const GROUPS = [
        // Point 222: everybody's own account – name, password, signed-in devices.
        'account' => ['user', [
            ['account', 'account.show', 'user', null, ['account.*']],
        ], true],
        'people' => ['users', [
            ['members', 'members.index', 'users', 'members.manage', ['members.*']],
            ['roles', 'roles.index', 'user-shield', 'members.manage', ['roles.*']],
        ], false],
        'bank' => ['building-bank', [
            ['bank', 'bank.import', 'building-bank', 'banking.import', ['bank.import', 'bank.accounts.*']],
            ['bank_reasons', 'bank.reasons', 'receipt-off', 'banking.import', ['bank.reasons*']],
            ['bank_mcc', 'bank.mcc', 'tags', 'banking.import', ['bank.mcc*']],
        ], false],
        'tags' => ['tags', [
            ['tags', 'tags.index', 'tags', 'tags.manage', ['tags.*']],
        ], true],
        'properties' => ['file-invoice', [
            ['properties', 'properties.index', 'file-invoice', 'settings.manage', ['properties.*']],
        ], true],
        'colors' => ['palette', [
            ['colors', 'palette.index', 'palette', 'settings.manage', ['palette.*']],
        ], true],
        'ocr' => ['text-scan-2', [
            ['ocr', 'ocr.show', 'text-scan-2', 'settings.manage', ['ocr.show']],
        ], true],
        // Point 236: the optional module "Dokumentgröße optimieren" (off after install, F46).
        'optimize' => ['arrows-minimize', [
            ['optimize', 'optimize.show', 'arrows-minimize', 'settings.manage', ['optimize.*']],
        ], true],
        // D-040: the door for AI assistants such as Claude – the owner only.
        'assistant' => ['plug', [
            ['assistant', 'assistant.show', 'plug', 'assistant.manage', ['assistant.*']],
        ], true],
        // Point 173: everything optional – OCR languages, later translation packs and modules.
        'extensions' => ['puzzle', [
            ['extensions', 'extensions.index', 'puzzle', 'settings.manage', ['extensions.*']],
        ], true],
        // Point 153.5: the sources of the Posteingang. Geräte comes with the Android share target (only once its route exists).
        'eingang' => ['inbox', [
            ['printer', 'printers.index', 'printer', 'documents.upload', ['printers.*']],
            ['mail', 'inbox.mail', 'mail', 'documents.upload', ['inbox.mail']],
            ['devices', 'devices.index', 'device-mobile', 'documents.upload', ['devices.*']],
            // Point 174: the phone test belongs to the phones that send documents, not to the updates.
            ['phone', 'phone-check.qr', 'stethoscope', null, ['phone-check*']],
        ], false],
        'log' => ['list-details', [
            ['log', 'log.index', 'list-details', 'settings.manage', ['log.index']],
            ['log_general', 'log.general', 'note', 'settings.manage', ['log.general']],
            ['log_ocr', 'log.ocr', 'text-scan-2', 'settings.manage', ['log.ocr']],
            ['log_updates', 'log.updates', 'cloud-download', 'settings.manage', ['log.updates']],
        ], false],
        'export' => ['file-export', [
            ['export', 'exports.form', 'file-export', 'exports.create', ['exports.*']],
            ['shares', 'shares.index', 'send', 'exports.create', ['shares.*']],
            // Point 211: the receipt pack (PDF), frozen and versioned.
            ['packs', 'packs.index', 'file-type-pdf', 'packs.view', ['packs.*']],
        ], false],
        // Point 164.7: measured server times, speed checks, self tests and options.
        'speed' => ['gauge', [
            ['speed', 'speed.index', 'gauge', 'settings.manage', ['speed.*']],
        ], true],
        // A347: one entry only, so the tile opens the Update page directly.
        'update' => ['cloud-download', [
            ['update', 'updates.show', 'cloud-download', 'system.update', ['updates.*']],
        ], true],
        // Point 206 (F23): "Backup herunterladen", owners and admins.
        'backup' => ['archive', [
            ['backup', 'backup.index', 'archive', 'backup.create', ['backup.*']],
        ], true],
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
                if (Route::has($route) && ($ability === null || Gate::allows($ability))) {
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

    /** Whether the current page is part of Einstellungen (the main menu keeps "Einstellungen" marked there). */
    public static function owns(Request $request): bool
    {
        return $request->routeIs('settings.*') || self::current($request)[0] !== null;
    }
}
