# Sistem Penerimaan Guru Baru (PHP)

Fitur utama:
- **Sisi Admin**
  - Login admin
  - Tambah mapel (custom)
  - Aktif/nonaktifkan mapel tes
  - Tambah bank soal per mapel
  - Bulk upload soal via CSV (tanpa batas)
  - Edit soal
  - Filter soal per mapel
  - Lihat hasil tes calon guru
- **Sisi Calon Guru**
  - Isi data diri
  - Pilih mapel yang dilamar
  - Ikuti tes sesuai mapel
  - Lihat skor otomatis

## Struktur utama (versi PHP)

- [index.php](index.php) → aplikasi utama
- [config.php](config.php) → konfigurasi DB dan default admin
- [static/templates/question_bulk_template.csv](static/templates/question_bulk_template.csv) → template CSV bulk upload
- Database SQLite default: [recruitment.db](recruitment.db)

## Jalankan lokal

Gunakan web server PHP bawaan:

1. Buka terminal di folder project
2. Jalankan:
   - `php -S 127.0.0.1:8000`
3. Buka browser:
   - `http://127.0.0.1:8000`

## Deploy ke cPanel (PHP)

1. Upload semua file project ke `public_html` (atau subfolder domain).
2. Pastikan file [index.php](index.php) ada di root folder web.
3. Pastikan permission write untuk file DB SQLite ([recruitment.db](recruitment.db)).
4. Jika ingin pakai MySQL, edit [config.php](config.php):
   - ubah `DB_DRIVER` menjadi `mysql`
   - isi `DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASS`
5. Buka URL domain, aplikasi langsung jalan tanpa Python/Flask.

## Login admin default
- URL: `/?page=admin_login`
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

> Setelah login pertama, sebaiknya ubah password admin.