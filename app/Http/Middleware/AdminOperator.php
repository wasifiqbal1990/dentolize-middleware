<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AdminOperator
{
    public function handle(Request $request, Closure $next): Response
    {
        $admin = $request->attributes->get('admin_user');

        if (! $admin || ! in_array($admin->role, ['operator', 'admin'], true)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
