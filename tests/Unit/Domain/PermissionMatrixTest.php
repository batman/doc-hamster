<?php

declare(strict_types=1);

use App\Domain\Org\Access;
use App\Domain\Org\Permission;
use App\Domain\Org\Role;

/*
| PLAN §2.1, row by row: [permission, owner, member, tax advisor].
| ✅ Allow · ❌ Deny · ⚙️ OrgSetting · "eigene" OwnOnly · "eigene, ⚙️"
| OwnOnlyIfOrgSetting · 🔶 ShareOnly. Change this table only together with PLAN §2.1.
*/
dataset('matrix', [
    'Beleg hochladen' => [Permission::UploadDocuments, Access::Allow, Access::Allow, Access::Deny],
    'Eigene Belege sehen/bearbeiten' => [Permission::ManageOwnDocuments, Access::Allow, Access::Allow, Access::Deny],
    'Alle Belege der Organisation sehen' => [Permission::ViewAllDocuments, Access::Allow, Access::OrgSetting, Access::ShareOnly],
    'Belege bearbeiten' => [Permission::EditDocuments, Access::Allow, Access::OwnOnly, Access::Deny],
    'Beleg löschen (Papierkorb)' => [Permission::TrashDocuments, Access::Allow, Access::OwnOnlyIfOrgSetting, Access::Deny],
    'Endgültig löschen' => [Permission::DeleteDocumentsForever, Access::Allow, Access::Deny, Access::Deny],
    'Kontoauszug importieren / Abgleich' => [Permission::ImportBanking, Access::Allow, Access::Deny, Access::Deny],
    'Berichte erstellen' => [Permission::CreateReports, Access::Allow, Access::Deny, Access::ShareOnly],
    'Export' => [Permission::Export, Access::Allow, Access::Deny, Access::ShareOnly],
    'Mitglieder/Zugänge verwalten' => [Permission::ManageMembers, Access::Allow, Access::Deny, Access::Deny],
    'Kategorien/Regeln verwalten' => [Permission::ManageSettings, Access::Allow, Access::Deny, Access::Deny],
    'Audit-Log sehen' => [Permission::ViewAuditLog, Access::Allow, Access::Deny, Access::Deny],
]);

it('matches the permission matrix of PLAN §2.1', function (Permission $permission, Access $owner, Access $member, Access $accountant): void {
    expect(Role::Owner->access($permission))->toBe($owner)
        ->and(Role::Member->access($permission))->toBe($member)
        ->and(Role::Accountant->access($permission))->toBe($accountant);
})->with('matrix');

it('covers every permission in the matrix', function (): void {
    expect(Permission::cases())->toHaveCount(12);
});

it('requires a step-up exactly where the matrix says "mit Bestätigung"', function (): void {
    $stepUp = array_values(array_filter(Permission::cases(), fn (Permission $p): bool => $p->needsStepUp()));

    expect($stepUp)->toBe([Permission::DeleteDocumentsForever, Permission::Export, Permission::ManageMembers]);
});
