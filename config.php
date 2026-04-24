<?php

declare(strict_types=1);

// Konfigurasi dasar untuk versi PHP (cPanel-friendly)
// Jika ingin MySQL, ganti DB_DRIVER ke mysql dan isi kredensialnya.

const APP_NAME = 'Sistem Penerimaan Guru Baru';
const APP_URL = ''; // Opsional: isi URL domain jika perlu

const DB_DRIVER = 'sqlite'; // sqlite | mysql
const DB_SQLITE_PATH = __DIR__ . '/recruitment.db';

const DB_HOST = 'localhost';
const DB_PORT = '3306';
const DB_NAME = 'recruitment_guru';
const DB_USER = 'root';
const DB_PASS = '';

const ADMIN_DEFAULT_USERNAME = 'admin';
const ADMIN_DEFAULT_PASSWORD = 'admin123';

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

date_default_timezone_set('Asia/Jakarta');
