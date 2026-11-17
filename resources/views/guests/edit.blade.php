@extends('layouts.admin')

@section('title', 'Edit Tamu')

@section('content')
    @php
        $phoneValues = old('phones', $guest->phones->pluck('phone')->all());
        $phoneValues = is_array($phoneValues) ? array_values($phoneValues) : [$phoneValues];
        $phoneValues = $phoneValues === [] ? [''] : $phoneValues;
    @endphp

    <div class="page-heading">
        <div>
            <p class="page-eyebrow">Pelanggan</p>
            <h1>Edit Tamu</h1>
            <p>Nama dan alamat akan diperbarui pada order terkait. Nomor telepon pada order tetap sesuai transaksi.</p>
        </div>
    </div>

    <div class="card guest-form-card">
        @if($errors->any())
            <ul class="error">
                @foreach($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        @endif

        <form action="{{ route('guests.update', $guest) }}" method="POST">
            @csrf
            @method('PUT')

            <label for="name">Nama tamu</label>
            <input id="name" name="name" value="{{ old('name', $guest->name) }}" maxlength="255" required>

            <div id="guest-phone-fields">
                @foreach($phoneValues as $index => $phone)
                    <div class="guest-phone-row">
                        <label for="guest-phone-{{ $index }}">Nomor telepon {{ $index + 1 }}</label>
                        <input
                            id="guest-phone-{{ $index }}"
                            type="tel"
                            name="phones[]"
                            value="{{ $phone }}"
                            maxlength="255"
                        >
                        <button class="btn btn-secondary" type="button" data-remove-guest-phone>Hapus</button>
                    </div>
                @endforeach
            </div>
            <button class="btn btn-secondary" id="add-guest-phone" type="button">+ Tambah Nomor</button>
            <small>Nomor yang dihapus dari form akan dihapus dari data tamu. Nomor historis di order tetap tersimpan.</small>

            <label for="address">Alamat</label>
            <textarea id="address" name="address" rows="4" maxlength="2000">{{ old('address', $guest->address) }}</textarea>

            <div class="employee-form-actions">
                <button class="btn-primary" type="submit">Simpan Perubahan</button>
                <a class="btn" href="{{ route('guests.show', $guest) }}">Kembali</a>
            </div>
        </form>
    </div>

    <script>
        const guestPhoneFields = document.getElementById('guest-phone-fields');
        const addGuestPhoneButton = document.getElementById('add-guest-phone');

        const updateGuestPhoneFieldLabels = () => {
            Array.from(guestPhoneFields.children).forEach((row, index) => {
                const label = row.querySelector('label');
                const input = row.querySelector('input');
                const id = `guest-phone-${index}`;

                label.htmlFor = id;
                label.textContent = `Nomor telepon ${index + 1}`;
                input.id = id;
            });
        };

        const addGuestPhoneField = () => {
            const index = guestPhoneFields.children.length;
            const row = document.createElement('div');
            const label = document.createElement('label');
            const input = document.createElement('input');
            const removeButton = document.createElement('button');

            row.className = 'guest-phone-row';
            label.htmlFor = `guest-phone-${index}`;
            label.textContent = `Nomor telepon ${index + 1}`;
            input.id = `guest-phone-${index}`;
            input.type = 'tel';
            input.name = 'phones[]';
            input.maxLength = 255;
            removeButton.className = 'btn btn-secondary';
            removeButton.type = 'button';
            removeButton.dataset.removeGuestPhone = '';
            removeButton.textContent = 'Hapus';

            row.append(label, input, removeButton);
            guestPhoneFields.append(row);
            updateGuestPhoneFieldLabels();
            input.focus();
        };

        addGuestPhoneButton.addEventListener('click', addGuestPhoneField);

        guestPhoneFields.addEventListener('click', (event) => {
            if (!(event.target instanceof Element) || !event.target.matches('[data-remove-guest-phone]')) {
                return;
            }

            event.target.closest('.guest-phone-row').remove();

            if (guestPhoneFields.children.length === 0) {
                addGuestPhoneField();
            } else {
                updateGuestPhoneFieldLabels();
            }
        });
    </script>
@endsection
