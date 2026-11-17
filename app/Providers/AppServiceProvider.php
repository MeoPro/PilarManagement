<?php

namespace App\Providers;

use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\View as ViewInstance;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        View::composer('layouts.admin', function (ViewInstance $view): void {
            $user = auth()->user();

            $view->with(
                'unreadKanbanTaskCount',
                $user?->is_admin
                    ? $user->kanbanTasks()->whereNull('read_at')->count()
                    : 0,
            );
        });
    }
}
