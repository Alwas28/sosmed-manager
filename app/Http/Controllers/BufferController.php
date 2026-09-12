<?php

namespace App\Http\Controllers;

use App\Models\BufferConnection;
use App\Services\Buffer\BufferAuthService;
use App\Services\Buffer\BufferException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class BufferController extends Controller
{
    public function __construct(private BufferAuthService $auth) {}

    /**
     * Kick off the Buffer OAuth2 authorization.
     */
    public function connect(Request $request): RedirectResponse
    {
        abort_unless($this->auth->supportsOAuth(), 503, 'Client ID / Secret Buffer belum diatur di .env.');

        $state = Str::random(40);
        $verifier = $this->auth->generateCodeVerifier();

        $request->session()->put('buffer_oauth_state', $state);
        $request->session()->put('buffer_oauth_verifier', $verifier);

        return redirect()->away($this->auth->authorizeUrl($state, $verifier));
    }

    /**
     * Connect using the personal access token in .env (no OAuth redirect).
     */
    public function connectToken(Request $request): RedirectResponse
    {
        abort_unless($this->auth->supportsStaticToken(), 503, 'BUFFER_ACCESS_TOKEN belum diatur di .env.');

        try {
            $this->auth->connectWithStaticToken($request->user());
        } catch (BufferException $e) {
            return redirect()->route('buffer')->with('error', $e->getMessage());
        }

        return redirect()->route('buffer')->with('status', 'Buffer terhubung memakai access token.');
    }

    /**
     * OAuth2 redirect target — exchange the code and store the connection.
     */
    public function callback(Request $request): RedirectResponse
    {
        if ($request->filled('error')) {
            return redirect()->route('buffer')->with(
                'error',
                'Otorisasi Buffer dibatalkan: '.$request->input('error_description', $request->input('error')),
            );
        }

        $expectedState = (string) $request->session()->pull('buffer_oauth_state');
        $verifier = (string) $request->session()->pull('buffer_oauth_verifier');

        abort_unless(
            $request->filled('code')
                && $expectedState !== ''
                && $verifier !== ''
                && hash_equals($expectedState, (string) $request->input('state')),
            419,
            'Sesi otorisasi Buffer tidak valid. Silakan coba lagi.',
        );

        try {
            $this->auth->handleCallback((string) $request->input('code'), $verifier, $request->user());
        } catch (BufferException $e) {
            return redirect()->route('buffer')->with('error', $e->getMessage());
        }

        return redirect()->route('buffer')->with('status', 'Buffer berhasil terhubung.');
    }

    /**
     * Re-pull the connected social channels from Buffer.
     */
    public function sync(): RedirectResponse
    {
        $connection = BufferConnection::current();
        abort_unless($connection !== null, 404);

        try {
            $count = $this->auth->syncChannels($connection);
        } catch (BufferException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "Sinkronisasi selesai — {$count} channel diperbarui.");
    }

    public function disconnect(): RedirectResponse
    {
        $this->auth->disconnect();

        return redirect()->route('buffer')->with('status', 'Koneksi Buffer telah diputus.');
    }
}
