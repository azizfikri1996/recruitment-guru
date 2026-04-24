# Sistem Penerimaan Guru Baru (Flask)

Fitur utama:
- **Sisi Admin**
  - Login admin
  - Tambah mapel (custom)
  - Aktif/nonaktifkan mapel tes
  - Tambah bank soal per mapel
   - Bulk upload soal via CSV (tanpa batas)
  - Lihat hasil tes calon guru
- **Sisi Calon Guru**
  - Isi data diri
  - Pilih mapel yang dilamar
  - Ikuti tes sesuai mapel
  - Lihat skor otomatis

## Cara jalankan

1. Buat virtual environment (opsional)
2. Install dependency:
   - `pip install -r requirements.txt`
3. Jalankan app:
   - `python app.py`
4. Buka browser:
   - `http://127.0.0.1:5000`

## Login admin default
- URL: `/panel-admin/login`
- Username: `admin`
- Password: `admin123`

## Format bulk upload soal (CSV)

Silakan download template dari menu admin: **Bulk Upload Soal**.

Header CSV:
- `subject_name` (opsional jika mapel default dipilih saat upload)
- `question_text`
- `option_a`
- `option_b`
- `option_c`
- `option_d`
- `correct_option` (isi `A` / `B` / `C` / `D`)

> Setelah login pertama, sebaiknya ubah password di kode/database untuk keamanan.


> ada soal essay 