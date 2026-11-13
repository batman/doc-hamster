<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use App\Domain\Auth\User;
use App\Domain\Documents\Document;
use App\Domain\Documents\Folder;
use App\Domain\Documents\Tag;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Organization;
use App\Domain\Org\Permission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/** The documents a person may see and the shared rules of the document controllers. */
trait ScopesDocuments
{
    /**
     * @return Builder<Document>
     */
    private function visible(Request $request): Builder
    {
        return Document::query()->visibleTo($this->userId($request), Gate::allows(Permission::ViewAllDocuments->value));
    }

    private function organization(): ?Organization
    {
        $org = app(CurrentOrg::class);

        return $org->isSet() ? $org->organization() : null;
    }

    /**
     * Only existing tags of the active organisation (Tag is org-scoped).
     *
     * @return array<string, list<mixed>>
     */
    private static function tagRules(): array
    {
        return ['tags' => ['array'], 'tags.*' => [Rule::in(Tag::query()->pluck('id')->all())]];
    }

    /**
     * Only an existing folder of the active organisation (Folder is org-scoped); empty means "Belege".
     *
     * @return array<string, mixed>
     */
    private static function folderRule(): array
    {
        return ['folder' => ['nullable', 'string', Rule::in(Folder::query()->pluck('id')->all())]];
    }

    private function userId(Request $request): string
    {
        return $this->user($request)->id;
    }

    private function user(Request $request): User
    {
        $user = $request->user();

        abort_unless($user instanceof User, 403);

        return $user;
    }
}
