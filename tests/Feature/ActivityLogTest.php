<?php

use App\Models\ActivityLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

test('successful admin login and logout go to the application log instead of the activity feed', function () {
    Log::spy();
    $admin = User::factory()->factoryAdmin()->create([
        'email' => 'factory-admin@example.com',
        'password' => 'secret-password',
    ]);

    $this->post(route('login.store'), [
        'email' => $admin->email,
        'password' => 'secret-password',
    ])->assertRedirect(route('admin.index'));

    $this->post(route('logout'))->assertRedirect(route('login'));

    $this->assertDatabaseCount('activity_logs', 0);
    Log::shouldHaveReceived('info')->twice();
});

test('employee attendance actions are recorded in the owner activity feed', function () {
    $admin = User::factory()->factoryAdmin()->create();
    $employee = Employee::create([
        'full_name' => 'Factory Worker',
        'photo_path' => 'employees/factory-worker.jpg',
        'address' => 'Factory',
        'business_unit' => 'factory',
        'employee_type' => 'Borongan',
        'started_at' => today(),
        'status' => 'Aktif',
    ]);

    $this->travelTo(today()->setTime(8, 10));
    $this->actingAs($admin)
        ->post(route('admin.employees.attendance', [$employee, 'morning']), [
            'work_type' => 'Borongan',
            'piecework_items' => [[
                'product_name' => 'Kursi',
                'quantity' => 1,
                'unit_price' => '0',
                'target_deadline' => today()->toDateString(),
            ]],
        ])
        ->assertRedirect();

    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $admin->id,
        'business_unit' => 'factory',
        'action' => 'Absensi dicatat',
        'target_type' => 'Employee',
        'target_id' => (string) $employee->id,
    ]);
});

test('only owner can view and filter activity logs', function () {
    $admin = User::factory()->admin()->create();
    ActivityLog::create([
        'user_id' => $admin->id,
        'actor_name' => $admin->name,
        'business_unit' => 'bali',
        'action' => 'Data tamu diperbarui',
        'method' => 'PUT',
        'description' => 'Guest #1',
        'occurred_at' => now(),
    ]);
    $factoryAdmin = User::factory()->factoryAdmin()->create();

    $this->actingAs($factoryAdmin)
        ->get(route('admin.activity-log.index'))
        ->assertForbidden();

    $this->actingAs(User::factory()->owner()->create())
        ->get(route('admin.activity-log.index', [
            'business_unit' => 'bali',
            'action' => 'Data tamu diperbarui',
        ]))
        ->assertSee('Data tamu diperbarui')
        ->assertSee($admin->name)
        ->assertSee('Pilar Bali')
        ->assertDontSee('factory@example.com');
});

test('three consecutive actions by one admin are summarized with expandable details', function () {
    $owner = User::factory()->owner()->create();
    $admin = User::factory()->factoryAdmin()->create();

    foreach (range(1, 3) as $index) {
        ActivityLog::create([
            'user_id' => $admin->id,
            'actor_name' => $admin->name,
            'business_unit' => 'factory',
            'action' => 'Absensi dicatat',
            'method' => 'POST',
            'description' => 'Absensi dicatat · Employee #'.$index,
            'occurred_at' => now()->subSeconds($index),
        ]);
    }

    $this->actingAs($owner)
        ->get(route('admin.activity-log.index'))
        ->assertSee('Absensi dicatat (3 aktivitas)')
        ->assertSee('Lihat rincian')
        ->assertSee('Employee #1')
        ->assertSee('Employee #3');
});
