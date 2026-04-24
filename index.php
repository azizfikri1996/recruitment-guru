<?php

declare(strict_types=1);

require_once __DIR__ . '/config.php';

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (DB_DRIVER === 'mysql') {
        $dsn = sprintf('mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4', DB_HOST, DB_PORT, DB_NAME);
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    } else {
        $dsn = 'sqlite:' . DB_SQLITE_PATH;
        $pdo = new PDO($dsn, null, null, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    return $pdo;
}

function initDb(): void
{
    $pdo = db();

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS admins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL
        )"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS subjects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL,
            description TEXT,
            is_active INTEGER NOT NULL DEFAULT 1
        )"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS questions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subject_id INTEGER NOT NULL,
            question_text TEXT NOT NULL,
            option_a TEXT NOT NULL,
            option_b TEXT NOT NULL,
            option_c TEXT NOT NULL,
            option_d TEXT NOT NULL,
            correct_option TEXT NOT NULL,
            FOREIGN KEY(subject_id) REFERENCES subjects(id)
        )"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS applicants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            email TEXT NOT NULL,
            phone TEXT,
            applied_subject_id INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY(applied_subject_id) REFERENCES subjects(id)
        )"
    );

    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            applicant_id INTEGER NOT NULL,
            subject_id INTEGER NOT NULL,
            score REAL NOT NULL,
            total_questions INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY(applicant_id) REFERENCES applicants(id),
            FOREIGN KEY(subject_id) REFERENCES subjects(id)
        )"
    );

    $stmt = $pdo->prepare('SELECT * FROM admins WHERE username = :username LIMIT 1');
    $stmt->execute([':username' => ADMIN_DEFAULT_USERNAME]);
    $admin = $stmt->fetch();

    if (!$admin) {
        $ins = $pdo->prepare('INSERT INTO admins (username, password_hash) VALUES (:u, :p)');
        $ins->execute([
            ':u' => ADMIN_DEFAULT_USERNAME,
            ':p' => password_hash(ADMIN_DEFAULT_PASSWORD, PASSWORD_BCRYPT),
        ]);
    }
}

function e(string $text): string
{
    return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
}

function setFlash(string $type, string $message): void
{
    $_SESSION['flash'][] = ['type' => $type, 'message' => $message];
}

function getFlash(): array
{
    $messages = $_SESSION['flash'] ?? [];
    unset($_SESSION['flash']);
    return $messages;
}

function isAdmin(): bool
{
    return isset($_SESSION['admin_id']) && (int) $_SESSION['admin_id'] > 0;
}

function requireAdmin(): void
{
    if (!isAdmin()) {
        setFlash('warning', 'Silakan login admin terlebih dahulu.');
        redirectTo('?page=admin_login');
    }
}

function redirectTo(string $url): void
{
    header('Location: ' . $url);
    exit;
}

function verifyWerkzeugPbkdf2(string $password, string $stored): bool
{
    if (strpos($stored, 'pbkdf2:') !== 0) {
        return false;
    }

    $parts = explode('$', $stored);
    if (count($parts) !== 3) {
        return false;
    }

    [$method, $salt, $hash] = $parts;
    $methodParts = explode(':', $method);
    if (count($methodParts) < 3) {
        return false;
    }

    $algo = $methodParts[1] ?? 'sha256';
    $iterations = (int) ($methodParts[2] ?? 260000);
    if ($iterations <= 0) {
        return false;
    }

    $computed = hash_pbkdf2($algo, $password, $salt, $iterations, 0, false);
    return hash_equals($hash, $computed);
}

function verifyPassword(string $password, string $storedHash): bool
{
    if (password_verify($password, $storedHash)) {
        return true;
    }

    return verifyWerkzeugPbkdf2($password, $storedHash);
}

function renderHeader(string $title): void
{
    $flashes = getFlash();
    ?>
<!doctype html>
<html lang="id">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title><?= e($title) ?></title>
  <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
</head>
<body class="bg-light">
<nav class="navbar navbar-expand-lg navbar-dark bg-primary mb-4">
  <div class="container">
    <a class="navbar-brand" href="index.php"><?= e(APP_NAME) ?></a>
    <div class="d-flex gap-2">
      <a class="btn btn-sm btn-light" href="?page=apply">Daftar Calon Guru</a>
      <?php if (isAdmin()): ?>
        <a class="btn btn-sm btn-warning" href="?page=admin_dashboard">Panel Admin</a>
        <a class="btn btn-sm btn-outline-light" href="?page=admin_logout">Logout</a>
      <?php endif; ?>
    </div>
  </div>
</nav>

<div class="container pb-5">
  <?php foreach ($flashes as $flash): ?>
    <div class="alert alert-<?= e($flash['type']) ?> alert-dismissible fade show" role="alert">
      <?= e($flash['message']) ?>
      <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
  <?php endforeach; ?>
<?php
}

function renderFooter(): void
{
    ?>
</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
}

initDb();
$pdo = db();
$page = $_GET['page'] ?? 'home';

if ($page === 'admin_logout') {
    session_destroy();
    session_start();
    setFlash('info', 'Logout berhasil.');
    redirectTo('index.php');
}

if ($page === 'admin_login') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $username = trim($_POST['username'] ?? '');
        $password = (string) ($_POST['password'] ?? '');

        $stmt = $pdo->prepare('SELECT * FROM admins WHERE username = :u LIMIT 1');
        $stmt->execute([':u' => $username]);
        $admin = $stmt->fetch();

        if ($admin && verifyPassword($password, (string) $admin['password_hash'])) {
            $_SESSION['admin_id'] = (int) $admin['id'];
            $_SESSION['admin_username'] = (string) $admin['username'];

            if (!password_get_info((string) $admin['password_hash'])['algo']) {
                $upd = $pdo->prepare('UPDATE admins SET password_hash = :p WHERE id = :id');
                $upd->execute([
                    ':p' => password_hash($password, PASSWORD_BCRYPT),
                    ':id' => (int) $admin['id'],
                ]);
            }

            setFlash('success', 'Login berhasil.');
            redirectTo('?page=admin_dashboard');
        }

        setFlash('danger', 'Username atau password salah.');
        redirectTo('?page=admin_login');
    }

    renderHeader('Login Admin');
    ?>
    <div class="row justify-content-center">
      <div class="col-md-5">
        <div class="card shadow-sm">
          <div class="card-header bg-white fw-semibold">Login Admin</div>
          <div class="card-body">
            <form method="post">
              <div class="mb-3">
                <label class="form-label">Username</label>
                <input type="text" name="username" class="form-control" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Password</label>
                <input type="password" name="password" class="form-control" required>
              </div>
              <button class="btn btn-primary w-100">Login</button>
            </form>
            <hr>
            <small class="text-muted">Default: admin / admin123</small>
          </div>
        </div>
      </div>
    </div>
    <?php
    renderFooter();
    exit;
}

if ($page === 'subject_toggle') {
    requireAdmin();
    $id = (int) ($_GET['id'] ?? 0);

    $stmt = $pdo->prepare('SELECT * FROM subjects WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $subject = $stmt->fetch();

    if (!$subject) {
        setFlash('danger', 'Mapel tidak ditemukan.');
        redirectTo('?page=admin_dashboard');
    }

    $newStatus = ((int) $subject['is_active'] === 1) ? 0 : 1;
    $upd = $pdo->prepare('UPDATE subjects SET is_active = :s WHERE id = :id');
    $upd->execute([':s' => $newStatus, ':id' => $id]);

    setFlash('info', 'Status mapel diperbarui.');
    redirectTo('?page=admin_dashboard');
}

if ($page === 'subject_new') {
    requireAdmin();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $name = trim($_POST['name'] ?? '');
        $description = trim($_POST['description'] ?? '');

        if ($name === '') {
            setFlash('warning', 'Nama mapel wajib diisi.');
            redirectTo('?page=subject_new');
        }

        try {
            $ins = $pdo->prepare('INSERT INTO subjects (name, description, is_active) VALUES (:n, :d, 1)');
            $ins->execute([':n' => $name, ':d' => $description]);
            setFlash('success', 'Mapel berhasil ditambahkan.');
            redirectTo('?page=admin_dashboard');
        } catch (Throwable $e) {
            setFlash('danger', 'Nama mapel sudah ada / tidak valid.');
            redirectTo('?page=subject_new');
        }
    }

    renderHeader('Tambah Mapel');
    ?>
    <div class="row justify-content-center">
      <div class="col-md-7">
        <div class="card shadow-sm">
          <div class="card-header bg-white fw-semibold">Tambah Mata Pelajaran</div>
          <div class="card-body">
            <form method="post">
              <div class="mb-3">
                <label class="form-label">Nama Mapel</label>
                <input type="text" name="name" class="form-control" required>
              </div>
              <div class="mb-3">
                <label class="form-label">Deskripsi</label>
                <textarea name="description" class="form-control" rows="3"></textarea>
              </div>
              <div class="d-flex justify-content-between">
                <a href="?page=admin_dashboard" class="btn btn-outline-secondary">Kembali</a>
                <button class="btn btn-primary">Simpan</button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
    <?php
    renderFooter();
    exit;
}

if ($page === 'question_new') {
    requireAdmin();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $subjectId = (int) ($_POST['subject_id'] ?? 0);
        $question = trim($_POST['question_text'] ?? '');
        $a = trim($_POST['option_a'] ?? '');
        $b = trim($_POST['option_b'] ?? '');
        $c = trim($_POST['option_c'] ?? '');
        $d = trim($_POST['option_d'] ?? '');
        $correct = strtoupper(trim($_POST['correct_option'] ?? ''));

        if ($subjectId <= 0 || $question === '' || $a === '' || $b === '' || $c === '' || $d === '' || !in_array($correct, ['A', 'B', 'C', 'D'], true)) {
            setFlash('warning', 'Lengkapi semua field soal dengan benar.');
            redirectTo('?page=question_new');
        }

        $ins = $pdo->prepare('INSERT INTO questions (subject_id, question_text, option_a, option_b, option_c, option_d, correct_option) VALUES (:s, :q, :a, :b, :c, :d, :k)');
        $ins->execute([
            ':s' => $subjectId,
            ':q' => $question,
            ':a' => $a,
            ':b' => $b,
            ':c' => $c,
            ':d' => $d,
            ':k' => $correct,
        ]);

        setFlash('success', 'Soal berhasil ditambahkan.');
        redirectTo('?page=admin_dashboard');
    }

    $subjects = $pdo->query('SELECT id, name FROM subjects ORDER BY name')->fetchAll();

    renderHeader('Tambah Soal');
    ?>
    <div class="row justify-content-center">
      <div class="col-lg-9">
        <div class="card shadow-sm">
          <div class="card-header bg-white fw-semibold">Tambah Soal Tes</div>
          <div class="card-body">
            <form method="post">
              <div class="mb-3">
                <label class="form-label">Mata Pelajaran</label>
                <select name="subject_id" class="form-select" required>
                  <option value="">Pilih mapel...</option>
                  <?php foreach ($subjects as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"><?= e((string) $s['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">Pertanyaan</label>
                <textarea name="question_text" class="form-control" rows="3" required></textarea>
              </div>
              <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Pilihan A</label><input name="option_a" class="form-control" required></div>
                <div class="col-md-6"><label class="form-label">Pilihan B</label><input name="option_b" class="form-control" required></div>
                <div class="col-md-6"><label class="form-label">Pilihan C</label><input name="option_c" class="form-control" required></div>
                <div class="col-md-6"><label class="form-label">Pilihan D</label><input name="option_d" class="form-control" required></div>
              </div>
              <div class="mt-3 mb-4">
                <label class="form-label">Jawaban Benar</label>
                <select name="correct_option" class="form-select" required>
                  <option value="">Pilih...</option><option>A</option><option>B</option><option>C</option><option>D</option>
                </select>
              </div>
              <div class="d-flex justify-content-between">
                <a href="?page=admin_dashboard" class="btn btn-outline-secondary">Kembali</a>
                <button class="btn btn-primary">Simpan Soal</button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
    <?php
    renderFooter();
    exit;
}

if ($page === 'question_bulk') {
    requireAdmin();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $defaultSubject = (int) ($_POST['subject_id'] ?? 0);

        if (!isset($_FILES['questions_file']) || !is_uploaded_file($_FILES['questions_file']['tmp_name'])) {
            setFlash('warning', 'Pilih file CSV terlebih dahulu.');
            redirectTo('?page=question_bulk');
        }

        $csv = file_get_contents($_FILES['questions_file']['tmp_name']);
        if ($csv === false) {
            setFlash('danger', 'Gagal membaca file CSV.');
            redirectTo('?page=question_bulk');
        }

        $rows = array_map('str_getcsv', preg_split('/\r\n|\r|\n/', trim($csv)));
        if (count($rows) < 2) {
            setFlash('danger', 'Isi CSV tidak valid.');
            redirectTo('?page=question_bulk');
        }

        $headers = array_map('trim', $rows[0]);
        $idx = array_flip($headers);
        $required = ['question_text', 'option_a', 'option_b', 'option_c', 'option_d', 'correct_option'];

        foreach ($required as $r) {
            if (!isset($idx[$r])) {
                setFlash('danger', 'Header CSV wajib: ' . implode(', ', $required));
                redirectTo('?page=question_bulk');
            }
        }

        $subjectNameToId = [];
        $subjectRows = $pdo->query('SELECT id, name FROM subjects')->fetchAll();
        foreach ($subjectRows as $s) {
            $subjectNameToId[strtolower(trim((string) $s['name']))] = (int) $s['id'];
        }

        $inserted = 0;
        for ($i = 1; $i < count($rows); $i++) {
            $row = $rows[$i];
            if (count($row) === 1 && trim((string) $row[0]) === '') {
                continue;
            }

            $question = trim((string) ($row[$idx['question_text']] ?? ''));
            $a = trim((string) ($row[$idx['option_a']] ?? ''));
            $b = trim((string) ($row[$idx['option_b']] ?? ''));
            $c = trim((string) ($row[$idx['option_c']] ?? ''));
            $d = trim((string) ($row[$idx['option_d']] ?? ''));
            $correct = strtoupper(trim((string) ($row[$idx['correct_option']] ?? '')));

            $subjectId = $defaultSubject;
            if ($subjectId <= 0 && isset($idx['subject_name'])) {
                $subjectName = strtolower(trim((string) ($row[$idx['subject_name']] ?? '')));
                $subjectId = $subjectNameToId[$subjectName] ?? 0;
            }

            if ($subjectId <= 0 || $question === '' || $a === '' || $b === '' || $c === '' || $d === '' || !in_array($correct, ['A', 'B', 'C', 'D'], true)) {
                continue;
            }

            $ins = $pdo->prepare('INSERT INTO questions (subject_id, question_text, option_a, option_b, option_c, option_d, correct_option) VALUES (:s,:q,:a,:b,:c,:d,:k)');
            $ins->execute([
                ':s' => $subjectId,
                ':q' => $question,
                ':a' => $a,
                ':b' => $b,
                ':c' => $c,
                ':d' => $d,
                ':k' => $correct,
            ]);
            $inserted++;
        }

        setFlash('success', 'Bulk upload selesai. Berhasil: ' . $inserted . ' soal.');
        redirectTo('?page=admin_dashboard');
    }

    $subjects = $pdo->query('SELECT id, name FROM subjects ORDER BY name')->fetchAll();

    renderHeader('Bulk Upload Soal');
    ?>
    <div class="row justify-content-center">
      <div class="col-lg-10">
        <div class="card shadow-sm mb-3">
          <div class="card-header bg-white fw-semibold">Bulk Upload Soal (CSV)</div>
          <div class="card-body">
            <a class="btn btn-outline-primary" href="static/templates/question_bulk_template.csv">Download Template CSV</a>
          </div>
        </div>
        <div class="card shadow-sm">
          <div class="card-body">
            <form method="post" enctype="multipart/form-data">
              <div class="mb-3">
                <label class="form-label">Mapel Default (opsional)</label>
                <select name="subject_id" class="form-select">
                  <option value="">Gunakan subject_name dari CSV</option>
                  <?php foreach ($subjects as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"><?= e((string) $s['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-3">
                <label class="form-label">File CSV</label>
                <input type="file" name="questions_file" class="form-control" accept=".csv" required>
              </div>
              <div class="d-flex justify-content-between">
                <a href="?page=admin_dashboard" class="btn btn-outline-secondary">Kembali</a>
                <button class="btn btn-primary">Upload</button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
    <?php
    renderFooter();
    exit;
}

if ($page === 'question_edit') {
    requireAdmin();
    $id = (int) ($_GET['id'] ?? 0);

    $stmt = $pdo->prepare('SELECT * FROM questions WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $question = $stmt->fetch();
    if (!$question) {
        setFlash('danger', 'Soal tidak ditemukan.');
        redirectTo('?page=admin_dashboard');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $subjectId = (int) ($_POST['subject_id'] ?? 0);
        $q = trim($_POST['question_text'] ?? '');
        $a = trim($_POST['option_a'] ?? '');
        $b = trim($_POST['option_b'] ?? '');
        $c = trim($_POST['option_c'] ?? '');
        $d = trim($_POST['option_d'] ?? '');
        $k = strtoupper(trim($_POST['correct_option'] ?? ''));

        if ($subjectId <= 0 || $q === '' || $a === '' || $b === '' || $c === '' || $d === '' || !in_array($k, ['A', 'B', 'C', 'D'], true)) {
            setFlash('warning', 'Lengkapi semua field dengan benar.');
            redirectTo('?page=question_edit&id=' . $id);
        }

        $upd = $pdo->prepare('UPDATE questions SET subject_id=:s, question_text=:q, option_a=:a, option_b=:b, option_c=:c, option_d=:d, correct_option=:k WHERE id=:id');
        $upd->execute([
            ':s' => $subjectId, ':q' => $q, ':a' => $a, ':b' => $b, ':c' => $c, ':d' => $d, ':k' => $k, ':id' => $id,
        ]);

        setFlash('success', 'Soal berhasil diperbarui.');
        redirectTo('?page=admin_dashboard');
    }

    $subjects = $pdo->query('SELECT id, name FROM subjects ORDER BY name')->fetchAll();

    renderHeader('Edit Soal');
    ?>
    <div class="row justify-content-center">
      <div class="col-lg-9">
        <div class="card shadow-sm">
          <div class="card-header bg-white fw-semibold">Edit Soal Tes</div>
          <div class="card-body">
            <form method="post">
              <div class="mb-3">
                <label class="form-label">Mata Pelajaran</label>
                <select name="subject_id" class="form-select" required>
                  <?php foreach ($subjects as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= ((int) $question['subject_id'] === (int) $s['id']) ? 'selected' : '' ?>><?= e((string) $s['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="mb-3"><label class="form-label">Pertanyaan</label><textarea name="question_text" class="form-control" rows="3" required><?= e((string) $question['question_text']) ?></textarea></div>
              <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Pilihan A</label><input name="option_a" value="<?= e((string) $question['option_a']) ?>" class="form-control" required></div>
                <div class="col-md-6"><label class="form-label">Pilihan B</label><input name="option_b" value="<?= e((string) $question['option_b']) ?>" class="form-control" required></div>
                <div class="col-md-6"><label class="form-label">Pilihan C</label><input name="option_c" value="<?= e((string) $question['option_c']) ?>" class="form-control" required></div>
                <div class="col-md-6"><label class="form-label">Pilihan D</label><input name="option_d" value="<?= e((string) $question['option_d']) ?>" class="form-control" required></div>
              </div>
              <div class="mt-3 mb-4">
                <label class="form-label">Jawaban Benar</label>
                <select name="correct_option" class="form-select" required>
                  <?php foreach (['A', 'B', 'C', 'D'] as $opt): ?>
                    <option value="<?= $opt ?>" <?= ((string) $question['correct_option'] === $opt) ? 'selected' : '' ?>><?= $opt ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div class="d-flex justify-content-between">
                <a href="?page=admin_dashboard" class="btn btn-outline-secondary">Kembali</a>
                <button class="btn btn-primary">Simpan Perubahan</button>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
    <?php
    renderFooter();
    exit;
}

if ($page === 'admin_dashboard') {
    requireAdmin();

    $selectedSubjectId = (int) ($_GET['subject_id'] ?? 0);

    $subjects = $pdo->query(
        'SELECT s.*, COUNT(q.id) AS total_questions
         FROM subjects s
         LEFT JOIN questions q ON q.subject_id = s.id
         GROUP BY s.id
         ORDER BY s.name'
    )->fetchAll();

    $attempts = $pdo->query(
        'SELECT a.id, ap.full_name, ap.email, s.name AS subject_name, a.score, a.total_questions, a.created_at
         FROM attempts a
         JOIN applicants ap ON ap.id = a.applicant_id
         JOIN subjects s ON s.id = a.subject_id
         ORDER BY a.id DESC
         LIMIT 50'
    )->fetchAll();

    if ($selectedSubjectId > 0) {
        $qStmt = $pdo->prepare(
            'SELECT q.id, q.question_text, q.correct_option, s.name AS subject_name
             FROM questions q
             JOIN subjects s ON s.id = q.subject_id
             WHERE q.subject_id = :sid
             ORDER BY q.id DESC
             LIMIT 100'
        );
        $qStmt->execute([':sid' => $selectedSubjectId]);
        $questions = $qStmt->fetchAll();
    } else {
        $questions = $pdo->query(
            'SELECT q.id, q.question_text, q.correct_option, s.name AS subject_name
             FROM questions q
             JOIN subjects s ON s.id = q.subject_id
             ORDER BY q.id DESC
             LIMIT 100'
        )->fetchAll();
    }

    renderHeader('Panel Admin');
    ?>
    <div class="d-flex justify-content-between align-items-center mb-3">
      <h3 class="mb-0">Panel Admin</h3>
      <a class="btn btn-outline-danger" href="?page=admin_logout">Logout</a>
    </div>

    <div class="row g-3">
      <div class="col-lg-3">
        <div class="card shadow-sm sticky-top" style="top:1rem;">
          <div class="card-header bg-white fw-semibold">Menu Admin</div>
          <div class="list-group list-group-flush">
            <div class="list-group-item">
              <div class="fw-semibold mb-2">1. Mapel</div>
              <div class="d-grid gap-2">
                <a class="btn btn-sm btn-success" href="?page=subject_new">a. Tambah Mapel</a>
                <a class="btn btn-sm btn-secondary" href="?page=question_new">a. Soal (Tambah Manual)</a>
                <a class="btn btn-sm btn-dark" href="?page=question_bulk">a. Bulk Upload Soal</a>
              </div>
            </div>
            <div class="list-group-item">
              <div class="fw-semibold mb-2">2. Daftar Mapel untuk Soal</div>
              <form method="get" class="d-grid gap-2">
                <input type="hidden" name="page" value="admin_dashboard">
                <label class="form-label mb-0 small text-muted">a. Filter Lihat Soal per Mapel</label>
                <select name="subject_id" class="form-select form-select-sm">
                  <option value="">Semua mata pelajaran</option>
                  <?php foreach ($subjects as $s): ?>
                    <option value="<?= (int) $s['id'] ?>" <?= ($selectedSubjectId === (int) $s['id']) ? 'selected' : '' ?>><?= e((string) $s['name']) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn btn-sm btn-primary">Tampilkan Soal</button>
              </form>
            </div>
          </div>
        </div>
      </div>

      <div class="col-lg-9">
        <div class="row g-3 mb-3">
          <div class="col-md-6">
            <div class="card shadow-sm h-100">
              <div class="card-header bg-white fw-semibold">Pengaturan Mata Pelajaran</div>
              <div class="card-body">
                <div class="table-responsive">
                  <table class="table table-bordered align-middle">
                    <thead>
                      <tr><th>Mapel</th><th>Soal</th><th>Status</th><th>Aksi</th></tr>
                    </thead>
                    <tbody>
                      <?php foreach ($subjects as $s): ?>
                        <tr>
                          <td><strong><?= e((string) $s['name']) ?></strong><br><small class="text-muted"><?= e((string) ($s['description'] ?? '-')) ?></small></td>
                          <td><?= (int) $s['total_questions'] ?></td>
                          <td>
                            <?php if ((int) $s['is_active'] === 1): ?>
                              <span class="badge text-bg-success">Aktif</span>
                            <?php else: ?>
                              <span class="badge text-bg-secondary">Nonaktif</span>
                            <?php endif; ?>
                          </td>
                          <td>
                            <a class="btn btn-sm <?= ((int) $s['is_active'] === 1) ? 'btn-danger' : 'btn-success' ?>" href="?page=subject_toggle&id=<?= (int) $s['id'] ?>">
                              <?= ((int) $s['is_active'] === 1) ? 'INACTIVE' : 'ACTIVE' ?>
                            </a>
                          </td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>

          <div class="col-md-6">
            <div class="card shadow-sm h-100">
              <div class="card-header bg-white fw-semibold">Riwayat Hasil Tes (50 Terbaru)</div>
              <div class="card-body">
                <div class="table-responsive">
                  <table class="table table-striped table-sm align-middle">
                    <thead><tr><th>Nama</th><th>Mapel</th><th>Skor</th><th>Waktu</th></tr></thead>
                    <tbody>
                      <?php foreach ($attempts as $a): ?>
                        <tr>
                          <td><strong><?= e((string) $a['full_name']) ?></strong><br><small class="text-muted"><?= e((string) $a['email']) ?></small></td>
                          <td><?= e((string) $a['subject_name']) ?></td>
                          <td><?= e((string) $a['score']) ?> / 100</td>
                          <td><small><?= e((string) $a['created_at']) ?></small></td>
                        </tr>
                      <?php endforeach; ?>
                    </tbody>
                  </table>
                </div>
              </div>
            </div>
          </div>
        </div>

        <div class="card shadow-sm">
          <div class="card-header bg-white fw-semibold">Daftar Soal (100 Terbaru)</div>
          <div class="card-body">
            <div class="table-responsive">
              <table class="table table-sm table-bordered align-middle">
                <thead><tr><th>ID</th><th>Mapel</th><th>Pertanyaan</th><th>Kunci</th><th>Aksi</th></tr></thead>
                <tbody>
                  <?php foreach ($questions as $q): ?>
                    <tr>
                      <td><?= (int) $q['id'] ?></td>
                      <td><?= e((string) $q['subject_name']) ?></td>
                      <td><?= e((string) $q['question_text']) ?></td>
                      <td><span class="badge text-bg-primary"><?= e((string) $q['correct_option']) ?></span></td>
                      <td><a class="btn btn-sm btn-outline-warning" href="?page=question_edit&id=<?= (int) $q['id'] ?>">Edit</a></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>
      </div>
    </div>
    <?php
    renderFooter();
    exit;
}

if ($page === 'apply') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $fullName = trim($_POST['full_name'] ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $subjectId = (int) ($_POST['applied_subject_id'] ?? 0);

        if ($fullName === '' || $email === '' || $subjectId <= 0) {
            setFlash('warning', 'Nama, email, dan mapel wajib diisi.');
            redirectTo('?page=apply');
        }

        $ins = $pdo->prepare('INSERT INTO applicants (full_name, email, phone, applied_subject_id, created_at) VALUES (:n,:e,:p,:s,:c)');
        $ins->execute([
            ':n' => $fullName,
            ':e' => $email,
            ':p' => $phone,
            ':s' => $subjectId,
            ':c' => date('Y-m-d H:i:s'),
        ]);

        $applicantId = (int) $pdo->lastInsertId();
        redirectTo('?page=test&applicant_id=' . $applicantId);
    }

    $subjects = $pdo->query('SELECT id, name FROM subjects WHERE is_active = 1 ORDER BY name')->fetchAll();

    renderHeader('Form Pendaftaran Calon Guru');
    ?>
    <div class="row justify-content-center">
      <div class="col-lg-7">
        <div class="card shadow-sm">
          <div class="card-header bg-white fw-semibold">Form Pendaftaran Calon Guru</div>
          <div class="card-body">
            <form method="post">
              <div class="mb-3"><label class="form-label">Nama Lengkap</label><input type="text" name="full_name" class="form-control" required></div>
              <div class="mb-3"><label class="form-label">Email</label><input type="email" name="email" class="form-control" required></div>
              <div class="mb-3"><label class="form-label">No HP</label><input type="text" name="phone" class="form-control"></div>
              <div class="mb-4">
                <label class="form-label">Lamaran Guru Mapel</label>
                <select name="applied_subject_id" class="form-select" required>
                  <option value="">Pilih mapel...</option>
                  <?php foreach ($subjects as $s): ?>
                    <option value="<?= (int) $s['id'] ?>"><?= e((string) $s['name']) ?></option>
                  <?php endforeach; ?>
                </select>
              </div>
              <button class="btn btn-primary w-100">Lanjut Mulai Tes</button>
            </form>
          </div>
        </div>
      </div>
    </div>
    <?php
    renderFooter();
    exit;
}

if ($page === 'test') {
    $applicantId = (int) ($_GET['applicant_id'] ?? 0);

    $stmt = $pdo->prepare('SELECT a.*, s.name AS subject_name FROM applicants a JOIN subjects s ON s.id = a.applied_subject_id WHERE a.id = :id');
    $stmt->execute([':id' => $applicantId]);
    $applicant = $stmt->fetch();

    if (!$applicant) {
        setFlash('danger', 'Pelamar tidak ditemukan.');
        redirectTo('?page=apply');
    }

    $chk = $pdo->prepare('SELECT id FROM attempts WHERE applicant_id = :id LIMIT 1');
    $chk->execute([':id' => $applicantId]);
    $attempt = $chk->fetch();
    if ($attempt) {
        redirectTo('?page=result&attempt_id=' . (int) $attempt['id']);
    }

    $qStmt = $pdo->prepare('SELECT * FROM questions WHERE subject_id = :sid ORDER BY id');
    $qStmt->execute([':sid' => (int) $applicant['applied_subject_id']]);
    $questions = $qStmt->fetchAll();

    if (!$questions) {
        setFlash('warning', 'Belum ada soal untuk mapel ini.');
        redirectTo('index.php');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $correct = 0;
        foreach ($questions as $q) {
            $answer = strtoupper(trim($_POST['q_' . $q['id']] ?? ''));
            if ($answer === strtoupper((string) $q['correct_option'])) {
                $correct++;
            }
        }

        $total = count($questions);
        $score = round(($correct / max($total, 1)) * 100, 2);

        $ins = $pdo->prepare('INSERT INTO attempts (applicant_id, subject_id, score, total_questions, created_at) VALUES (:a,:s,:sc,:t,:c)');
        $ins->execute([
            ':a' => $applicantId,
            ':s' => (int) $applicant['applied_subject_id'],
            ':sc' => $score,
            ':t' => $total,
            ':c' => date('Y-m-d H:i:s'),
        ]);

        redirectTo('?page=result&attempt_id=' . (int) $pdo->lastInsertId());
    }

    renderHeader('Tes Calon Guru');
    ?>
    <div class="card shadow-sm mb-3">
      <div class="card-body">
        <h4 class="mb-1">Tes Seleksi Guru <?= e((string) $applicant['subject_name']) ?></h4>
        <p class="mb-0">Peserta: <strong><?= e((string) $applicant['full_name']) ?></strong> (<?= e((string) $applicant['email']) ?>)</p>
      </div>
    </div>

    <form method="post" class="card shadow-sm">
      <div class="card-body">
        <?php foreach ($questions as $idx => $q): ?>
          <div class="mb-4">
            <p class="fw-semibold mb-2"><?= $idx + 1 ?>. <?= e((string) $q['question_text']) ?></p>
            <?php foreach (['A', 'B', 'C', 'D'] as $opt): ?>
              <div class="form-check">
                <input class="form-check-input" type="radio" name="q_<?= (int) $q['id'] ?>" value="<?= $opt ?>" id="q<?= (int) $q['id'] . strtolower($opt) ?>" <?= $opt === 'A' ? 'required' : '' ?>>
                <label class="form-check-label" for="q<?= (int) $q['id'] . strtolower($opt) ?>"><?= $opt ?>. <?= e((string) $q['option_' . strtolower($opt)]) ?></label>
              </div>
            <?php endforeach; ?>
          </div>
          <hr>
        <?php endforeach; ?>
        <button class="btn btn-success btn-lg">Kirim Jawaban</button>
      </div>
    </form>
    <?php
    renderFooter();
    exit;
}

if ($page === 'result') {
    $attemptId = (int) ($_GET['attempt_id'] ?? 0);

    $stmt = $pdo->prepare(
        'SELECT a.*, ap.full_name, ap.email, s.name AS subject_name
         FROM attempts a
         JOIN applicants ap ON ap.id = a.applicant_id
         JOIN subjects s ON s.id = a.subject_id
         WHERE a.id = :id'
    );
    $stmt->execute([':id' => $attemptId]);
    $attempt = $stmt->fetch();

    if (!$attempt) {
        setFlash('danger', 'Hasil tidak ditemukan.');
        redirectTo('index.php');
    }

    renderHeader('Hasil Tes');
    ?>
    <div class="row justify-content-center">
      <div class="col-lg-6">
        <div class="card shadow-sm text-center">
          <div class="card-body p-4">
            <h3 class="mb-3">Hasil Tes Seleksi</h3>
            <p class="mb-1">Nama: <strong><?= e((string) $attempt['full_name']) ?></strong></p>
            <p class="mb-1">Mapel: <strong><?= e((string) $attempt['subject_name']) ?></strong></p>
            <p class="mb-3">Tanggal: <?= e((string) $attempt['created_at']) ?></p>
            <div class="display-5 fw-bold text-primary mb-2"><?= e((string) $attempt['score']) ?></div>
            <p class="text-muted">Skor dari 100 (<?= (int) $attempt['total_questions'] ?> soal)</p>
            <?php if ((float) $attempt['score'] >= 75): ?>
              <div class="alert alert-success">Lolos tahap tes awal.</div>
            <?php else: ?>
              <div class="alert alert-warning">Belum memenuhi passing grade tes awal.</div>
            <?php endif; ?>
            <a href="index.php" class="btn btn-primary">Kembali ke Beranda</a>
          </div>
        </div>
      </div>
    </div>
    <?php
    renderFooter();
    exit;
}

$activeSubjects = $pdo->query('SELECT id, name, description FROM subjects WHERE is_active = 1 ORDER BY name')->fetchAll();

renderHeader(APP_NAME);
?>
<div class="p-4 p-md-5 mb-4 bg-white rounded-3 shadow-sm">
  <h1 class="display-6">Sistem Penerimaan Guru Baru (PHP)</h1>
  <p class="lead mb-3">2 sisi: admin untuk setting mapel & soal, calon guru untuk isi data diri lalu tes otomatis.</p>
  <div class="d-flex gap-2">
    <a class="btn btn-primary btn-lg" href="?page=apply">Mulai Daftar & Tes</a>
    <a class="btn btn-outline-dark btn-lg" href="?page=admin_login">Panel Admin</a>
  </div>
</div>

<div class="card shadow-sm">
  <div class="card-header bg-white fw-semibold">Mata Pelajaran Aktif</div>
  <div class="card-body">
    <?php if ($activeSubjects): ?>
      <ul class="list-group">
        <?php foreach ($activeSubjects as $subject): ?>
          <li class="list-group-item d-flex justify-content-between align-items-center">
            <div>
              <strong><?= e((string) $subject['name']) ?></strong><br>
              <small class="text-muted"><?= e((string) ($subject['description'] ?? '-')) ?></small>
            </div>
            <span class="badge text-bg-success">Aktif</span>
          </li>
        <?php endforeach; ?>
      </ul>
    <?php else: ?>
      <p class="text-muted mb-0">Belum ada mapel aktif.</p>
    <?php endif; ?>
  </div>
</div>
<?php
renderFooter();
