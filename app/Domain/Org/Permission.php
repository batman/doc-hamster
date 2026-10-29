<?php

declare(strict_types=1);

namespace App\Domain\Org;

/**
 * The rows of the permission matrix (PLAN §2.1). The values are the Gate
 * ability names, e.g. `Gate::allows('members.manage')`.
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
    case ManageMembers = 'members.manage';
    case ManageSettings = 'settings.manage';
    case ViewAuditLog = 'audit.view';

    /**
     * Organisation setting (key in `organizations.settings_json`) behind the
     * ⚙️ cells, with its default.
     *
     * @return array{0: string, 1: bool}|null
     */
    public function orgSetting(): ?array
    {
        return match ($this) {
            self::ViewAllDocuments => ['members_see_all_documents', false],
            self::TrashDocuments => ['members_may_trash_own_documents', true],
            default => null,
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
