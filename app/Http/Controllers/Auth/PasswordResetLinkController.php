<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;

class PasswordResetLinkController extends Controller
{
    public function create(Request $request): Response
    {
        return Inertia::render('Auth/ForgotPassword', ['status' => $request->session()->get('status')]);
    }

    public function store(Request $request): RedirectResponse
    {
        $email = mb_strtolower(trim((string) $request->input('email')));
        validator(['email' => $email], ['email' => ['required', 'email', 'max:255']])->validate();
        Password::sendResetLink(['email' => $email]);

        return back()->with('status', 'If this work email is registered, a password reset link will be sent shortly.');
    }
}
