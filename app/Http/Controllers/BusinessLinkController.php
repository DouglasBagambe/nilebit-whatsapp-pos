<?php

namespace App\Http\Controllers;

use App\Services\BusinessLink;
use App\Services\PosClient;
use App\Services\WhatsAppConversation;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class BusinessLinkController extends Controller
{
    private function sessionKey(string $token): string
    {
        return 'business_link.'.hash('sha256', $token);
    }

    public function show(Request $request, BusinessLink $links, string $token): Response
    {
        $link = $links->find($token);
        $sender = DB::table('wa_conversations')->where('id', $link->conversation_id)->value('sender');
        $maskedPhone = '••••'.substr($sender, -4);
        $stored = $request->session()->get($this->sessionKey($token));
        $candidate = $stored ? json_decode(Crypt::decryptString($stored), true, flags: JSON_THROW_ON_ERROR) : null;
        $backends = collect(config('whatsapp.backends'))->filter(fn ($backend) => ! empty($backend['url']))->keys();

        return response()->view('business-link', ['token' => $token, 'candidate' => $candidate, 'backends' => $backends, 'maskedPhone' => $maskedPhone, 'expiresAt' => Carbon::parse($link->expires_at)])
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function authenticate(Request $request, BusinessLink $links, PosClient $pos, string $token): RedirectResponse
    {
        $links->find($token);
        $data = $request->validate(['backend' => ['required', Rule::in(['retail', 'production'])], 'email' => ['required', 'email', 'max:255'], 'password' => ['required', 'string', 'max:1000']]);
        try {
            $candidate = $pos->login($data['backend'], $data['email'], $data['password']);
        } catch (ConnectionException|RequestException) {
            return back()->withErrors(['email' => 'Your POS is taking too long to respond or is temporarily unavailable. Please try again shortly.'])
                ->withInput($request->only('backend', 'email'));
        }
        $request->session()->regenerate();
        $request->session()->put($this->sessionKey($token), Crypt::encryptString(json_encode($candidate + ['backend' => $data['backend']], JSON_THROW_ON_ERROR)));

        return to_route('business-link.show', ['token' => $token]);
    }

    public function approve(Request $request, BusinessLink $links, WhatsAppConversation $bot, string $token): Response|RedirectResponse
    {
        $links->find($token);
        $data = $request->validate(['shops' => ['required', 'array', 'min:1', 'max:50'], 'shops.*' => ['required', 'integer', 'distinct']]);
        $stored = $request->session()->get($this->sessionKey($token));
        abort_unless($stored, 403);
        $candidate = json_decode(Crypt::decryptString($stored), true, flags: JSON_THROW_ON_ERROR);
        $allowed = array_column($candidate['shops'], 'id');
        abort_if(array_diff($data['shops'], $allowed), 403);
        try {
            DB::transaction(function () use ($links, $token, $candidate, $data, $bot): void {
                $link = $links->find($token, lock: true);
                foreach ($data['shops'] as $shopId) {
                    $bot->link($link->conversation_id, $candidate['backend'], $candidate['token'], (int) $shopId);
                }
                DB::table('wa_link_requests')->where('id', $link->id)->update(['consumed_at' => now(), 'updated_at' => now()]);
            });
        } catch (ConnectionException) {
            return back()->withErrors(['shops' => 'The POS could not be reached. Nothing was linked. Please try again.']);
        }
        $request->session()->forget($this->sessionKey($token));

        return response()->view('business-link-done')->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
