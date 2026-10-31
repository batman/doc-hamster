<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Documents\Tag;
use App\Domain\Org\CurrentOrg;
use Closure;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * The tag list of an organisation (#32), for Admins and the checkbox "Tags
 * bearbeiten". Deleting a tag keeps the receipts; if it is in use, the count
 * is named first and has to be confirmed.
 */
final class TagController
{
    public function index(): View
    {
        return view('tags.index', ['tags' => Tag::query()->withCount('documents')->orderBy('name')->get()]);
    }

    public function store(Request $request, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $data = $request->validate(['name' => $this->nameRule()]);

        $tag = Tag::query()->create(['name' => $data['name']]);
        $this->log($audit, $request, $org, 'tag.created', $tag);

        return redirect()->route('tags.index')->with('status', __('tags.saved'));
    }

    public function update(Request $request, string $id, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $tag = Tag::query()->whereKey($id)->firstOrFail();
        $data = $request->validate(['name' => $this->nameRule($tag)]);

        $tag->update(['name' => $data['name']]);
        $this->log($audit, $request, $org, 'tag.renamed', $tag);

        return redirect()->route('tags.index')->with('status', __('tags.saved'));
    }

    public function destroy(Request $request, string $id, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $tag = Tag::query()->withCount('documents')->whereKey($id)->firstOrFail();
        $count = (int) $tag->getAttribute('documents_count');

        if ($count > 0 && ! $request->boolean('confirmed')) {
            return redirect()->route('tags.index')->with('confirm_delete', ['id' => $tag->id, 'name' => $tag->name, 'count' => $count]);
        }

        $this->log($audit, $request, $org, 'tag.deleted', $tag, ['documents' => $count]);
        $tag->delete();

        return redirect()->route('tags.index')->with('status', __('tags.deleted'));
    }

    /**
     * @return list<mixed>
     */
    private function nameRule(?Tag $tag = null): array
    {
        return ['required', 'string', 'max:64', static function (string $attribute, mixed $value, Closure $fail) use ($tag): void {
            // In PHP, so the check doesn't depend on the database collation.
            $taken = Tag::query()->whereKeyNot($tag?->id)->pluck('name')
                ->map(static fn (string $name): string => mb_strtolower($name));

            if (is_string($value) && $taken->contains(mb_strtolower($value))) {
                $fail(__('tags.name_taken'));
            }
        }];
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    private function log(AuditLog $audit, Request $request, CurrentOrg $org, string $action, Tag $tag, array $meta = []): void
    {
        $audit->record($action, orgId: $org->id(), userId: $org->membership()->user_id, subject: $tag, meta: ['name' => $tag->name, ...$meta], ip: $request->ip());
    }
}
