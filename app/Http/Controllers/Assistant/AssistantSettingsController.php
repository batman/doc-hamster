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

/**
 * Einstellungen → Assistenten (D-040, owner only): "Zugang für Assistenten" Aus | An, the address and the three
 * clicks in Claude, and every connected assistant of the organisation with Trennen. Closing disconnects them all.
 * Switching and disconnecting need a fresh confirmation (point 212).
 */
final class AssistantSettingsController
{
    public function show(CurrentOrg $org): View
    {
        return view('settings.assistant', [
            'on' => AssistantAccess::enabled($org->organization()),
            'mcpUrl' => route('mcp'),
            'connections' => AccessKey::query()->whereNotNull('client_id')->whereNull('revoked_at')->with('user')->latest('last_used_at')->get(),
        ]);
    }

    public function save(Request $request, CurrentOrg $org): JsonResponse|RedirectResponse
    {
        $on = (bool) $request->validate(['on' => ['required', 'boolean']])['on'];
        $organization = $org->organization();
        if ($on !== AssistantAccess::enabled($organization)) {
            AssistantAccess::save($organization, $on);
            LogEntry::write('general', 'event', (string) __($on ? 'assistant.log.on' : 'assistant.log.off'), 'assistant.switched', $organization->id, $org->membership()->user_id);
        }
        $message = __($on ? 'assistant.saved_on' : 'assistant.saved_off');

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
