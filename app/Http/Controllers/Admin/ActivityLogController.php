<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ActivityLogController extends Controller
{
    public function index(Request $request): View
    {
        $timezone = $request->user()->timezone();
        $validated = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'business_unit' => ['nullable', Rule::in([User::UNIT_BALI, User::UNIT_FACTORY])],
            'action' => ['nullable', 'string', 'max:120'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
        ]);

        $query = ActivityLog::query()
            ->with('user:id,name,email')
            ->when(
                isset($validated['user_id']),
                fn ($query) => $query->where('user_id', $validated['user_id']),
            )
            ->when(
                isset($validated['business_unit']),
                fn ($query) => $query->where('business_unit', $validated['business_unit']),
            )
            ->when(
                isset($validated['action']),
                fn ($query) => $query->where('action', $validated['action']),
            )
            ->when(isset($validated['from']), function ($query) use ($validated, $timezone): void {
                $from = Carbon::createFromFormat('Y-m-d', $validated['from'], $timezone)
                    ->startOfDay()
                    ->setTimezone(config('app.timezone'));
                $query->where('occurred_at', '>=', $from);
            })
            ->when(isset($validated['to']), function ($query) use ($validated, $timezone): void {
                $to = Carbon::createFromFormat('Y-m-d', $validated['to'], $timezone)
                    ->endOfDay()
                    ->setTimezone(config('app.timezone'));
                $query->where('occurred_at', '<=', $to);
            })
            ->latest('occurred_at');
        $activities = $query->paginate(30)->withQueryString();
        $groupedActivities = [];
        $consecutiveActivities = [];
        $flushActivities = function () use (&$consecutiveActivities, &$groupedActivities): void {
            if (count($consecutiveActivities) >= 3) {
                $groupedActivities[] = [
                    'activity' => $consecutiveActivities[0],
                    'entries' => $consecutiveActivities,
                ];
            } else {
                foreach ($consecutiveActivities as $activity) {
                    $groupedActivities[] = [
                        'activity' => $activity,
                        'entries' => [],
                    ];
                }
            }

            $consecutiveActivities = [];
        };

        foreach ($activities as $activity) {
            $previous = $consecutiveActivities[0] ?? null;
            if ($previous && ($previous->user_id !== $activity->user_id || $previous->action !== $activity->action)) {
                $flushActivities();
            }

            $consecutiveActivities[] = $activity;
        }
        $flushActivities();

        $admins = User::query()
            ->where('is_admin', true)
            ->where('role', User::ROLE_ADMIN)
            ->orderBy('name')
            ->get(['id', 'name', 'business_unit']);
        $actions = ActivityLog::query()
            ->select('action')
            ->distinct()
            ->orderBy('action')
            ->pluck('action');

        return view('admin.activity-log.index', [
            'activities' => $activities,
            'admins' => $admins,
            'actions' => $actions,
            'filters' => $validated,
            'groupedActivities' => $groupedActivities,
            'timezone' => $timezone,
        ]);
    }
}
