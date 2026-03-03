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
        ]);

        $user = User::create([
            'name'           => $validated['name'],
            'email'          => $validated['email'],
            'password'       => Hash::make($validated['password']),
            'tenant_id'      => 'admin',
            'token_secret'   => $this->hmacService->generateToken(64),
            'is_super_admin' => true,
        ]);

        Auth::login($user);

        return redirect('/admin')->with('success', 'Super admin account created successfully.');
    }
}
