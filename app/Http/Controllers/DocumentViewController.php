<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Properties\Property;
use App\Http\Controllers\Concerns\ScopesDocuments;
use App\Http\Documents\DocumentListing;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** How a person sees the documents windows: the view, the date basis (points 11, 52, 98, 108) and the columns (A244). */
final class DocumentViewController
{
    use ScopesDocuments;

    /**
     * The chosen view is the default for new windows (point 11); the window it was chosen in shows it via
     * ?view=… (point 52). `back` is that window's address (only this app's own addresses count).
     */
    public function setView(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'view' => ['required_without:date', Rule::in(array_keys(DocumentListing::VIEWS))],
            'date' => ['required_without:view', Rule::in(DocumentListing::DATE_BASES)],
            'back' => ['nullable', 'string', 'max:2000'],
        ]);
        $key = isset($data['view']) ? 'view' : 'date';
        $value = (string) $data[$key];

        // A display mode of the folder view is also remembered for "Ordneransicht" (point 98); the date basis of
        // timeline and calendar likewise (point 108).
        $this->user($request)->forceFill(match (true) {
            $key === 'date' => ['receipt_dates' => $value],
            in_array($value, DocumentListing::FOLDER_VIEWS, true) => ['receipt_view' => $value, 'folder_view' => $value],
            default => ['receipt_view' => $value],
        })->save();

        $root = url('/');
        $back = is_string($data['back'] ?? null) && str_starts_with($data['back'].'/', $root.'/') ? $data['back'] : url()->previous(route('documents.index'));
        if (! str_starts_with($back.'/', $root.'/')) {
            $back = route('documents.index');
        }
        parse_str((string) parse_url($back, PHP_URL_QUERY), $query);
        unset($query['page']);
        $query[$key] = $value;

        return redirect()->to(explode('?', $back, 2)[0].'?'.http_build_query($query));
    }

    /** A244: switches one property column of the details view on or off for this person. */
    public function setColumns(Request $request): RedirectResponse|JsonResponse
    {
        $data = $request->validate(['toggle' => ['required', 'string', Rule::in(Property::query()->pluck('id')->all())]]);
        $user = $this->user($request);
        $columns = is_array($user->document_columns) ? $user->document_columns : [];
        $columns = in_array($data['toggle'], $columns, true)
            ? array_values(array_diff($columns, [$data['toggle']]))
            : [...$columns, $data['toggle']];
        $user->forceFill(['document_columns' => $columns])->save();

        return $request->wantsJson() ? response()->json(['columns' => $columns]) : back();
    }
}
