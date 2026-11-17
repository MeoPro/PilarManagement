const initializeAttendanceForms = () => {
    document.querySelectorAll('[data-attendance-form]').forEach((form) => {
        if (form.dataset.attendanceInitialized === 'true') {
            return;
        }

        form.dataset.attendanceInitialized = 'true';
        form.addEventListener('submit', async (event) => {
            event.preventDefault();

            const submitButton = form.querySelector('[data-attendance-submit]');
            const feedback = document.querySelector('[data-attendance-feedback]');
            submitButton.disabled = true;
            feedback.textContent = '';
            feedback.hidden = true;
            feedback.classList.remove('error');

            try {
                const response = await fetch(form.action, {
                    method: 'POST',
                    body: new FormData(form),
                    headers: {
                        Accept: 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    credentials: 'same-origin',
                });
                const result = await response.json();

                if (!response.ok) {
                    const validationMessage = Object.values(result.errors ?? {}).flat().join(' ');
                    throw new Error(validationMessage || result.message || 'Absensi gagal disimpan.');
                }

                const cell = document.querySelector(
                    `[data-attendance-cell][data-employee-id="${result.employee_id}"][data-session="${result.session}"]`,
                );
                if (!cell) {
                    throw new Error('Data absensi tersimpan, tetapi tampilan tidak dapat diperbarui. Muat ulang halaman untuk melihat hasilnya.');
                }

                cell.innerHTML = result.attendance_cell;
                feedback.textContent = result.message;
                feedback.hidden = false;
            } catch (error) {
                feedback.textContent = error.message || 'Absensi gagal disimpan.';
                feedback.hidden = false;
                feedback.classList.add('error');
                submitButton.disabled = false;
            }
        });
    });
};

document.addEventListener('pilar:navigated', initializeAttendanceForms);
initializeAttendanceForms();
