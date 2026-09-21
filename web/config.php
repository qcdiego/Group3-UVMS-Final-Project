<?php
/* UVMS — production database connection for univms.xyz */
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

const DB_HOST = 'localhost';
const DB_NAME = 'vjeqmtxfvc_conduct_system';
const DB_USER = 'vjeqmtxfvc_uvms_user';
const DB_PASS = 'PASTE_DATABASE_PASSWORD_HERE';

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ]
    );
} catch (PDOException $e) {
    die('<div style="font-family:sans-serif;max-width:640px;margin:60px auto;padding:24px;border:1px solid #e0b4b4;background:#fbe9e6;color:#a63d2f;"><h2 style="margin-top:0;">Database connection failed</h2><p>Please check the hosting database configuration.</p></div>');
}
