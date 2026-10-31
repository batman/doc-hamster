<?php

declare(strict_types=1);

namespace App\Domain\Org;

/**
 * What the app checks. The values are the Gate ability names, e.g.
 * `Gate::allows('members.manage')`. People see the plain checkboxes of
 * RoleRight instead (#30).
 */
enum Permission: string
{
    case UploadDocuments = 'documents.upload';
    case ManageOwnDocuments = 'documents.manage_own';
    case ViewAllDocuments = 'documents.view_all';
    case EditDocuments = 'documents.edit';
    case TrashDocuments = 'documents.trash';
    case DeleteDocumentsForever = 'documents.delete_forever';
    case ImportBanking = 'banking.import';
    case CreateReports = 'reports.create';
    case Export = 'exports.create';
    case ManageTags = 'tags.manage';
    case ManageMembers = 'members.manage';
    case ManageSettings = 'settings.manage';
    case ViewAuditLog = 'audit.view';

    /**
     * The checkbox that grants this permission; null means Admins only.
     * Editing and trashing act on what the person can see, so resource checks
     * stay limited by Document::visibleTo().
     */
    public function right(): ?RoleRight
    {
        return match ($this) {
            self::UploadDocuments, self::ManageOwnDocuments => RoleRight::Upload,
            self::ViewAllDocuments => RoleRight::ViewAll,
            self::EditDocuments => RoleRight::Edit,
            self::TrashDocuments => RoleRight::Delete,
            self::ImportBanking => RoleRight::Banking,
            self::CreateReports, self::Export => RoleRight::Export,
            self::ManageTags => RoleRight::Tags,
            self::DeleteDocumentsForever, self::ManageMembers, self::ManageSettings, self::ViewAuditLog => null,
        };
    }

    /**
     * "mit Bestätigung" in the matrix: needs a fresh step-up (PLAN §11.1, P1-5).
     */
    public function needsStepUp(): bool
    {
        return in_array($this, [self::DeleteDocumentsForever, self::Export, self::ManageMembers], true);
    }
}
