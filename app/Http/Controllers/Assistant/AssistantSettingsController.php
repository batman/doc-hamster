<?php

declare(strict_types=1);

namespace App\Http\Controllers\Assistant;

use App\Domain\Api\AccessKey;
use App\Domain\Assistant\AssistantAccess;
use App\Domain\Audit\AuditLog;
use App\Domain\Log\LogEntry;
use App\Domain\Org\CurrentOrg;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Einstellungen → Assistenten (D-040, owner only): "Zugang für Assistenten" Aus | An, the address and the three
 * clicks in Claude, and every connected assistant of the organisation with Trennen. Closing disconnects them all.
 * Switching and disconnecting need a fresh confirmation (point 212).
 */
final class AssistantSettingsController
{
    public function show(CurrentOrg $org): View|RedirectResponse
    {
        // A57: staff with "Vorschläge bearbeiten" only – the door stays the owner's, they get the Vorschläge.
        if (! Gate::allows('assistant.manage')) {
            return redirect()->route('proposals.index');
        }

        return view('settings.assistant', [
            'on' => AssistantAccess::enabled($org->organization()),
            'rights' => AssistantAccess::rights($org->organization()),
            'mcpUrl' => route('mcp'),
            'connections' => AccessKey::query()->whereNotNull('client_id')->whereNull('revoked_at')->with('user')->latest('last_used_at')->get(),
        ]);
    }

    public function save(Request $request, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $data = $request->validate(['on' => ['required', 'boolean'], 'rights' => ['nullable', 'array'], 'rights.*' => ['string', 'in:'.implode(',', AssistantAccess::RIGHTS)]]);
        $organization = $org->organization();
        $wasOn = AssistantAccess::enabled($organization);
        $rights = array_values($data['rights'] ?? []);
        AssistantAccess::save($organization, (bool) $data['on'], $rights);
        $on = AssistantAccess::enabled($organization);
        if ($on !== $wasOn) {
            LogEntry::write('general', 'event', (string) __($on ? 'assistant.log.on' : 'assistant.log.off'), 'assistant.switched', $organization->id, $org->membership()->user_id);
        }
        $message = match (true) {
            (bool) $data['on'] && $rights === [] => __('assistant.rights_first'),
            default => __($on ? 'assistant.saved_on' : 'assistant.saved_off'),
        };

        // The page shows more (or less) once the door opens or closes: the module reloads its window.
        return $request->expectsJson() ? response()->json(['message' => $message, 'reload' => true]) : redirect()->route('assistant.show')->with('status', $message);
    }

    public function disconnect(Request $request, string $id, AuditLog $audit, CurrentOrg $org): RedirectResponse
    {
        $access = AccessKey::query()->whereNotNull('client_id')->whereNull('revoked_at')->whereKey($id)->firstOrFail();
        $access->update(['revoked_at' => now()]);
        $audit->record('assistant.disconnected', orgId: $org->id(), userId: $org->membership()->user_id, subject: $access, ip: $request->ip());

        return redirect()->route('assistant.show')->with('status', __('assistant.disconnected', ['name' => $access->name]));
    }
}
