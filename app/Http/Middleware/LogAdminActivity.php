<?php

namespace App\Http\Middleware;

use App\Models\ActivityLog;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class LogAdminActivity
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $actor = $request->user();
        $routeName = $request->route()?->getName();
        $method = $request->method();
        $response = $next($request);

        if (
            ! in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true)
            || $response->getStatusCode() >= 400
            || $request->session()->has('errors')
            || $request->session()->has('error')
        ) {
            return $response;
        }

        $actor ??= $routeName === 'login.store' ? $request->user() : null;

        if (! $actor) {
            return $response;
        }

        if (in_array($routeName, ['login.store', 'logout'], true)) {
            Log::info('Admin authentication activity', [
                'action' => $routeName === 'login.store' ? 'login' : 'logout',
                'user_id' => $actor->id,
                'name' => $actor->name,
                'business_unit' => $actor->business_unit,
            ]);

            return $response;
        }

        $targetType = null;
        $targetId = null;

        foreach ($request->route()?->parameters() ?? [] as $parameter) {
            if ($parameter instanceof Model) {
                $targetType = class_basename($parameter);
                $targetId = (string) $parameter->getKey();
                break;
            }
        }

        if ($routeName === 'admin.employees.attendance.correct') {
            $attendance = $request->route('attendance');
            if ($attendance instanceof Model) {
                $targetType = class_basename($attendance);
                $targetId = (string) $attendance->getKey();
            }
        }

        if ($targetType === null && $routeName === 'orders.store') {
            $targetType = 'Order';
            $targetId = $request->string('order_id')->toString();
        }

        $action = match ($routeName) {
            'orders.store' => 'Order dibuat',
            'orders.update' => 'Order diperbarui',
            'orders.production.update' => 'Status produksi diperbarui',
            'orders.destroy' => 'Order dihapus',
            'guests.update' => 'Data tamu diperbarui',
            'admin.employees.store' => 'Karyawan ditambahkan',
            'admin.employees.update' => 'Data karyawan diperbarui',
            'admin.employees.destroy' => 'Karyawan dihapus',
            'admin.employees.attendance' => 'Absensi dicatat',
            'admin.employees.attendance.correct' => 'Absensi dikoreksi',
            'admin.employees.overtime' => 'Lembur diperbarui',
            'admin.users.store' => 'Akun admin dibuat',
            'admin.users.update' => 'Akun admin diperbarui',
            'admin.users.destroy' => 'Akun admin dihapus',
            default => ucfirst(str_replace(['.', '_'], ' ', $routeName ?? strtolower($method))),
        };
        $description = $action.($targetType ? " · {$targetType} #{$targetId}" : '');

        if ($routeName === 'orders.store' && $targetId !== '') {
            $description = "{$action} · {$targetId}";
        }

        ActivityLog::create([
            'user_id' => $actor->id,
            'actor_name' => $actor->name,
            'business_unit' => $actor->business_unit,
            'action' => $action,
            'route_name' => $routeName,
            'method' => $method,
            'target_type' => $targetType,
            'target_id' => $targetId,
            'description' => $description,
            'occurred_at' => now(),
        ]);

        return $response;
    }
}
