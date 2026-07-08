<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAccessLog;
use App\Models\AdminUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function showLogin(): View
    {
        return view('admin.login');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate([
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $admin = AdminUser::query()->where('email', $credentials['email'])->first();

        if (! $admin || ! Hash::check($credentials['password'], $admin->password_hash)) {
            return back()->withErrors(['email' => 'Invalid email or password.']);
        }

        $request->session()->put('admin_user_id', $admin->id);
        $admin->update(['last_login_at' => now()]);

        AdminAccessLog::query()->create([
            'admin_user_id' => $admin->id,
            'action' => 'login',
            'ip' => $request->ip(),
        ]);

        return redirect('/admin');
    }

    public function logout(Request $request): RedirectResponse
    {
        $request->session()->forget('admin_user_id');

        return redirect('/admin/login');
    }
}
