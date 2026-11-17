@extends('layouts.admin')

@section('title', 'Kanban')

@section('content')
    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Workspace</p>
            <h1>Kanban Board</h1>
            <p>Papan tugas bersama admin. Kartu dan riwayat status tersimpan; foto dihapus setelah 30 hari.</p>
        </div>
        <div class="kanban-heading-actions">
            @if(auth()->user()->isOwner())
                <form action="{{ route('kanban.index') }}" method="GET" class="kanban-admin-filter">
                    <label for="kanban-task-date-filter">
                        Tanggal tugas
                        <input
                            id="kanban-task-date-filter"
                            type="date"
                            name="task_date"
                            value="{{ $taskDate }}"
                            max="{{ $localToday->toDateString() }}"
                            onchange="this.form.submit()"
                        >
                    </label>
                    <label for="kanban-admin-filter">
                        Papan admin
                        <select id="kanban-admin-filter" name="admin_id" onchange="this.form.submit()">
                            <option value="">Semua Admin</option>
                            @foreach($admins as $admin)
                                <option value="{{ $admin->id }}" @selected((string) $selectedAdminId === (string) $admin->id)>
                                    {{ $admin->name }} · {{ $admin->business_unit === 'factory' ? 'Factory' : 'Bali' }}
                                </option>
                            @endforeach
                        </select>
                    </label>
                </form>
            @endif
            @if($isCurrentTaskDate && $canCreateTask)
                <details class="kanban-create-control" @if(old('title')) open @endif>
                    <summary class="kanban-add-task-button" aria-label="Tambah tugas" title="Tambah tugas">
                        <x-layout.icon name="plus" />
                    </summary>
                    <div class="kanban-create-popover">
                        <form action="{{ route('kanban.tasks.store') }}" method="POST" class="kanban-create-form">
                            @csrf
                            <label for="kanban-task-title">Nama tugas</label>
                            <input id="kanban-task-title" name="title" value="{{ old('title') }}" maxlength="255" required>

                            <label for="kanban-task-description">Deskripsi</label>
                            <textarea id="kanban-task-description" name="description" rows="2" maxlength="5000">{{ old('description') }}</textarea>

                            @if(auth()->user()->isOwner())
                                <label for="kanban-task-assignee">Admin</label>
                                <select id="kanban-task-assignee" name="assignee_id" required>
                                    <option value="">Pilih admin</option>
                                    @foreach($admins as $admin)
                                        <option value="{{ $admin->id }}" @selected((string) old('assignee_id', $selectedAdminId) === (string) $admin->id)>
                                            {{ $admin->name }} · {{ $admin->business_unit === 'factory' ? 'Factory' : 'Bali' }}
                                        </option>
                                    @endforeach
                                </select>
                            @endif

                            <div class="employee-form-actions">
                                <button class="btn-primary" type="submit">Tambah Tugas</button>
                            </div>
                        </form>
                    </div>
                </details>
            @endif
        </div>
    </div>

    @if(session('success'))
        <p class="message" role="status">{{ session('success') }}</p>
    @endif
    @if(session('error'))
        <p class="error" role="alert">{{ session('error') }}</p>
    @endif
    @if($errors->any())
        <ul class="error" role="alert">
            @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    @if($isCurrentTaskDate && $isBoardClosed)
        <p class="message" role="status">Kanban hari ini sudah ditutup setelah absen pulang atau pukul 20.00.</p>
    @elseif($isCurrentTaskDate && $canCheckout)
        <section class="card kanban-checkout-panel">
            <p>Jika selesai bekerja, absen pulang akan menutup Kanban untuk hari ini.</p>
            <form action="{{ route('kanban.attendance.checkout') }}" method="POST">
                @csrf
                <button class="btn-primary" type="submit">Absen Pulang dan Tutup Kanban</button>
            </form>
        </section>
    @endif

    @if($isCurrentTaskDate && !auth()->user()->isOwner() && $attendance && !$isBoardClosed)
        <section class="card kanban-session-evidence">
            <h2>Bukti Foto Sesi</h2>
            @foreach(['morning' => 'Pagi', 'afternoon' => 'Siang'] as $session => $sessionLabel)
                <div class="kanban-session-upload">
                    <strong>{{ $sessionLabel }}</strong>
                    @if($attendance->sessionPhotosComplete($session))
                        <span class="kanban-session-status is-complete">Lengkap · 2/2 foto</span>
                    @elseif($canUploadSessionPhotos[$session])
                        <form
                            action="{{ route('kanban.attendance.session-photos.store', $session) }}"
                            method="POST"
                            enctype="multipart/form-data"
                        >
                            @csrf
                            <label for="kanban-session-photo-{{ $session }}-1">Foto 1</label>
                            <input id="kanban-session-photo-{{ $session }}-1" type="file" name="photos[0]" accept="image/jpeg,image/png,image/webp" required>
                            <label for="kanban-session-photo-{{ $session }}-2">Foto 2</label>
                            <input id="kanban-session-photo-{{ $session }}-2" type="file" name="photos[1]" accept="image/jpeg,image/png,image/webp" required>
                            <button class="btn" type="submit">Simpan foto {{ $sessionLabel }}</button>
                        </form>
                    @else
                        <span class="kanban-session-status is-incomplete">Belum lengkap · 2 foto wajib sebelum sesi ditutup</span>
                    @endif
                </div>
            @endforeach
        </section>
    @endif

    @if(!$canCreateTask && $isCurrentTaskDate && !auth()->user()->isOwner() && !$isBoardClosed)
        <p class="message" role="status">Pengisian pertama dibuka pukul {{ str_replace(':', '.', $firstEntryWindows['morning']['start']) }}-{{ str_replace(':', '.', $firstEntryWindows['morning']['end']) }} atau {{ str_replace(':', '.', $firstEntryWindows['afternoon']['start']) }}-{{ str_replace(':', '.', $firstEntryWindows['afternoon']['end']) }} jika sesi pertama terlewat. Jika belum mengisi pada sesi aktif, kehadiran Kanban belum tercatat.</p>
    @elseif(!$isCurrentTaskDate)
        <p class="message" role="status">Arsip tanggal {{ \Illuminate\Support\Carbon::parse($taskDate)->format('d/m/Y') }} bersifat hanya-baca.</p>
    @endif

    <p class="kanban-error" role="alert" data-kanban-error hidden></p>

    <div class="kanban-board" data-kanban-board>
        @foreach($columns as $status => $label)
            @php($columnTasks = $tasks->get($status, collect()))
            <section class="kanban-column">
                <header class="kanban-column-header">
                    <h2>{{ $label }}</h2>
                    <span class="kanban-count" data-kanban-count>{{ $columnTasks->count() }}</span>
                </header>
                <div class="kanban-dropzone" data-kanban-column="{{ $status }}" aria-label="{{ $label }}">
                    @foreach($columnTasks as $task)
                        @php($canManageTask = auth()->user()->isOwner() || (int) $task->assignee_id === auth()->id())
                        @php($canEditTask = $isCurrentTaskDate && $canManageTask && !$isBoardClosed)
                        <article
                            class="kanban-task-card"
                            data-kanban-task
                            @if($canEditTask)
                                data-status-url="{{ route('kanban.tasks.status', $task) }}"
                                data-status="{{ $task->status }}"
                                draggable="true"
                            @else
                                draggable="false"
                            @endif
                        >
                            <div class="kanban-task-heading">
                                <span class="kanban-task-status-label">{{ $columns[$task->status] }}</span>
                                @if($canEditTask)
                                    <form
                                        action="{{ route('kanban.tasks.destroy', $task) }}"
                                        method="POST"
                                        onsubmit="return confirm('Hapus tugas ini?')"
                                    >
                                        @csrf
                                        @method('DELETE')
                                        <button class="icon-button delete-button" type="submit" aria-label="Hapus tugas {{ $task->title }}" title="Hapus tugas">
                                            <x-layout.icon name="trash" />
                                        </button>
                                    </form>
                                @endif
                            </div>
                            <h3>{{ $task->title }}</h3>
                            @if($task->description)
                                <p>{{ $task->description }}</p>
                            @endif
                            <div class="kanban-task-assignee">
                                <span class="user-avatar">{{ mb_substr($task->assignee->name, 0, 1) }}</span>
                                <span>{{ $task->assignee->name }} · {{ $task->assignee->business_unit === 'factory' ? 'PF' : 'PB' }}</span>
                            </div>

                            @if($task->statusChanges->isNotEmpty())
                                <div class="kanban-status-history">
                                    <strong>Riwayat perubahan</strong>
                                    <ul>
                                        @foreach($task->statusChanges as $statusChange)
                                            <li>
                                                <span>
                                                    {{ $columns[$statusChange->from_status] ?? $statusChange->from_status }}
                                                    &rarr;
                                                    {{ $columns[$statusChange->to_status] ?? $statusChange->to_status }}
                                                </span>
                                                <small>
                                                    {{ $statusChange->changedBy?->name ?? 'Admin' }}
                                                    · {{ $statusChange->changed_at->copy()->setTimezone($task->assignee->timezone())->format('d/m H:i') }}
                                                </small>
                                                @if($statusChange->photo_path)
                                                    <a href="{{ route('kanban.status-changes.photo', $statusChange) }}" target="_blank" rel="noopener">Lihat foto</a>
                                                @endif
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif

                            @if($canEditTask)
                                <form
                                    action="{{ route('kanban.tasks.status', $task) }}"
                                    method="POST"
                                    enctype="multipart/form-data"
                                    class="kanban-status-form"
                                    data-kanban-status-form
                                >
                                    @csrf
                                    @method('PATCH')
                                    <label class="sr-only" for="kanban-status-{{ $task->id }}">Pindahkan {{ $task->title }}</label>
                                    <select id="kanban-status-{{ $task->id }}" name="status" data-kanban-status>
                                        @foreach($columns as $optionStatus => $optionLabel)
                                            <option value="{{ $optionStatus }}" @selected($task->status === $optionStatus)>{{ $optionLabel }}</option>
                                        @endforeach
                                    </select>
                                    <label class="kanban-photo-picker" for="kanban-photo-{{ $task->id }}" title="Pilih foto perpindahan">
                                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                            <rect x="3" y="4" width="18" height="16" rx="2" />
                                            <circle cx="8.5" cy="9" r="1.5" />
                                            <path d="m21 15-5-5L5 20" />
                                        </svg>
                                        <span class="sr-only">Pilih foto perpindahan (wajib)</span>
                                        <input id="kanban-photo-{{ $task->id }}" class="kanban-photo-input" type="file" name="photo" accept="image/jpeg,image/png,image/webp" aria-label="Foto perpindahan wajib" required>
                                    </label>
                                    <button class="btn" type="submit">Pindahkan</button>
                                </form>
                            @endif
                        </article>
                    @endforeach
                </div>
            </section>
        @endforeach
    </div>
@endsection
