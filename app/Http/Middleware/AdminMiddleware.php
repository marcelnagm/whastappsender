<?php 
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AdminMiddleware
{
    public function handle(Request $request, Closure $next)
    {
        // Verifica se está logado E se o campo 'role' no banco é 'admin'
        if (Auth::check() && Auth::user()->role === 'admin') {
            return $next($request);
        }

        // Se não for admin, chuta para a home ou login com erro
        return redirect('/')->with('error', 'Access denied. Administrators only.');
    }
}