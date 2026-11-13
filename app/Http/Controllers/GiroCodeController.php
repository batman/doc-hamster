<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Auth\User;
use App\Domain\Documents\Document;
use App\Domain\Org\Permission;
use App\Domain\Payments\GiroCode;
use App\Domain\Payments\GiroCodeCard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;

/**
 * The GiroCode card of a document (point 120): the card again (it reloads itself on properties:changed) and the QR
 * code as SVG. The SVG takes the card's current fields as query (live preview while typing, debounced in
 * girocode.js) – they are only drawn, never stored; edits are saved through the property routes.
 */
final class GiroCodeController
{
    public function card(Request $request, string $id): JsonResponse
    {
        $document = $this->document($request, $id);

        return response()->json(['html' => view('documents._girocode', ['document' => $document])->render()]);
    }

    public function svg(Request $request, string $id, GiroCodeCard $card): Response
    {
        $document = $this->document($request, $id);
        $over = [];
        foreach (['name' => 70, 'iban' => 42, 'bic' => 11, 'amount' => 30, 'ref' => 140] as $field => $max) {
            $value = $request->query($field);
            if (is_string($value)) {
                $over[$field] = mb_substr($value, 0, $max);
            }
        }
        $payload = $card->payload($document, $over, $request->boolean('skonto'));
        if ($payload === null) {
            return response('', 422, ['Cache-Control' => 'no-store']);
        }

        return response(GiroCode::svg($payload), 200, [
            'Content-Type' => 'image/svg+xml',
            'Cache-Control' => 'private, no-store',
        ]);
    }

    private function document(Request $request, string $id): Document
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return Document::query()->visibleTo($user->id, Gate::allows(Permission::ViewAllDocuments->value))->whereKey($id)->firstOrFail();
    }
}
