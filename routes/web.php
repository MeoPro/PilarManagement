<?php

use App\Http\Controllers\Admin\ActivityLogController;
use App\Http\Controllers\Admin\AdminUserController;
use App\Http\Controllers\Admin\AttendanceScheduleController;
use App\Http\Controllers\Admin\CashbondAdvanceController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\EmployeeController;
use App\Http\Controllers\Admin\LoginController;
use App\Http\Controllers\Admin\PayrollController;
use App\Http\Controllers\Admin\PerformanceController;
use App\Http\Controllers\Admin\PieceworkProductionController;
use App\Http\Controllers\Admin\UserMenuPreferenceController;
use App\Http\Controllers\CalendarController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\ColorController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\FinishingAssignmentController;
use App\Http\Controllers\GuestController;
use App\Http\Controllers\KanbanController;
use App\Http\Controllers\KanbanTaskController;
use App\Http\Controllers\MaterialController;
use App\Http\Controllers\OrderCommentController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OrderPaymentController;
use App\Http\Controllers\OrderProductController;
use App\Http\Controllers\PieceworkAssignmentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\RevenueController;
use App\Http\Controllers\SubcontractorAssignmentController;
use App\Http\Controllers\SubcontractorController;
use App\Http\Controllers\SubcontractorProductController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [LoginController::class, 'create'])->name('login');
    Route::post('/login', [LoginController::class, 'store'])
        ->middleware(['throttle:5,1', 'activity'])
        ->name('login.store');
});

Route::middleware(['auth', 'admin', 'activity'])->group(function () {
    Route::post('/logout', [LoginController::class, 'destroy'])->name('logout');
    Route::get('/admin/preferences/sidebar', [UserMenuPreferenceController::class, 'editSidebar'])
        ->name('admin.sidebar-preferences.edit');
    Route::put('/admin/preferences/sidebar', [UserMenuPreferenceController::class, 'updateSidebar'])
        ->name('admin.sidebar-preferences.update');
    Route::put('/admin/preferences/pinned-menu', [UserMenuPreferenceController::class, 'update'])
        ->name('admin.menu-preferences.update');
    Route::put('/admin/preferences/timezone', [UserMenuPreferenceController::class, 'updateTimezone'])
        ->name('admin.timezone-preferences.update');
    Route::put('/admin/preferences/business-unit', [UserMenuPreferenceController::class, 'updateBusinessUnit'])
        ->name('admin.business-unit-preferences.update');

    Route::get('/calendar', [CalendarController::class, 'index'])
        ->middleware('menu:calendar')
        ->name('calendar.index');
    Route::get('/order-products', [OrderProductController::class, 'index'])
        ->middleware('menu:order_products')
        ->name('order-products.index');
    Route::patch('/order-products/{orderItem}', [OrderProductController::class, 'update'])
        ->middleware('menu:order_products')
        ->whereNumber('orderItem')
        ->name('order-products.update');
    Route::patch('/order-products/{orderItem}/factory-drawing', [OrderProductController::class, 'updateFactoryDrawing'])
        ->middleware('menu:order_products')
        ->whereNumber('orderItem')
        ->name('order-products.factory-drawing.update');
    Route::post('/order-products/{orderItem}/quality', [OrderProductController::class, 'recordQuality'])
        ->middleware('menu:order_products')
        ->whereNumber('orderItem')
        ->name('order-products.quality.store');
    Route::post('/order-item-services/{orderItemService}/result', [OrderProductController::class, 'recordServiceResult'])
        ->middleware('menu:order_products')
        ->whereNumber('orderItemService')
        ->name('order-item-services.result.store');
    Route::post('/order-items/{orderItem}/piecework-assignments', [PieceworkAssignmentController::class, 'store'])
        ->middleware('menu:order_products')
        ->whereNumber('orderItem')
        ->name('order-items.piecework-assignments.store');
    Route::patch('/piecework-assignments/{pieceworkAssignment}', [PieceworkAssignmentController::class, 'update'])
        ->middleware('menu:order_products')
        ->whereNumber('pieceworkAssignment')
        ->name('piecework-assignments.update');
    Route::patch('/piecework-assignments/{pieceworkAssignment}/complete', [PieceworkAssignmentController::class, 'complete'])
        ->middleware('menu:order_products')
        ->whereNumber('pieceworkAssignment')
        ->name('piecework-assignments.complete');
    Route::post('/order-products/{orderItem}/subcontractor-assignments', [SubcontractorAssignmentController::class, 'store'])
        ->middleware(['menu:order_products', 'menu:subcontractors'])
        ->whereNumber('orderItem')
        ->name('order-products.subcontractor-assignments.store');
    Route::post('/orders/{order}/subcontractor-assignments', [SubcontractorAssignmentController::class, 'storeOrder'])
        ->middleware(['menu:orders', 'menu:subcontractors'])
        ->whereNumber('order')
        ->name('orders.subcontractor-assignments.store');
    Route::patch('/subcontractor-assignments/{subcontractorAssignment}', [SubcontractorAssignmentController::class, 'update'])
        ->middleware('menu:subcontractors')
        ->whereNumber('subcontractorAssignment')
        ->name('subcontractor-assignments.update');
    Route::patch('/subcontractor-assignments/{subcontractorAssignment}/cancel', [SubcontractorAssignmentController::class, 'cancel'])
        ->middleware('menu:subcontractors')
        ->whereNumber('subcontractorAssignment')
        ->name('subcontractor-assignments.cancel');
    Route::get('/order-comments/reminders', [OrderCommentController::class, 'reminders'])
        ->middleware('menu:orders')
        ->name('order-comments.reminders');
    Route::post('/orders/{order}/comments', [OrderCommentController::class, 'store'])
        ->middleware('menu:orders')
        ->whereNumber('order')
        ->name('orders.comments.store');
    Route::patch('/orders/{order}/comments/{comment}', [OrderCommentController::class, 'update'])
        ->middleware('menu:orders')
        ->whereNumber('order')
        ->whereNumber('comment')
        ->scopeBindings()
        ->name('orders.comments.update');

    Route::get('/', function () {
        return redirect()->route('admin.index');
    });

    Route::get('/admin', [DashboardController::class, 'index'])->name('admin.index');
    Route::get('/kanban', [KanbanController::class, 'index'])->name('kanban.index');
    Route::post('/kanban/tasks', [KanbanTaskController::class, 'store'])->name('kanban.tasks.store');
    Route::post('/kanban/attendance/checkout', [KanbanTaskController::class, 'checkout'])
        ->name('kanban.attendance.checkout');
    Route::post('/kanban/attendance/{session}/photos', [KanbanTaskController::class, 'storeSessionPhotos'])
        ->where('session', 'morning|afternoon')
        ->name('kanban.attendance.session-photos.store');
    Route::get('/kanban/attendance/{attendance}/photos/{session}/{photoNumber}', [KanbanTaskController::class, 'showSessionPhoto'])
        ->whereNumber('attendance')
        ->where('session', 'morning|afternoon')
        ->where('photoNumber', '[12]')
        ->name('kanban.attendance.session-photos.show');
    Route::get('/kanban/status-changes/{statusChange}/photo', [KanbanTaskController::class, 'showPhoto'])
        ->whereNumber('statusChange')
        ->name('kanban.status-changes.photo');
    Route::patch('/kanban/tasks/{kanbanTask}/status', [KanbanTaskController::class, 'updateStatus'])
        ->whereNumber('kanbanTask')
        ->name('kanban.tasks.status');
    Route::delete('/kanban/tasks/{kanbanTask}', [KanbanTaskController::class, 'destroy'])
        ->whereNumber('kanbanTask')
        ->name('kanban.tasks.destroy');

    Route::middleware('owner')->group(function () {
        Route::get('/admin/preferences/attendance-schedule', [AttendanceScheduleController::class, 'edit'])
            ->name('admin.attendance-schedule.edit');
        Route::put('/admin/preferences/attendance-schedule', [AttendanceScheduleController::class, 'update'])
            ->name('admin.attendance-schedule.update');
        Route::get('/admin/activity-log', [ActivityLogController::class, 'index'])
            ->name('admin.activity-log.index');
        Route::resource('/admin/users', AdminUserController::class)
            ->names('admin.users');
    });

    Route::middleware('bali')->group(function () {
        Route::middleware('menu:orders')->group(function () {
            Route::get('/orders/export', [OrderController::class, 'export'])->name('orders.export');
            Route::get('/orders/check-id', [OrderController::class, 'checkOrderId'])
                ->name('orders.check-id');
            Route::post('/colors', [ColorController::class, 'store'])->name('colors.store');
            Route::resource('orders', OrderController::class)
                ->except(['index', 'show'])
                ->names('orders');
            Route::post('/orders/{order}/payments', [OrderPaymentController::class, 'store'])
                ->whereNumber('order')
                ->name('orders.payments.store');
        });

        Route::middleware('menu:guests')->group(function () {
            Route::post('/guests', [GuestController::class, 'store'])->name('guests.store');
            Route::get('/guests', [GuestController::class, 'index'])->name('guests.index');
            Route::get('/guests/{guest}', [GuestController::class, 'show'])->name('guests.show');
            Route::get('/guests/{guest}/edit', [GuestController::class, 'edit'])->name('guests.edit');
            Route::put('/guests/{guest}', [GuestController::class, 'update'])->name('guests.update');
            Route::delete('/guests/{guest}', [GuestController::class, 'destroy'])->name('guests.destroy');
        });

        Route::middleware('menu:categories')->group(function () {
            Route::resource('categories', CategoryController::class);
        });

        Route::middleware('menu:products')->group(function () {
            Route::resource('products', ProductController::class);
        });

        Route::get('/reports/popular-categories', [CategoryController::class, 'popularProducts'])
            ->middleware('menu:popular_categories')
            ->name('reports.popular-categories');
    });

    Route::middleware('menu:materials')->group(function () {
        Route::post('/materials/{material}/transactions', [MaterialController::class, 'storeTransaction'])
            ->whereNumber('material')
            ->name('materials.transactions.store');
        Route::resource('materials', MaterialController::class)->except(['destroy']);
    });

    Route::middleware('menu:expenses')->group(function () {
        Route::resource('expenses', ExpenseController::class);
    });
    Route::get('/revenue', [RevenueController::class, 'index'])
        ->middleware('menu:revenue')
        ->name('revenue.index');

    Route::middleware('menu:employees')->group(function () {
        Route::get('/admin/cashbond', [CashbondAdvanceController::class, 'index'])
            ->name('admin.cashbond.index');
        Route::post('/admin/cashbond', [CashbondAdvanceController::class, 'store'])
            ->name('admin.cashbond.store');
        Route::get('/admin/payroll', [PayrollController::class, 'index'])
            ->name('admin.payroll.index');
        Route::post('/admin/payroll/{employee}/pay', [PayrollController::class, 'pay'])
            ->whereNumber('employee')
            ->name('admin.payroll.pay');
        Route::get('/admin/employees/{employee}/photo', [EmployeeController::class, 'photo'])
            ->name('admin.employees.photo');
        Route::resource('/admin/employees', EmployeeController::class)
            ->except(['show'])
            ->names('admin.employees');
    });

    Route::middleware('menu:attendance')->group(function () {
        Route::get('/admin/attendance', [EmployeeController::class, 'attendanceIndex'])
            ->name('admin.attendance.index');
        Route::post('/admin/employees/{employee}/attendance/{session}', [EmployeeController::class, 'attendance'])
            ->where('session', 'morning|afternoon')
            ->name('admin.employees.attendance');
        Route::delete('/admin/employees/{employee}/attendance-records/{attendance}', [EmployeeController::class, 'cancelAttendance'])
            ->whereNumber('attendance')
            ->name('admin.employees.attendance.cancel');
        Route::post('/admin/employees/{employee}/piecework-production', [PieceworkProductionController::class, 'store'])
            ->whereNumber('employee')
            ->name('admin.employees.piecework-production.store');
        Route::post('/admin/finishing-assignments', [FinishingAssignmentController::class, 'store'])
            ->name('admin.finishing-assignments.store');
        Route::patch('/admin/finishing-assignments/{finishingAssignment}/complete', [FinishingAssignmentController::class, 'complete'])
            ->whereNumber('finishingAssignment')
            ->name('admin.finishing-assignments.complete');
        Route::post('/admin/finishing-assignments/{finishingAssignment}/cancel', [FinishingAssignmentController::class, 'cancel'])
            ->whereNumber('finishingAssignment')
            ->name('admin.finishing-assignments.cancel');
        Route::patch('/admin/employees/{employee}/piecework-items/{pieceworkItem}', [PieceworkProductionController::class, 'updateStandalone'])
            ->whereNumber('employee')
            ->whereNumber('pieceworkItem')
            ->name('admin.employees.piecework-items.update');
        Route::post('/admin/employees/{employee}/piecework-assignments', [PieceworkAssignmentController::class, 'storeForEmployee'])
            ->whereNumber('employee')
            ->name('admin.employees.piecework-assignments.store');
        Route::patch('/admin/piecework-assignments/{pieceworkAssignment}', [PieceworkAssignmentController::class, 'updateFromAttendance'])
            ->whereNumber('pieceworkAssignment')
            ->name('admin.piecework-assignments.update');
        Route::post('/admin/piecework-assignments/{pieceworkAssignment}/cancel-quantity', [PieceworkAssignmentController::class, 'cancelQuantity'])
            ->whereNumber('pieceworkAssignment')
            ->name('admin.piecework-assignments.cancel-quantity');
        Route::patch('/admin/piecework-assignments/{pieceworkAssignment}/complete', [PieceworkAssignmentController::class, 'complete'])
            ->whereNumber('pieceworkAssignment')
            ->name('admin.piecework-assignments.complete');
        Route::put('/admin/employees/{employee}/attendance-records/{attendance}', [EmployeeController::class, 'correctAttendance'])
            ->whereNumber('attendance')
            ->name('admin.employees.attendance.correct');
        Route::post('/admin/employees/{employee}/overtime', [EmployeeController::class, 'overtime'])
            ->name('admin.employees.overtime');
        Route::get('/admin/employees/{employee}/attendance', [EmployeeController::class, 'attendanceHistory'])
            ->name('admin.employees.attendance-history');
    });

    Route::get('/admin/performance', [PerformanceController::class, 'index'])
        ->middleware('menu:performance')
        ->name('admin.performance.index');

    Route::middleware('menu:subcontractors')->group(function () {
        Route::get('/subcontractor-products', [SubcontractorProductController::class, 'index'])
            ->name('subcontractor-products.index');
        Route::resource('subcontractors', SubcontractorController::class)
            ->except(['destroy']);
    });

    Route::middleware('menu:orders')->group(function () {
        Route::patch('/orders/{order}/deadline-change/approve', [OrderController::class, 'approveDeadlineChange'])
            ->whereNumber('order')
            ->name('orders.deadline-change.approve');
        Route::patch('/orders/{order}/deadline-change/reject', [OrderController::class, 'rejectDeadlineChange'])
            ->whereNumber('order')
            ->name('orders.deadline-change.reject');
        Route::patch('/orders/{order}/production', [OrderController::class, 'updateProduction'])
            ->name('orders.production.update');
        Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/{order}', [OrderController::class, 'show'])
            ->whereNumber('order')
            ->name('orders.show');
    });
});
