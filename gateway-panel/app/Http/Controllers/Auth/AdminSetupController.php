<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\HmacService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;

class AdminSetupController extends Controller
{
    public function __construct(private HmacService $hmacService) {}

    /**
     * Show the admin setup form (only accessible if no admin exists).
     * Route: GET /account/admin
     */
    public function show(): Response|\Illuminate\Http\RedirectResponse
    {
        if (User::exists()) {
            return redirect('/')->with('error', 'Admin already configured. Please login.');
        }

        return Inertia::render('Auth/AdminSetup');
    }

    /**
     * Create the first admin account.
     * Route: POST /account/admin
     */
    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        if (User::exists()) {
            return redirect('/')->with('error', 'Admin already configured.');
        }

        $validated = $request->validate([
            'name'     => 'required|string|max:255',
            'email'    => 'required|email|max:255',
            'password' => 'required|string|min:8|confirmed',
            'tenant_id' => 'required|string|alpha_dash|max:50|unique:users,tenant_id',
        ]);

        $user = User::create([
            'name'         => $validated['name'],
            'email'        => $validated['email'],
            'password'     => Hash::make($validated['password']),
            'tenant_id'    => strtolower($validated['tenant_id']),
            'token_secret' => $this->hmacService->generateToken(64),
        ]);

        // Also create a named gateway token for convenience
        $user->gatewayTokens()->create([
            'name'  => 'Default Token',
            'token' => $this->hmacService->generateToken(64),
        ]);

        Auth::login($user);

        return redirect('/dashboard')->with('success', 'Admin account created successfully.');
    }
}
