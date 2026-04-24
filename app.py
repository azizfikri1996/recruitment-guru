import os
import sqlite3
import csv
import io
from datetime import datetime
from functools import wraps

from flask import (
    Flask,
    flash,
    g,
    redirect,
    render_template,
    request,
    send_from_directory,
    session,
    url_for,
)
from werkzeug.security import check_password_hash, generate_password_hash

BASE_DIR = os.path.abspath(os.path.dirname(__file__))
DB_PATH = os.path.join(BASE_DIR, "recruitment.db")

app = Flask(__name__)
app.config["SECRET_KEY"] = os.getenv("SECRET_KEY", "dev-secret-key-ganti-di-production")


def get_db():
    if "db" not in g:
        g.db = sqlite3.connect(DB_PATH)
        g.db.row_factory = sqlite3.Row
    return g.db


@app.teardown_appcontext
def close_db(_error):
    db = g.pop("db", None)
    if db is not None:
        db.close()


def init_db():
    db = get_db()
    db.executescript(
        """
        CREATE TABLE IF NOT EXISTS admins (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            username TEXT UNIQUE NOT NULL,
            password_hash TEXT NOT NULL
        );

        CREATE TABLE IF NOT EXISTS subjects (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE NOT NULL,
            description TEXT,
            is_active INTEGER NOT NULL DEFAULT 1
        );

        CREATE TABLE IF NOT EXISTS questions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            subject_id INTEGER NOT NULL,
            question_text TEXT NOT NULL,
            option_a TEXT NOT NULL,
            option_b TEXT NOT NULL,
            option_c TEXT NOT NULL,
            option_d TEXT NOT NULL,
            correct_option TEXT NOT NULL CHECK(correct_option IN ('A','B','C','D')),
            FOREIGN KEY(subject_id) REFERENCES subjects(id) ON DELETE CASCADE
        );

        CREATE TABLE IF NOT EXISTS applicants (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            full_name TEXT NOT NULL,
            email TEXT NOT NULL,
            phone TEXT,
            applied_subject_id INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY(applied_subject_id) REFERENCES subjects(id)
        );

        CREATE TABLE IF NOT EXISTS attempts (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            applicant_id INTEGER NOT NULL,
            subject_id INTEGER NOT NULL,
            score REAL NOT NULL,
            total_questions INTEGER NOT NULL,
            created_at TEXT NOT NULL,
            FOREIGN KEY(applicant_id) REFERENCES applicants(id),
            FOREIGN KEY(subject_id) REFERENCES subjects(id)
        );
        """
    )

    admin = db.execute("SELECT id FROM admins WHERE username = ?", ("admin",)).fetchone()
    if admin is None:
        db.execute(
            "INSERT INTO admins (username, password_hash) VALUES (?, ?)",
            ("admin", generate_password_hash("admin123", method="pbkdf2:sha256")),
        )
    db.commit()


def admin_required(view_func):
    @wraps(view_func)
    def wrapped(*args, **kwargs):
        if session.get("admin_id") is None:
            flash("Silakan login sebagai admin.", "warning")
            return redirect(url_for("admin_login"))
        return view_func(*args, **kwargs)

    return wrapped


@app.route("/")
def index():
    db = get_db()
    active_subjects = db.execute(
        "SELECT id, name, description FROM subjects WHERE is_active = 1 ORDER BY name"
    ).fetchall()
    return render_template("index.html", active_subjects=active_subjects)


@app.route("/panel-admin/login", methods=["GET", "POST"])
def admin_login():
    if request.method == "POST":
        username = request.form.get("username", "").strip()
        password = request.form.get("password", "")

        db = get_db()
        admin = db.execute("SELECT * FROM admins WHERE username = ?", (username,)).fetchone()

        if admin and check_password_hash(admin["password_hash"], password):
            session["admin_id"] = admin["id"]
            session["admin_username"] = admin["username"]
            flash("Login berhasil.", "success")
            return redirect(url_for("admin_dashboard"))

        flash("Username atau password salah.", "danger")

    return render_template("admin_login.html")


@app.route("/panel-admin/logout")
@admin_required
def admin_logout():
    session.clear()
    flash("Logout berhasil.", "info")
    return redirect(url_for("index"))


@app.route("/panel-admin")
@admin_required
def admin_dashboard():
    db = get_db()
    selected_subject_id = request.args.get("subject_id", type=int)

    subjects = db.execute(
        """
        SELECT s.*, COUNT(q.id) AS total_questions
        FROM subjects s
        LEFT JOIN questions q ON q.subject_id = s.id
        GROUP BY s.id
        ORDER BY s.name
        """
    ).fetchall()

    attempts = db.execute(
        """
        SELECT a.id, ap.full_name, ap.email, s.name AS subject_name, a.score,
               a.total_questions, a.created_at
        FROM attempts a
        JOIN applicants ap ON ap.id = a.applicant_id
        JOIN subjects s ON s.id = a.subject_id
        ORDER BY a.id DESC
        LIMIT 50
        """
    ).fetchall()

    if selected_subject_id:
        questions = db.execute(
            """
            SELECT q.id, q.question_text, q.correct_option, s.name AS subject_name
            FROM questions q
            JOIN subjects s ON s.id = q.subject_id
            WHERE q.subject_id = ?
            ORDER BY q.id DESC
            LIMIT 100
            """,
            (selected_subject_id,),
        ).fetchall()
    else:
        questions = db.execute(
            """
            SELECT q.id, q.question_text, q.correct_option, s.name AS subject_name
            FROM questions q
            JOIN subjects s ON s.id = q.subject_id
            ORDER BY q.id DESC
            LIMIT 100
            """
        ).fetchall()

    return render_template(
        "admin_dashboard.html",
        subjects=subjects,
        attempts=attempts,
        questions=questions,
        selected_subject_id=selected_subject_id,
    )


@app.route("/panel-admin/subjects/new", methods=["GET", "POST"])
@admin_required
def admin_new_subject():
    if request.method == "POST":
        name = request.form.get("name", "").strip()
        description = request.form.get("description", "").strip()

        if not name:
            flash("Nama mata pelajaran wajib diisi.", "warning")
            return render_template("admin_subject_form.html")

        db = get_db()
        try:
            db.execute(
                "INSERT INTO subjects (name, description, is_active) VALUES (?, ?, 1)",
                (name, description),
            )
            db.commit()
            flash("Mata pelajaran berhasil ditambahkan.", "success")
            return redirect(url_for("admin_dashboard"))
        except sqlite3.IntegrityError:
            flash("Nama mata pelajaran sudah ada.", "danger")

    return render_template("admin_subject_form.html")


@app.route("/panel-admin/subjects/<int:subject_id>/toggle")
@admin_required
def admin_toggle_subject(subject_id):
    db = get_db()
    subject = db.execute("SELECT * FROM subjects WHERE id = ?", (subject_id,)).fetchone()
    if subject is None:
        flash("Mata pelajaran tidak ditemukan.", "danger")
        return redirect(url_for("admin_dashboard"))

    new_status = 0 if subject["is_active"] == 1 else 1
    db.execute("UPDATE subjects SET is_active = ? WHERE id = ?", (new_status, subject_id))
    db.commit()

    flash("Status mata pelajaran diperbarui.", "info")
    return redirect(url_for("admin_dashboard"))


@app.route("/panel-admin/questions/new", methods=["GET", "POST"])
@admin_required
def admin_new_question():
    db = get_db()
    subjects = db.execute("SELECT id, name FROM subjects ORDER BY name").fetchall()

    if request.method == "POST":
        subject_id = request.form.get("subject_id", type=int)
        question_texts = [q.strip() for q in request.form.getlist("question_text[]")]
        option_as = [o.strip() for o in request.form.getlist("option_a[]")]
        option_bs = [o.strip() for o in request.form.getlist("option_b[]")]
        option_cs = [o.strip() for o in request.form.getlist("option_c[]")]
        option_ds = [o.strip() for o in request.form.getlist("option_d[]")]
        correct_options = [o.strip().upper() for o in request.form.getlist("correct_option[]")]

        if not question_texts:
            question_texts = [request.form.get("question_text", "").strip()]
            option_as = [request.form.get("option_a", "").strip()]
            option_bs = [request.form.get("option_b", "").strip()]
            option_cs = [request.form.get("option_c", "").strip()]
            option_ds = [request.form.get("option_d", "").strip()]
            correct_options = [request.form.get("correct_option", "").strip().upper()]

        if not subject_id:
            flash("Mata pelajaran wajib dipilih.", "warning")
            return render_template("admin_question_form.html", subjects=subjects)

        total_rows = max(
            len(question_texts),
            len(option_as),
            len(option_bs),
            len(option_cs),
            len(option_ds),
            len(correct_options),
        )

        insert_rows = []
        row_errors = []
        for i in range(total_rows):
            question_text = question_texts[i].strip() if i < len(question_texts) else ""
            option_a = option_as[i].strip() if i < len(option_as) else ""
            option_b = option_bs[i].strip() if i < len(option_bs) else ""
            option_c = option_cs[i].strip() if i < len(option_cs) else ""
            option_d = option_ds[i].strip() if i < len(option_ds) else ""
            correct_option = (
                correct_options[i].strip().upper() if i < len(correct_options) else ""
            )

            if not any([question_text, option_a, option_b, option_c, option_d, correct_option]):
                continue

            if (
                not question_text
                or not option_a
                or not option_b
                or not option_c
                or not option_d
                or correct_option not in {"A", "B", "C", "D"}
            ):
                row_errors.append(f"Form soal ke-{i + 1} belum lengkap/valid.")
                continue

            insert_rows.append(
                (
                    subject_id,
                    question_text,
                    option_a,
                    option_b,
                    option_c,
                    option_d,
                    correct_option,
                )
            )

        if not insert_rows:
            flash("Tidak ada soal valid untuk disimpan.", "warning")
            if row_errors:
                flash("; ".join(row_errors[:3]), "warning")
            return render_template("admin_question_form.html", subjects=subjects)

        db.executemany(
            """
            INSERT INTO questions (
                subject_id, question_text, option_a, option_b, option_c, option_d, correct_option
            )
            VALUES (?, ?, ?, ?, ?, ?, ?)
            """,
            insert_rows,
        )
        db.commit()

        flash(f"Berhasil menambahkan {len(insert_rows)} soal.", "success")
        if row_errors:
            flash("Sebagian soal dilewati: " + "; ".join(row_errors[:3]), "warning")
        return redirect(url_for("admin_dashboard"))

    return render_template("admin_question_form.html", subjects=subjects)


@app.route("/panel-admin/questions/<int:question_id>/edit", methods=["GET", "POST"])
@admin_required
def admin_edit_question(question_id):
    db = get_db()
    subjects = db.execute("SELECT id, name FROM subjects ORDER BY name").fetchall()
    question = db.execute(
        """
        SELECT id, subject_id, question_text, option_a, option_b, option_c, option_d, correct_option
        FROM questions
        WHERE id = ?
        """,
        (question_id,),
    ).fetchone()

    if question is None:
        flash("Soal tidak ditemukan.", "danger")
        return redirect(url_for("admin_dashboard"))

    if request.method == "POST":
        subject_id = request.form.get("subject_id", type=int)
        question_text = request.form.get("question_text", "").strip()
        option_a = request.form.get("option_a", "").strip()
        option_b = request.form.get("option_b", "").strip()
        option_c = request.form.get("option_c", "").strip()
        option_d = request.form.get("option_d", "").strip()
        correct_option = request.form.get("correct_option", "").strip().upper()

        if (
            not subject_id
            or not question_text
            or not option_a
            or not option_b
            or not option_c
            or not option_d
            or correct_option not in {"A", "B", "C", "D"}
        ):
            flash("Semua field wajib diisi dengan benar.", "warning")
            return render_template(
                "admin_question_edit_form.html",
                subjects=subjects,
                question=question,
            )

        db.execute(
            """
            UPDATE questions
            SET subject_id = ?, question_text = ?, option_a = ?, option_b = ?,
                option_c = ?, option_d = ?, correct_option = ?
            WHERE id = ?
            """,
            (
                subject_id,
                question_text,
                option_a,
                option_b,
                option_c,
                option_d,
                correct_option,
                question_id,
            ),
        )
        db.commit()
        flash("Soal berhasil diperbarui.", "success")
        return redirect(url_for("admin_dashboard"))

    return render_template("admin_question_edit_form.html", subjects=subjects, question=question)


@app.route("/panel-admin/questions/template")
@admin_required
def admin_question_template():
    return send_from_directory(
        os.path.join(BASE_DIR, "static", "templates"),
        "question_bulk_template.csv",
        as_attachment=True,
    )


@app.route("/panel-admin/questions/bulk", methods=["GET", "POST"])
@admin_required
def admin_bulk_questions():
    db = get_db()
    subjects = db.execute("SELECT id, name FROM subjects ORDER BY name").fetchall()
    subject_name_to_id = {s["name"].strip().lower(): s["id"] for s in subjects}

    if request.method == "POST":
        default_subject_id = request.form.get("subject_id", type=int)
        uploaded_file = request.files.get("questions_file")

        if not uploaded_file or not uploaded_file.filename:
            flash("Pilih file CSV terlebih dahulu.", "warning")
            return render_template("admin_bulk_question_form.html", subjects=subjects)

        try:
            content = uploaded_file.read().decode("utf-8-sig")
        except UnicodeDecodeError:
            flash("File harus berformat CSV UTF-8.", "danger")
            return render_template("admin_bulk_question_form.html", subjects=subjects)

        reader = csv.DictReader(io.StringIO(content))
        required_headers = {
            "question_text",
            "option_a",
            "option_b",
            "option_c",
            "option_d",
            "correct_option",
        }

        if not reader.fieldnames:
            flash("Header CSV tidak ditemukan.", "danger")
            return render_template("admin_bulk_question_form.html", subjects=subjects)

        normalized_headers = {h.strip() for h in reader.fieldnames if h}
        missing_headers = required_headers - normalized_headers
        if missing_headers:
            flash(
                "Header wajib belum lengkap: " + ", ".join(sorted(missing_headers)),
                "danger",
            )
            return render_template("admin_bulk_question_form.html", subjects=subjects)

        insert_rows = []
        row_errors = []

        for row_number, row in enumerate(reader, start=2):
            question_text = (row.get("question_text") or "").strip()
            option_a = (row.get("option_a") or "").strip()
            option_b = (row.get("option_b") or "").strip()
            option_c = (row.get("option_c") or "").strip()
            option_d = (row.get("option_d") or "").strip()
            correct_option = (row.get("correct_option") or "").strip().upper()
            subject_id = default_subject_id

            if not subject_id:
                subject_name = (row.get("subject_name") or "").strip().lower()
                subject_id = subject_name_to_id.get(subject_name)

            if not subject_id:
                row_errors.append(
                    f"Baris {row_number}: subject_id/form atau subject_name CSV tidak valid."
                )
                continue

            if not all([question_text, option_a, option_b, option_c, option_d]):
                row_errors.append(f"Baris {row_number}: data soal/pilihan belum lengkap.")
                continue

            if correct_option not in {"A", "B", "C", "D"}:
                row_errors.append(f"Baris {row_number}: correct_option wajib A/B/C/D.")
                continue

            insert_rows.append(
                (
                    subject_id,
                    question_text,
                    option_a,
                    option_b,
                    option_c,
                    option_d,
                    correct_option,
                )
            )

        if insert_rows:
            db.executemany(
                """
                INSERT INTO questions (
                    subject_id, question_text, option_a, option_b, option_c, option_d, correct_option
                )
                VALUES (?, ?, ?, ?, ?, ?, ?)
                """,
                insert_rows,
            )
            db.commit()
            flash(f"Berhasil upload {len(insert_rows)} soal.", "success")
        else:
            flash("Tidak ada soal valid yang bisa diupload.", "warning")

        if row_errors:
            preview_errors = row_errors[:5]
            flash("; ".join(preview_errors), "warning")
            if len(row_errors) > 5:
                flash(
                    f"Masih ada {len(row_errors) - 5} error lainnya. Periksa file CSV.",
                    "warning",
                )

        return redirect(url_for("admin_dashboard"))

    return render_template("admin_bulk_question_form.html", subjects=subjects)


@app.route("/apply", methods=["GET", "POST"])
def candidate_register():
    db = get_db()
    subjects = db.execute(
        "SELECT id, name FROM subjects WHERE is_active = 1 ORDER BY name"
    ).fetchall()

    if request.method == "POST":
        full_name = request.form.get("full_name", "").strip()
        email = request.form.get("email", "").strip()
        phone = request.form.get("phone", "").strip()
        applied_subject_id = request.form.get("applied_subject_id", type=int)

        if not full_name or not email or not applied_subject_id:
            flash("Nama lengkap, email, dan mapel tujuan wajib diisi.", "warning")
            return render_template("candidate_register.html", subjects=subjects)

        created_at = datetime.utcnow().isoformat(timespec="seconds")
        cursor = db.execute(
            """
            INSERT INTO applicants (full_name, email, phone, applied_subject_id, created_at)
            VALUES (?, ?, ?, ?, ?)
            """,
            (full_name, email, phone, applied_subject_id, created_at),
        )
        applicant_id = cursor.lastrowid
        db.commit()

        return redirect(url_for("candidate_test", applicant_id=applicant_id))

    return render_template("candidate_register.html", subjects=subjects)


@app.route("/test/<int:applicant_id>", methods=["GET", "POST"])
def candidate_test(applicant_id):
    db = get_db()
    applicant = db.execute(
        """
        SELECT a.*, s.name AS subject_name
        FROM applicants a
        JOIN subjects s ON s.id = a.applied_subject_id
        WHERE a.id = ?
        """,
        (applicant_id,),
    ).fetchone()

    if applicant is None:
        flash("Data pelamar tidak ditemukan.", "danger")
        return redirect(url_for("candidate_register"))

    existing_attempt = db.execute(
        "SELECT id FROM attempts WHERE applicant_id = ?", (applicant_id,)
    ).fetchone()
    if existing_attempt:
        return redirect(url_for("candidate_result", attempt_id=existing_attempt["id"]))

    questions = db.execute(
        """
        SELECT id, question_text, option_a, option_b, option_c, option_d
        FROM questions
        WHERE subject_id = ?
        ORDER BY id
        """,
        (applicant["applied_subject_id"],),
    ).fetchall()

    if not questions:
        flash(
            "Soal untuk mapel ini belum tersedia. Silakan hubungi admin recruitment.",
            "warning",
        )
        return redirect(url_for("index"))

    if request.method == "POST":
        correct_count = 0
        for q in questions:
            user_answer = request.form.get(f"q_{q['id']}", "").strip().upper()
            correct = db.execute(
                "SELECT correct_option FROM questions WHERE id = ?", (q["id"],)
            ).fetchone()
            if correct and user_answer == correct["correct_option"]:
                correct_count += 1

        total_questions = len(questions)
        score = round((correct_count / total_questions) * 100, 2)
        created_at = datetime.utcnow().isoformat(timespec="seconds")

        cursor = db.execute(
            """
            INSERT INTO attempts (applicant_id, subject_id, score, total_questions, created_at)
            VALUES (?, ?, ?, ?, ?)
            """,
            (applicant_id, applicant["applied_subject_id"], score, total_questions, created_at),
        )
        attempt_id = cursor.lastrowid
        db.commit()

        return redirect(url_for("candidate_result", attempt_id=attempt_id))

    return render_template("candidate_test.html", applicant=applicant, questions=questions)


@app.route("/result/<int:attempt_id>")
def candidate_result(attempt_id):
    db = get_db()
    attempt = db.execute(
        """
        SELECT a.*, ap.full_name, ap.email, s.name AS subject_name
        FROM attempts a
        JOIN applicants ap ON ap.id = a.applicant_id
        JOIN subjects s ON s.id = a.subject_id
        WHERE a.id = ?
        """,
        (attempt_id,),
    ).fetchone()

    if attempt is None:
        flash("Hasil tidak ditemukan.", "danger")
        return redirect(url_for("index"))

    return render_template("candidate_result.html", attempt=attempt)


with app.app_context():
    init_db()


if __name__ == "__main__":
    app.run(debug=True)
