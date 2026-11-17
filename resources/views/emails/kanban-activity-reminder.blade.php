<p>Halo,</p>

@if($ownerAlert)
    <p>Admin berikut belum mengubah status Kanban pada {{ $notificationDate }}:</p>
    <ul>
        @foreach($adminNames as $adminName)
            <li>{{ $adminName }}</li>
        @endforeach
    </ul>
@else
    <p>Pengingat untuk memperbarui status tugas Kanban Anda pada {{ $notificationDate }}.</p>
@endif

<p>Mohon perbarui status tugas agar aktivitas hari ini tercatat.</p>
