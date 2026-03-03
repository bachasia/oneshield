<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class LoginController extends Controller
{
    /**
     * Show login form.
     * Route: GET /
     */
    public function show(): Response|\Illuminate\Http\RedirectResponse
    {
        if (!User::exists()) {
            return redirect('/account/admin');
        }

        if (Auth::check()) {
            return redirect(Auth::user()->is_super_admin ? '/admin' : '/dashboard');
        }

        return Inertia::render('Auth/Login');
    }

    /**
     * Handle login submission.
     * Route: POST /login
     */
    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $credentials = $request->validate([
            'email'    => 'required|email',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'Invalid credentials.']);
        }

        $request->session()->regenerate();

        return redirect($request->user()->is_super_admin ? '/admin' : '/dashboard');
    }

    /**
     * Logout.
     * Route: POST /logout
     */
    public function destroy(Request $request): \Illuminate\Http\RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
