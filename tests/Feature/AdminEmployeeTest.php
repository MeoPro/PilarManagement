<?php

use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\PieceworkAssignment;
use App\Models\PieceworkItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

test('pengunjung harus login sebelum mengakses menu admin', function () {
    $response = $this->get(route('admin.employees.index'));
    $loginPage = $this->get(route('login'));

    $response->assertRedirect(route('login'));
    $loginPage->assertSee('Login Admin')
        ->assertSee('name="email"', false)
        ->assertSee('name="password"', false)
        ->assertSee('<input id="remember" type="checkbox" name="remember" value="1" checked>', false);

    $employee = Employee::create([
        'full_name' => 'Private Photo',
        'photo_path' => 'employees/private.jpg',
        'address' => 'Private Address',
        'started_at' => today(),
        'status' => 'Aktif',
    ]);
    $this->get(route('admin.employees.photo', $employee))
        ->assertRedirect(route('login'));
});

test('admin dapat login dan logout', function () {
    $admin = User::factory()->admin()->create([
        'email' => 'admin@example.com',
        'password' => 'secret-password',
        'remember_token' => null,
    ]);

    $loginResponse = $this->post(route('login.store'), [
        'email' => $admin->email,
        'password' => 'secret-password',
        'remember' => '1',
    ]);

    $loginResponse->assertRedirect(route('admin.index'));
    $loginResponse->assertCookieNotExpired(auth()->guard()->getRecallerName());
    expect($admin->fresh()->remember_token)->not->toBeNull();
    $rememberToken = $admin->fresh()->remember_token;
    $this->assertAuthenticatedAs($admin);
    $this->get(route('admin.index'))
        ->assertSee('Karyawan', false);

    $logoutResponse = $this->post(route('logout'));

    $logoutResponse->assertRedirect(route('login'));
    $this->assertGuest();
    expect($admin->fresh()->remember_token)->not->toBe($rememberToken);
});

test('admin can opt out of staying signed in on a shared device', function () {
    $admin = User::factory()->admin()->create([
        'email' => 'admin@example.com',
        'password' => 'secret-password',
        'remember_token' => null,
    ]);

    $loginResponse = $this->post(route('login.store'), [
        'email' => $admin->email,
        'password' => 'secret-password',
    ]);

    $loginResponse->assertRedirect(route('admin.index'));
    $loginResponse->assertCookieMissing(auth()->guard()->getRecallerName());
    expect($admin->fresh()->remember_token)->toBeNull();
    $this->assertAuthenticatedAs($admin);
});

test('password yang salah tidak memberikan akses admin', function () {
    User::factory()->admin()->create([
        'email' => 'admin@example.com',
        'password' => 'secret-password',
    ]);

    $response = $this->from(route('login'))->post(route('login.store'), [
        'email' => 'admin@example.com',
        'password' => 'wrong-password',
    ]);

    $response->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => 'Email atau password tidak cocok.']);
    $this->assertGuest();
});

test('akun biasa tidak dapat login atau mengakses menu admin', function () {
    $user = User::factory()->create([
        'email' => 'user@example.com',
        'password' => 'secret-password',
    ]);

    $loginResponse = $this->from(route('login'))->post(route('login.store'), [
        'email' => $user->email,
        'password' => 'secret-password',
    ]);

    $loginResponse->assertRedirect(route('login'))
        ->assertSessionHasErrors(['email' => 'Email atau password tidak cocok.']);
    $this->assertGuest();

    $this->actingAs($user)
        ->get(route('admin.employees.index'))
        ->assertForbidden();
});

test('karyawan tanpa foto menampilkan ikon profil pada daftar', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_BALI,
        'photo_path' => null,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.employees.index'))
        ->assertSee('employee-list-avatar-placeholder')
        ->assertDontSee(route('admin.employees.photo', $employee), false);
});

test('admin dapat membuat karyawan dan mencatat dua sesi absensi harian satu kali', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $this->travelTo('2026-11-16 08:15:00');

    $createResponse = $this->post(route('admin.employees.store'), [
        'full_name' => 'Siti Pekerja',
        'photo' => UploadedFile::fake()->create('siti.jpg', 100, 'image/jpeg'),
        'address' => 'Jalan Mawar 20',
        'phone' => '08123456789',
        'email' => 'siti@example.com',
        'daily_wage' => '125,000',
        'employee_type' => 'Harian',
        'started_at' => today()->subDays(2)->toDateString(),
        'status' => 'Aktif',
    ]);

    $createResponse->assertRedirect(route('admin.employees.index'));
    $employee = Employee::firstOrFail();
    expect($employee->employee_type)->toBe('Harian');
    Storage::disk('local')->assertExists($employee->photo_path);
    $this->get(route('admin.employees.photo', $employee))->assertOk();
    $this->assertDatabaseHas('employees', [
        'full_name' => 'Siti Pekerja',
        'address' => 'Jalan Mawar 20',
        'phone' => '08123456789',
        'email' => 'siti@example.com',
        'daily_wage' => 125000,
    ]);

    $this->get(route('admin.attendance.index'))
        ->assertSee('Pilih Harian atau Borongan')
        ->assertDontSee('Absensi Sesi 1');
    $this->get(route('admin.attendance.index', ['work_type' => 'Harian']))
        ->assertSee('Absensi Sesi 1')
        ->assertSee('Absensi Sesi 2')
        ->assertDontSee('Pagi')
        ->assertDontSee('Siang');

    $morningResponse = $this->post(route('admin.employees.attendance', [$employee, 'morning']), [
        'work_type' => 'Harian',
    ]);
    $duplicateResponse = $this->post(route('admin.employees.attendance', [$employee, 'morning']), [
        'work_type' => 'Harian',
    ]);

    $morningResponse->assertRedirect();
    $duplicateResponse->assertRedirect()
        ->assertSessionHas('error', 'Absensi sesi tersebut sudah dicatat hari ini.');

    $this->travelTo(today()->setTime(13, 15));
    $afternoonResponse = $this->post(route('admin.employees.attendance', [$employee, 'afternoon']), [
        'work_type' => 'Harian',
    ]);
    $afternoonResponse->assertRedirect();

    $overtimeResponse = $this->post(route('admin.employees.overtime', $employee), [
        'overtime_date' => today()->toDateString(),
        'overtime_hours' => 2.5,
        'hourly_rate' => 20000,
    ]);
    $overtimeResponse->assertRedirect()
        ->assertSessionHas('success', 'Jam dan tarif lembur berhasil disimpan.');

    $indexResponse = $this->get(route('admin.attendance.index', ['work_type' => 'Harian']));

    $this->assertDatabaseCount('employee_attendances', 2);
    $indexResponse->assertSee('Siti Pekerja')
        ->assertSee('Terlambat 15 menit')
        ->assertSee('value="2.50"', false);
    $this->get(route('admin.employees.index'))
        ->assertSee('Siti Pekerja')
        ->assertSee('08123456789')
        ->assertSee('siti@example.com')
        ->assertSee('Rp 125,000')
        ->assertDontSee('Kehadiran Bulan Ini');
    $this->get(route('admin.employees.attendance-history', $employee))
        ->assertSee('Riwayat Absensi: Siti Pekerja')
        ->assertDontSee('Persentase kehadiran tahun')
        ->assertSee('Harian')
        ->assertSee('Riwayat Lembur')
        ->assertSee('20.000');
    $this->assertDatabaseHas('employee_attendances', [
        'employee_id' => $employee->id,
        'session' => 'afternoon',
        'overtime_hours' => 0,
    ]);
    $this->assertDatabaseHas('employee_overtimes', [
        'employee_id' => $employee->id,
        'overtime_date' => today()->toDateString().' 00:00:00',
        'overtime_hours' => 2.5,
        'hourly_rate' => 20000,
    ]);
});

test('admin tidak dapat membuat karyawan dengan lebih dari satu jenis kerja', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create())
        ->post(route('admin.employees.store'), [
            'full_name' => 'Karyawan Multi Jenis',
            'photo' => UploadedFile::fake()->create('multi-jenis.jpg', 100, 'image/jpeg'),
            'address' => 'Jalan Mawar 20',
            'daily_wage' => '100,000',
            'employee_type' => 'Harian',
            'employee_types' => ['Harian', 'Borongan'],
            'started_at' => today()->subDays(2)->toDateString(),
            'status' => 'Aktif',
        ])
        ->assertSessionHasErrors('employee_types');

    $this->assertDatabaseMissing('employees', ['full_name' => 'Karyawan Multi Jenis']);
});

test('employee attendance history shows the timezone label and Borongan task dropdown', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_BALI,
    ]);
    PieceworkAssignment::factory()
        ->for($employee)
        ->create([
            'product_name' => 'Pekerjaan Borongan',
            'target_quantity' => 8,
        ]);

    $this->actingAs($admin)
        ->get(route('admin.employees.attendance-history', $employee))
        ->assertOk()
        ->assertSeeText('WITA')
        ->assertSeeText('Borongan Berjalan (1)')
        ->assertSeeText('Pekerjaan Borongan')
        ->assertSeeText('0 / 8')
        ->assertSeeText('Edit tugas')
        ->assertSeeText('Batalkan tugas')
        ->assertSeeText('Selesaikan tugas');
});

test('employee attendance history keeps products with open assignments out of the completed history', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_BALI,
    ]);
    $runningAssignment = PieceworkAssignment::factory()
        ->for($employee)
        ->create([
            'product_name' => 'Produk berjalan aktif',
            'target_quantity' => 5,
        ]);
    PieceworkItem::create([
        'employee_id' => $employee->id,
        'piecework_assignment_id' => $runningAssignment->id,
        'product_name' => 'Produk berjalan aktif',
        'quantity' => 2,
        'unit_price' => 10000,
        'line_total' => 20000,
        'target_deadline' => $runningAssignment->target_deadline,
    ]);
    PieceworkItem::create([
        'employee_id' => $employee->id,
        'product_name' => 'Produk riwayat selesai',
        'quantity' => 1,
        'unit_price' => 15000,
        'line_total' => 15000,
        'target_deadline' => '2026-11-20',
    ]);

    $response = $this->actingAs($admin)
        ->get(route('admin.employees.attendance-history', $employee))
        ->assertOk()
        ->assertSee('data-piecework-action="cancel"', false)
        ->assertSee('data-piecework-action="complete"', false)
        ->assertSeeText('Edit tugas')
        ->assertSeeText('Produk riwayat selesai')
        ->assertSeeText('Edit produk');
    $historyStart = strpos($response->getContent(), '<h2>Riwayat Borongan</h2>');
    $historyEnd = strpos($response->getContent(), '<section class="page-section">', $historyStart + 1);
    $historyMarkup = substr($response->getContent(), $historyStart, $historyEnd - $historyStart);

    expect($historyMarkup)->not->toContain('Produk berjalan aktif')
        ->and($historyMarkup)->not->toContain('data-piecework-action="cancel"')
        ->and($historyMarkup)->not->toContain('data-piecework-action="complete"');
});

test('admin can edit standalone Borongan products from employee attendance history', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_BALI,
    ]);
    $pieceworkItem = PieceworkItem::create([
        'employee_id' => $employee->id,
        'product_name' => 'Buat meja',
        'quantity' => 2,
        'unit_price' => 150000,
        'line_total' => 300000,
        'target_deadline' => '2026-11-17',
    ]);

    $this->actingAs($admin)
        ->get(route('admin.employees.attendance-history', $employee))
        ->assertSeeText('Edit produk')
        ->assertSee(route('admin.employees.piecework-items.update', [$employee, $pieceworkItem]), false);

    $this->patch(route('admin.employees.piecework-items.update', [$employee, $pieceworkItem]), [
        'product_name' => 'Meja makan',
        'quantity' => 3,
        'unit_price' => '175,000',
        'target_deadline' => '2026-11-20',
    ])->assertRedirect(route('admin.employees.attendance-history', $employee))
        ->assertSessionHas('success', 'Barang Borongan berhasil diperbarui.');

    $this->assertDatabaseHas('piecework_items', [
        'id' => $pieceworkItem->id,
        'employee_id' => $employee->id,
        'product_name' => 'Meja makan',
        'quantity' => 3,
        'unit_price' => 175000,
        'line_total' => 525000,
        'target_deadline' => '2026-11-20 00:00:00',
    ]);
});

test('admin cannot edit another employee or assigned Borongan production as a standalone product', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_BALI,
    ]);
    $otherEmployee = Employee::factory()->create([
        'business_unit' => User::UNIT_BALI,
    ]);
    $otherEmployeeItem = PieceworkItem::create([
        'employee_id' => $otherEmployee->id,
        'product_name' => 'Milik karyawan lain',
        'quantity' => 1,
        'unit_price' => 100000,
        'line_total' => 100000,
        'target_deadline' => '2026-11-17',
    ]);
    $assignment = PieceworkAssignment::factory()
        ->for($employee)
        ->create();
    $assignedItem = PieceworkItem::create([
        'employee_id' => $employee->id,
        'piecework_assignment_id' => $assignment->id,
        'product_name' => $assignment->product_name,
        'quantity' => 1,
        'unit_price' => 100000,
        'line_total' => 100000,
        'target_deadline' => $assignment->target_deadline,
    ]);
    $update = [
        'product_name' => 'Perubahan tidak diizinkan',
        'quantity' => 2,
        'unit_price' => 200000,
        'target_deadline' => '2026-11-20',
    ];

    $this->actingAs($admin)
        ->patch(route('admin.employees.piecework-items.update', [$employee, $otherEmployeeItem]), $update)
        ->assertNotFound();

    $this->patch(route('admin.employees.piecework-items.update', [$employee, $assignedItem]), $update)
        ->assertNotFound();

    $this->assertDatabaseHas('piecework_items', [
        'id' => $otherEmployeeItem->id,
        'product_name' => 'Milik karyawan lain',
        'quantity' => 1,
    ]);
    $this->assertDatabaseHas('piecework_items', [
        'id' => $assignedItem->id,
        'product_name' => $assignment->product_name,
        'quantity' => 1,
    ]);
});

test('Borongan-only employees do not accept a daily wage and start date defaults to today', function () {
    $this->travelTo('2026-11-16 10:00:00');
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Borongan',
        'daily_wage' => null,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.employees.create'))
        ->assertSee('value="2026-11-16"', false);

    $this->get(route('admin.employees.edit', $employee))
        ->assertSee('id="daily_wage"', false)
        ->assertSee('disabled', false);

    $this->post(route('admin.employees.store'), [
        'full_name' => 'Borongan Saja',
        'address' => 'Jalan Mawar',
        'employee_type' => 'Borongan',
        'started_at' => '2026-11-16',
        'status' => 'Aktif',
    ])->assertRedirect(route('admin.employees.index'));

    $this->assertDatabaseHas('employees', [
        'full_name' => 'Borongan Saja',
        'daily_wage' => null,
    ]);

    $this->from(route('admin.employees.create'))
        ->post(route('admin.employees.store'), [
            'full_name' => 'Borongan Invalid',
            'address' => 'Jalan Mawar',
            'employee_type' => 'Borongan',
            'daily_wage' => '100,000',
            'started_at' => '2026-11-16',
            'status' => 'Aktif',
        ])
        ->assertRedirect()
        ->assertSessionHasErrors('daily_wage');
});

test('foto karyawan opsional dan gaji wajib untuk karyawan harian', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());

    $employeeData = [
        'full_name' => 'Karyawan Harian',
        'address' => 'Jalan Melati 10',
        'employee_type' => 'Harian',
        'started_at' => today()->subDays(2)->toDateString(),
        'status' => 'Aktif',
    ];

    $this->from(route('admin.employees.create'))
        ->post(route('admin.employees.store'), $employeeData)
        ->assertSessionHasErrors('daily_wage');
    $this->assertDatabaseMissing('employees', ['full_name' => 'Karyawan Harian']);

    $this->post(route('admin.employees.store'), array_merge($employeeData, [
        'daily_wage' => '125,000',
    ]))->assertRedirect(route('admin.employees.index'));

    $employee = Employee::query()->where('full_name', 'Karyawan Harian')->firstOrFail();
    expect($employee->photo_path)->toBeNull()
        ->and((float) $employee->daily_wage)->toBe(125000.0);
    $this->get(route('admin.employees.photo', $employee))->assertNotFound();

    $this->put(route('admin.employees.update', $employee), array_merge($employeeData, [
        'daily_wage' => '125,000',
    ]))->assertRedirect(route('admin.employees.index'));
    expect($employee->fresh()->photo_path)->toBeNull();

    $this->put(route('admin.employees.update', $employee), array_merge($employeeData, [
        'daily_wage' => '125,000',
        'photo' => UploadedFile::fake()->create('harian.jpg', 100, 'image/jpeg'),
    ]))->assertRedirect(route('admin.employees.index'));
    Storage::disk('local')->assertExists($employee->fresh()->photo_path);
});

test('karyawan borongan tidak wajib memiliki gaji harian', function () {
    $this->actingAs(User::factory()->admin()->create());

    $this->post(route('admin.employees.store'), [
        'full_name' => 'Karyawan Borongan',
        'address' => 'Jalan Kenanga 5',
        'employee_type' => 'Borongan',
        'started_at' => today()->subDays(2)->toDateString(),
        'status' => 'Aktif',
    ])->assertRedirect(route('admin.employees.index'));

    $employee = Employee::query()->where('full_name', 'Karyawan Borongan')->firstOrFail();
    expect($employee->daily_wage)->toBeNull()
        ->and($employee->photo_path)->toBeNull();
});

test('karyawan dapat absen lagi pada hari berikutnya', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::create([
        'full_name' => 'Daily Attendance',
        'photo_path' => 'employees/daily-attendance.jpg',
        'address' => 'Bali',
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Harian',
        'started_at' => today()->subDay(),
        'status' => 'Aktif',
        'daily_wage' => 100000,
    ]);
    $firstDay = today();
    $secondDay = $firstDay->copy()->addDay();

    $this->actingAs($admin);
    $this->travelTo($firstDay->copy()->setTime(8, 15));
    $this->post(route('admin.employees.attendance', [$employee, 'morning']), [
        'work_type' => 'Harian',
    ])->assertRedirect();

    $this->travelTo($secondDay->copy()->setTime(8, 15));
    $this->post(route('admin.employees.attendance', [$employee, 'morning']), [
        'work_type' => 'Harian',
    ])->assertRedirect()
        ->assertSessionHas('success', 'Status hadir sesi 1 berhasil dicatat.');

    $this->assertDatabaseHas('employee_attendances', [
        'employee_id' => $employee->id,
        'attendance_date' => $firstDay->toDateString(),
        'session' => 'morning',
    ]);
    $this->assertDatabaseHas('employee_attendances', [
        'employee_id' => $employee->id,
        'attendance_date' => $secondDay->toDateString(),
        'session' => 'morning',
    ]);

    $this->get(route('admin.attendance.index', ['work_type' => 'Harian']))
        ->assertSee('Absensi Sesi 2')
        ->assertDontSee('Pagi')
        ->assertDontSee('Siang');
});

test('riwayat absensi tidak menampilkan persentase kehadiran', function () {
    $this->travelTo('2026-11-15 17:00:00');
    $admin = User::factory()->admin()->create();
    $employee = Employee::create([
        'full_name' => 'Annual Attendance',
        'photo_path' => 'employees/annual-attendance.jpg',
        'address' => 'Bali',
        'business_unit' => User::UNIT_BALI,
        'started_at' => '2026-01-01',
        'status' => 'Aktif',
    ]);

    foreach ([
        ['2026-11-14', 'morning', '2026-11-14 08:00:00'],
        ['2026-11-14', 'afternoon', '2026-11-14 13:00:00'],
    ] as [$date, $session, $attendedAt]) {
        EmployeeAttendance::create([
            'employee_id' => $employee->id,
            'attendance_date' => $date,
            'session' => $session,
            'work_type' => 'Harian',
            'attended_at' => $attendedAt,
        ]);
    }

    $this->actingAs($admin)
        ->get(route('admin.employees.attendance-history', $employee))
        ->assertDontSee('Persentase kehadiran');
});

test('admin dapat mengubah data karyawan dan mengganti foto', function () {
    Storage::fake('local');
    $this->actingAs(User::factory()->admin()->create());
    $oldPhoto = UploadedFile::fake()->create('old.jpg', 100, 'image/jpeg');
    $oldPhotoPath = $oldPhoto->store('employees', 'local');
    $employee = Employee::create([
        'full_name' => 'Nama Lama',
        'photo_path' => $oldPhotoPath,
        'address' => 'Alamat Lama',
        'employee_type' => 'Harian',
        'started_at' => today()->subDays(4),
    ]);

    $this->get(route('admin.employees.edit', $employee))
        ->assertSee('Gaji harian')
        ->assertSee('Status karyawan');

    $response = $this->put(route('admin.employees.update', $employee), [
        'full_name' => 'Nama Baru',
        'photo' => UploadedFile::fake()->create('new.png', 100, 'image/png'),
        'address' => 'Alamat Baru',
        'phone' => '08111111111',
        'email' => 'nama.baru@example.com',
        'employee_type' => 'Harian',
        'daily_wage' => '100,000',
        'cashbond' => '250,000',
        'started_at' => today()->subDays(3)->toDateString(),
        'status' => 'Aktif',
    ]);

    $response->assertRedirect(route('admin.employees.index'));
    $this->assertDatabaseHas('employees', [
        'id' => $employee->id,
        'full_name' => 'Nama Baru',
        'address' => 'Alamat Baru',
        'phone' => '08111111111',
        'email' => 'nama.baru@example.com',
        'daily_wage' => 100000,
        'cashbond' => 250000,
    ]);
    $this->get(route('admin.employees.attendance-history', $employee))
        ->assertSee('Cashbond')
        ->assertSee('Rp 250.000');
    Storage::disk('local')->assertMissing($oldPhotoPath);
    Storage::disk('local')->assertExists($employee->fresh()->photo_path);
});

test('gaji wajib dipertahankan untuk harian saat update dan tidak wajib untuk borongan saja', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create([
        'employee_type' => 'Harian',
        'daily_wage' => 100000,
    ]);
    $employeeData = [
        'full_name' => $employee->full_name,
        'address' => $employee->address,
        'started_at' => $employee->started_at->toDateString(),
        'status' => 'Aktif',
        'employee_type' => 'Harian',
    ];

    $this->actingAs($admin)
        ->from(route('admin.employees.edit', $employee))
        ->put(route('admin.employees.update', $employee), $employeeData)
        ->assertSessionHasErrors('daily_wage');
    $this->assertDatabaseHas('employees', [
        'id' => $employee->id,
        'daily_wage' => 100000,
    ]);

    $this->put(route('admin.employees.update', $employee), array_merge($employeeData, [
        'employee_type' => 'Borongan',
    ]))->assertRedirect(route('admin.employees.index'));

    expect($employee->fresh()->daily_wage)->toBeNull();
});

test('admin tidak dapat menyimpan cashbond karyawan bernilai negatif', function () {
    Storage::fake('local');
    $admin = User::factory()->admin()->create();
    $employee = Employee::create([
        'full_name' => 'Cashbond Employee',
        'photo_path' => 'employees/cashbond.jpg',
        'address' => 'Bali',
        'business_unit' => User::UNIT_BALI,
        'started_at' => today(),
        'cashbond' => 50000,
    ]);

    $this->actingAs($admin)
        ->from(route('admin.employees.edit', $employee))
        ->put(route('admin.employees.update', $employee), [
            'full_name' => $employee->full_name,
            'address' => $employee->address,
            'started_at' => $employee->started_at->toDateString(),
            'status' => 'Aktif',
            'cashbond' => '-1',
        ])
        ->assertSessionHasErrors('cashbond');

    $this->assertDatabaseHas('employees', [
        'id' => $employee->id,
        'cashbond' => 50000,
    ]);
});

test('admin can record Izin or Alpa without adding attendance pay', function () {
    $this->travelTo('2026-11-16 08:15:00');
    $admin = User::factory()->admin()->create([
        'accessible_menus' => ['attendance'],
    ]);
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Harian',
        'daily_wage' => 100000,
    ]);

    $this->actingAs($admin)
        ->post(route('admin.employees.attendance', [$employee, 'morning']), [
            'work_type' => 'Harian',
            'attendance_status' => 'Alpa',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Status alpa sesi 1 berhasil dicatat.');

    $this->assertDatabaseHas('employee_attendances', [
        'employee_id' => $employee->id,
        'attendance_date' => today()->toDateString(),
        'session' => 'morning',
        'attendance_status' => 'Alpa',
        'daily_pay_amount' => 0,
        'piecework_amount' => 0,
        'recorded_by_id' => $admin->id,
    ]);
});

test('admin can correct an attendance status and the corrected status updates daily payroll', function () {
    $admin = User::factory()->admin()->create([
        'accessible_menus' => ['attendance'],
    ]);
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Harian',
        'daily_wage' => 100000,
    ]);
    $attendance = EmployeeAttendance::create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-11-16',
        'session' => 'morning',
        'work_type' => 'Harian',
        'attendance_status' => 'Alpa',
        'daily_pay_amount' => 0,
        'attended_at' => '2026-11-16 08:00:00',
    ]);

    $this->actingAs($admin)
        ->put(route('admin.employees.attendance.correct', [$employee, $attendance]), [
            'attendance_status' => 'Hadir',
            'correction_reason' => 'Karyawan hadir, status awal keliru.',
        ])
        ->assertRedirect()
        ->assertSessionHas('success', 'Status absensi berhasil dikoreksi.');

    $this->assertDatabaseHas('employee_attendances', [
        'id' => $attendance->id,
        'attendance_status' => 'Hadir',
        'daily_pay_amount' => 50000,
        'corrected_by_id' => $admin->id,
        'correction_reason' => 'Karyawan hadir, status awal keliru.',
    ]);
    $this->assertDatabaseHas('activity_logs', [
        'user_id' => $admin->id,
        'action' => 'Absensi dikoreksi',
        'target_type' => 'EmployeeAttendance',
        'target_id' => (string) $attendance->id,
    ]);
});

test('attendance correction requires a reason and leaves the record unchanged when it is missing', function () {
    $admin = User::factory()->admin()->create([
        'accessible_menus' => ['attendance'],
    ]);
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_BALI,
    ]);
    $attendance = EmployeeAttendance::create([
        'employee_id' => $employee->id,
        'attendance_date' => '2026-11-16',
        'session' => 'morning',
        'work_type' => 'Harian',
        'attendance_status' => 'Alpa',
        'attended_at' => '2026-11-16 08:00:00',
    ]);

    $this->actingAs($admin)
        ->from(route('admin.employees.attendance-history', $employee))
        ->put(route('admin.employees.attendance.correct', [$employee, $attendance]), [
            'attendance_status' => 'Hadir',
        ])
        ->assertRedirect(route('admin.employees.attendance-history', $employee))
        ->assertSessionHasErrors('correction_reason');

    $this->assertDatabaseHas('employee_attendances', [
        'id' => $attendance->id,
        'attendance_status' => 'Alpa',
        'corrected_at' => null,
    ]);
});

test('admin without attendance access cannot correct an attendance record', function () {
    $admin = User::factory()->admin()->create([
        'accessible_menus' => ['employees'],
    ]);
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_BALI,
    ]);
    $attendance = EmployeeAttendance::create([
        'employee_id' => $employee->id,
        'attendance_date' => today()->toDateString(),
        'session' => 'morning',
        'work_type' => 'Harian',
        'attendance_status' => 'Alpa',
        'attended_at' => now(),
    ]);

    $this->actingAs($admin)
        ->put(route('admin.employees.attendance.correct', [$employee, $attendance]), [
            'attendance_status' => 'Hadir',
            'correction_reason' => 'Status awal salah.',
        ])
        ->assertForbidden();

    $this->assertDatabaseHas('employee_attendances', [
        'id' => $attendance->id,
        'attendance_status' => 'Alpa',
        'corrected_at' => null,
    ]);
});

test('admin can switch an employee between Harian and Borongan and filter the employee list', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create([
        'full_name' => 'Jenis Berubah',
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Harian',
    ]);

    $this->actingAs($admin)
        ->put(route('admin.employees.update', $employee), [
            'full_name' => $employee->full_name,
            'address' => $employee->address,
            'employee_type' => 'Borongan',
            'started_at' => $employee->started_at->toDateString(),
            'status' => 'Aktif',
        ])
        ->assertRedirect(route('admin.employees.index'));

    $this->assertDatabaseHas('employees', [
        'id' => $employee->id,
        'employee_type' => 'Borongan',
    ]);
    $this->get(route('admin.employees.index', ['employee_type' => 'Borongan']))
        ->assertSee('Jenis Berubah')
        ->assertSee('Borongan');
});

test('Harian attendance requires daily wage and does not appear in Borongan attendance', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create([
        'full_name' => 'Karyawan Harian',
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Harian',
        'daily_wage' => null,
    ]);

    $this->actingAs($admin)
        ->get(route('admin.employees.create'))
        ->assertSee('name="employee_type"', false)
        ->assertDontSee('name="employee_types[]"', false);

    $this->travelTo(today()->setTime(8, 0));
    $this->post(route('admin.employees.attendance', [$employee, 'morning']), [
        'work_type' => 'Harian',
    ])->assertRedirect()
        ->assertSessionHas('error', 'Isi gaji harian pada data karyawan sebelum mencatat hadir sebagai Harian.');

    $this->get(route('admin.attendance.index', ['work_type' => 'Harian']))
        ->assertSeeText($employee->full_name);
    $this->get(route('admin.attendance.index', ['work_type' => 'Borongan']))
        ->assertDontSeeText($employee->full_name);

    $this->post(route('admin.employees.attendance', [$employee, 'morning']), [
        'work_type' => 'Borongan',
    ])->assertRedirect()
        ->assertSessionHas('error', 'Absensi Borongan hanya tersedia untuk Pilar Factory atau karyawan Pilar Bali berjenis kerja Borongan.');
});

test('Borongan-only Bali employees can record morning and afternoon attendance', function () {
    $this->actingAs(User::factory()->admin()->create());
    $employee = Employee::create([
        'full_name' => 'PB Borongan Murni',
        'address' => 'Alamat PB',
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Borongan',
        'started_at' => today(),
        'status' => 'Aktif',
    ]);

    $this->travelTo(today()->setTime(8, 0));
    $this->get(route('admin.attendance.index', ['work_type' => 'Borongan']))
        ->assertSeeText('Absensi Sesi 1 (Pagi)')
        ->assertSeeText('Absensi Sesi 2 (Siang)')
        ->assertSee('aria-label="Hadir PB Borongan Murni sesi 1"', false)
        ->assertDontSee('aria-label="Hadir PB Borongan Murni sesi 2"', false);
    $this->post(route('admin.employees.attendance', [$employee, 'morning']), [
        'work_type' => 'Borongan',
    ])->assertRedirect()
        ->assertSessionHas('success', 'Status hadir sesi 1 berhasil dicatat.');

    $this->travelTo(today()->setTime(13, 15));
    $this->get(route('admin.attendance.index', ['work_type' => 'Borongan']))
        ->assertSee('aria-label="Hadir PB Borongan Murni sesi 2"', false);
    $this->post(route('admin.employees.attendance', [$employee, 'afternoon']), [
        'work_type' => 'Borongan',
    ])->assertRedirect()
        ->assertSessionHas('success', 'Status hadir sesi 2 berhasil dicatat.');

    $this->assertDatabaseCount('employee_attendances', 2);
    $this->assertDatabaseHas('employee_attendances', [
        'employee_id' => $employee->id,
        'session' => 'morning',
        'work_type' => 'Borongan',
        'attendance_status' => 'Hadir',
    ]);
    $this->assertDatabaseHas('employee_attendances', [
        'employee_id' => $employee->id,
        'session' => 'afternoon',
        'work_type' => 'Borongan',
        'attendance_status' => 'Hadir',
    ]);
});

test('attendance tabs show only the selected work type and the override endpoint is removed', function () {
    $this->travelTo('2026-11-16 08:15:00');
    $admin = User::factory()->admin()->create();
    $dailyEmployee = Employee::factory()->create([
        'full_name' => 'Karyawan Harian Tunggal',
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Harian',
        'daily_wage' => 100000,
    ]);
    $boronganEmployee = Employee::factory()->create([
        'full_name' => 'Karyawan Borongan Tunggal',
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Borongan',
    ]);
    $this->actingAs($admin)
        ->get(route('admin.attendance.index', ['work_type' => 'Harian']))
        ->assertSeeText($dailyEmployee->full_name)
        ->assertDontSeeText($boronganEmployee->full_name);

    $this->get(route('admin.attendance.index', ['work_type' => 'Borongan']))
        ->assertSeeText($boronganEmployee->full_name)
        ->assertDontSeeText($dailyEmployee->full_name);

    $this->post('/admin/employees/'.$dailyEmployee->id.'/daily-attendance-overrides')
        ->assertNotFound();
});

test('employee update rejects the legacy multi-type field', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create([
        'full_name' => 'Karyawan Tunggal',
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Harian',
        'daily_wage' => 100000,
    ]);
    $this->actingAs($admin)
        ->put(route('admin.employees.update', $employee), [
            'full_name' => $employee->full_name,
            'address' => $employee->address,
            'started_at' => $employee->started_at->toDateString(),
            'status' => 'Aktif',
            'employee_type' => 'Harian',
            'employee_types' => ['Harian', 'Borongan'],
            'daily_wage' => '100,000',
        ])
        ->assertSessionHasErrors('employee_types');

    $this->assertDatabaseHas('employees', [
        'id' => $employee->id,
        'employee_type' => 'Harian',
        'daily_wage' => 100000,
    ]);
});

test('attendance cancellation cannot delete Borongan records', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Borongan',
    ]);
    $attendance = EmployeeAttendance::create([
        'employee_id' => $employee->id,
        'attendance_date' => today()->toDateString(),
        'session' => 'morning',
        'work_type' => 'Borongan',
        'attendance_status' => 'Hadir',
        'attended_at' => now(),
    ]);

    $this->actingAs($admin)
        ->from(route('admin.attendance.index', ['work_type' => 'Borongan']))
        ->delete(route('admin.employees.attendance.cancel', [$employee, $attendance]))
        ->assertRedirect(route('admin.attendance.index', ['work_type' => 'Borongan']))
        ->assertSessionHas('error', 'Hanya absensi Harian berstatus Hadir yang dapat dibatalkan.');

    $this->assertDatabaseHas('employee_attendances', ['id' => $attendance->id]);
});

test('karyawan Borongan tidak dapat dimasukkan melalui endpoint jenis kerja tambahan', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create([
        'employee_type' => 'Borongan',
        'daily_wage' => null,
    ]);

    $this->actingAs($admin)
        ->post('/admin/employees/'.$employee->id.'/daily-attendance-overrides')
        ->assertNotFound();
});

test('absensi tepat pada jam mulai tidak dianggap terlambat', function () {
    $this->actingAs(User::factory()->admin()->create());
    $employee = Employee::create([
        'full_name' => 'Tepat Waktu',
        'photo_path' => 'employees/on-time.jpg',
        'address' => 'Alamat',
        'employee_type' => 'Harian',
        'started_at' => today(),
        'status' => 'Aktif',
        'daily_wage' => 100000,
    ]);

    $this->travelTo(today()->setTime(8, 0));
    $this->post(route('admin.employees.attendance', [$employee, 'morning']), [
        'work_type' => 'Harian',
    ])
        ->assertRedirect();

    $this->travelTo(today()->setTime(12, 0));
    $this->post(route('admin.employees.attendance', [$employee, 'afternoon']), [
        'work_type' => 'Harian',
    ])
        ->assertRedirect();

    $morningAttendance = $employee->attendances()->where('session', 'morning')->firstOrFail();
    $afternoonAttendance = $employee->attendances()->where('session', 'afternoon')->firstOrFail();

    expect($morningAttendance->is_late)->toBeFalse()
        ->and($morningAttendance->late_minutes)->toBe(0)
        ->and($afternoonAttendance->is_late)->toBeFalse()
        ->and($afternoonAttendance->late_minutes)->toBe(0);
});

test('absensi ditolak tepat pada batas penutupan WITA', function () {
    $this->actingAs(User::factory()->admin()->create());
    $employee = Employee::create([
        'full_name' => 'Batas Absensi',
        'photo_path' => 'employees/cutoff.jpg',
        'address' => 'Alamat',
        'employee_type' => 'Harian',
        'started_at' => today(),
        'status' => 'Aktif',
        'daily_wage' => 100000,
    ]);

    $this->travelTo(today()->setTime(8, 59));
    $this->get(route('admin.attendance.index', ['work_type' => 'Harian']))
        ->assertSeeText('Absensi sesi pertama dibuka pukul 00.00 dan ditutup pukul 09.00 WITA')
        ->assertSee('data-attendance-cutoff="'.today()->format('Y-m-d').'T09:00:00+08:00"', false);

    $this->travelTo(today()->setTime(9, 0));
    $this->post(route('admin.employees.attendance', [$employee, 'morning']), [
        'work_type' => 'Harian',
    ])
        ->assertRedirect()
        ->assertSessionHas('error', 'Waktu absensi sesi tersebut sudah ditutup pada pukul 09:00 WITA.');

    $this->travelTo(today()->setTime(13, 30));
    $this->post(route('admin.employees.attendance', [$employee, 'afternoon']), [
        'work_type' => 'Harian',
    ])
        ->assertRedirect()
        ->assertSessionHas('error', 'Waktu absensi sesi tersebut sudah ditutup pada pukul 13:30 WITA.');

    $this->assertDatabaseCount('employee_attendances', 0);
});

test('absensi yang dilakukan setelah jam mulai ditandai terlambat dalam zona WITA', function () {
    $this->actingAs(User::factory()->admin()->create());
    $employee = Employee::create([
        'full_name' => 'Terlambat',
        'photo_path' => 'employees/late.jpg',
        'address' => 'Alamat',
        'employee_type' => 'Harian',
        'started_at' => today(),
        'status' => 'Aktif',
        'daily_wage' => 100000,
    ]);

    $this->travelTo(today()->setTime(8, 1));
    $this->post(route('admin.employees.attendance', [$employee, 'morning']), [
        'work_type' => 'Harian',
    ])->assertRedirect();

    $attendance = $employee->attendances()->firstOrFail();

    expect(config('app.timezone'))->toBe('Asia/Makassar')
        ->and($attendance->attended_at->format('H:i'))->toBe('08:01')
        ->and($attendance->is_late)->toBeTrue()
        ->and($attendance->late_minutes)->toBe(1);
});

test('overtime can be recorded without attendance or a time restriction and its hourly rate is reused', function () {
    $admin = User::factory()->factoryAdmin()->create();
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_FACTORY,
        'started_at' => '2026-11-02',
    ]);
    $this->actingAs($admin);

    $this->travelTo('2026-11-16 09:00:00');
    $this->post(route('admin.employees.overtime', $employee), [
        'overtime_date' => '2026-11-16',
        'overtime_hours' => 1.5,
        'hourly_rate' => 30000,
    ])->assertRedirect()
        ->assertSessionHas('success', 'Jam dan tarif lembur berhasil disimpan.');

    $this->assertDatabaseHas('employees', [
        'id' => $employee->id,
        'overtime_hourly_rate' => 30000,
    ]);

    $this->travelTo('2026-11-17 09:00:00');
    $this->get(route('admin.attendance.index', ['work_type' => 'Harian']))
        ->assertSee('value="30000.00"', false)
        ->assertSeeText('Tambah lembur')
        ->assertDontSee('<details class="employee-overtime-details" open', false);

    $this->post(route('admin.employees.overtime', $employee), [
        'overtime_date' => '2026-11-17',
        'overtime_hours' => 2,
        'hourly_rate' => 30000,
    ])->assertRedirect()
        ->assertSessionHas('success', 'Jam dan tarif lembur berhasil disimpan.');

    $this->assertDatabaseCount('employee_attendances', 0);
    $this->assertDatabaseCount('employee_overtimes', 2);
});

test('employees who only support Borongan cannot record overtime', function () {
    $admin = User::factory()->admin()->create();
    $employee = Employee::factory()->create([
        'business_unit' => User::UNIT_BALI,
        'employee_type' => 'Borongan',
        'daily_wage' => null,
        'started_at' => '2026-11-02',
    ]);

    $this->actingAs($admin)
        ->post(route('admin.employees.overtime', $employee), [
            'overtime_date' => '2026-11-16',
            'overtime_hours' => 1,
            'hourly_rate' => 20000,
        ])
        ->assertRedirect()
        ->assertSessionHas('error');

    $this->assertDatabaseCount('employee_overtimes', 0);
});
