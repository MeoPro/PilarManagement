<?php

use App\Models\AttendancePunishmentRule;
use App\Models\FactoryPieceworkPunishmentRule;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('keeps historical attendance punishment rules while the punishment feature is unavailable', function () {
    $owner = User::factory()->owner()->create();
    $rule = AttendancePunishmentRule::factory()->create([
        'applies_to' => 'employees',
        'attendance_below' => 70,
        'punishment' => 'Peringatan tertulis',
    ]);

    $this->actingAs($owner)
        ->get('/admin/punishments')
        ->assertNotFound();

    $this->assertDatabaseHas('attendance_punishment_rules', [
        'id' => $rule->id,
        'punishment' => 'Peringatan tertulis',
    ]);
});

it('hides punishment management routes without deleting existing rule data', function () {
    $owner = User::factory()->owner()->create();
    $admin = User::factory()->admin()->create();
    $attendanceRule = AttendancePunishmentRule::factory()->create();
    $pieceworkRule = FactoryPieceworkPunishmentRule::create([
        'target_salary' => 200000,
        'action_type' => 'points',
        'points_deduction' => 5,
        'is_active' => true,
    ]);

    foreach ([$owner, $admin] as $user) {
        $this->actingAs($user)
            ->get('/admin/punishments')
            ->assertNotFound();
        $this->post('/admin/punishments', [])
            ->assertNotFound();
        $this->post('/admin/punishments/factory-piecework', [])
            ->assertNotFound();
    }

    $this->assertDatabaseHas('attendance_punishment_rules', ['id' => $attendanceRule->id]);
    $this->assertDatabaseHas('factory_piecework_punishment_rules', ['id' => $pieceworkRule->id]);
});
