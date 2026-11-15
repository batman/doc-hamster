<?php

declare(strict_types=1);

use App\Domain\Org\Permission;
use App\Domain\Org\RoleRight;

/*
| #30: which checkbox grants which permission. null = Admins only, no checkbox.
*/
dataset('rights', [
    'Beleg hochladen' => [Permission::UploadDocuments, RoleRight::Upload],
    'Eigene Belege verwalten' => [Permission::ManageOwnDocuments, RoleRight::Upload],
    'Alle Belege sehen' => [Permission::ViewAllDocuments, RoleRight::ViewAll],
    'Belege bearbeiten' => [Permission::EditDocuments, RoleRight::Edit],
    'Beleg löschen (Papierkorb)' => [Permission::TrashDocuments, RoleRight::Delete],
    'Endgültig löschen' => [Permission::DeleteDocumentsForever, null],
    'Bankdaten' => [Permission::ImportBanking, RoleRight::Banking],
    'Berichte' => [Permission::CreateReports, RoleRight::Export],
    'Export' => [Permission::Export, RoleRight::Export],
    'Tags' => [Permission::ManageTags, RoleRight::Tags],
    'Notizen' => [Permission::ShareNotes, RoleRight::Notes],
    'Personen und Rollen' => [Permission::ManageMembers, null],
    'Einstellungen' => [Permission::ManageSettings, null],
    'System aktualisieren' => [Permission::UpdateSystem, RoleRight::Update],
    'Vorschau-Branches' => [Permission::InstallPreviews, RoleRight::UpdatePreview],
    'Audit-Log' => [Permission::ViewAuditLog, null],
    'Backup' => [Permission::CreateBackup, null],
    'Belegpakete' => [Permission::ViewPacks, RoleRight::Packs],
]);

it('maps every permission to its checkbox', function (Permission $permission, ?RoleRight $right): void {
    expect($permission->right())->toBe($right);
})->with('rights');

it('covers every permission', function (): void {
    expect(Permission::cases())->toHaveCount(18);
});

it('has the agreed defaults for the roles of a new organisation', function (): void {
    expect(RoleRight::defaults())->toBe([RoleRight::Upload, RoleRight::ViewAll, RoleRight::Edit, RoleRight::Delete, RoleRight::Banking, RoleRight::Export, RoleRight::Packs, RoleRight::Tags, RoleRight::Notes])
        ->and(RoleRight::advisor())->toBe([RoleRight::ViewAll, RoleRight::Packs]);
});

it('requires a step-up exactly where the matrix says "mit Bestätigung"', function (): void {
    $stepUp = array_values(array_filter(Permission::cases(), fn (Permission $p): bool => $p->needsStepUp()));

    expect($stepUp)->toBe([Permission::DeleteDocumentsForever, Permission::Export, Permission::ManageMembers, Permission::CreateBackup]);
});
