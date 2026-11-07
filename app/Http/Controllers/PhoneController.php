<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Domain\Audit\AuditLog;
use App\Domain\Auth\Device;
use App\Domain\Auth\DeviceLogin;
use App\Domain\Auth\DevicePairing;
use App\Domain\Auth\User;
use App\Domain\Org\CurrentOrg;
use App\Domain\Org\Role;
use BaconQrCode\Renderer\Image\SvgImageBackEnd;
use BaconQrCode\Renderer\ImageRenderer;
use BaconQrCode\Renderer\RendererStyle\RendererStyle;
use BaconQrCode\Writer;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;

/**
 * "Handy verbinden" (PLAN §11.1): a signed-in person shows a QR code and a
 * short code; the phone opens it once and from then on stays signed in with
 * its own device login. The person sees their connected phones and can
 * remove any of them.
 */
final class PhoneController extends Controller
{
    public function __construct(private readonly DeviceLogin $devices) {}

    public function index(Request $request, CurrentOrg $org): View
    {
        $userId = $org->membership()->user_id;
        $code = $request->session()->get('pairing_code');
        $link = is_string($code) ? route('phone.connect', ['code' => DevicePairing::format($code)]) : null;

        return view('phone.index', [
            'allowed' => $org->membership()->role !== Role::Accountant,
            'code' => is_string($code) ? DevicePairing::format($code) : null,
            'link' => $link,
            'qr' => $link !== null ? $this->qr($link) : null,
            'minutes' => DevicePairing::MINUTES,
            'devices' => Device::query()->where('user_id', $userId)->whereNull('revoked_at')->latest('last_used_at')->get(),
            'current' => $request->session()->get(DeviceLogin::SESSION_KEY),
        ]);
    }

    /** New code; the page shows it once (flash), reloading the page does not show it again. */
    public function start(Request $request, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        // Tax advisors sign in with username and password only, never with a device login (D-012).
        abort_if($org->membership()->role === Role::Accountant, 403);

        $userId = $org->membership()->user_id;
        [$pairing, $code] = DevicePairing::start($userId);
        $audit->record('device.pairing_started', orgId: $org->id(), userId: $userId, subject: $pairing, ip: $request->ip());

        return redirect()->route('phone.index')->with('pairing_code', $code);
    }

    public function revoke(Request $request, string $id, CurrentOrg $org, AuditLog $audit): RedirectResponse
    {
        $userId = $org->membership()->user_id;
        $device = Device::query()->where('user_id', $userId)->whereKey($id)->whereNull('revoked_at')->firstOrFail();
        $device->forceFill(['revoked_at' => now()])->save();
        $audit->record('device.revoked', orgId: $org->id(), userId: $userId, deviceId: $device->id, subject: $device, ip: $request->ip());

        return redirect()->route('phone.index')->with('status', __('phone.revoked', ['name' => $device->name]));
    }

    /**
     * The page the QR code opens, also reachable by typing the short code (the
     * home-screen app on iPhones has its own cookies, so connecting must work
     * inside it, B14). Opening the link does nothing yet: link previews in
     * messengers must not use the code up.
     */
    public function connectForm(?string $code = null): View
    {
        return view('phone.connect', ['code' => $code !== null ? DevicePairing::format($code) : '']);
    }

    public function connect(Request $request, AuditLog $audit): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20'],
            'name' => ['nullable', 'string', 'max:100'],
        ]);

        $pairing = DevicePairing::redeem($data['code']);
        $user = $pairing?->user;
        if ($pairing === null || ! $user instanceof User) {
            return back()->withErrors(['code' => __('phone.code_invalid')])->onlyInput('code', 'name');
        }

        $name = trim((string) ($data['name'] ?? ''));
        [$device, $token] = $this->devices->issue($user, $name !== '' ? $name : __('phone.default_name'), $request);
        $pairing->forceFill(['device_id' => $device->id])->save();

        if (Auth::check()) {
            Auth::logout();
        }
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put(DeviceLogin::SESSION_KEY, $device->id);
        $audit->record('device.paired', userId: $user->id, deviceId: $device->id, subject: $device, ip: $request->ip());

        return redirect()->route('phone.index')
            ->with('status', __('phone.connected'))
            ->withCookie($this->devices->cookie($request, $token));
    }

    private function qr(string $text): string
    {
        $svg = (new Writer(new ImageRenderer(new RendererStyle(280), new SvgImageBackEnd)))->writeString($text);

        return 'data:image/svg+xml;base64,'.base64_encode($svg);
    }
}
