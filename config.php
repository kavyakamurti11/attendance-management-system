<?php
// ====== DATABASE (XAMPP che default settings) ======
const DB_HOST = '127.0.0.1';
const DB_NAME = 'attendance_management';
const DB_USER = 'root';
const DB_PASS = '';            // XAMPP has no password by default

// ====== ADMIN (no Create Account - the admin is set here) ======
// The first time setup.php or admin login runs, this password is hashed with bcrypt
// and stored in the `admins` table. After that the hash in the database is used.
// (If you reset a forgotten password by email, the new hash is saved in the database.)
// After setup you may remove the ADMIN_PASSWORD line from this file.
const ADMIN_NAME     = 'Administrator';
const ADMIN_EMAIL    = 'admin@gmail.com';     // <-- your admin email (the verification code is sent here)
const ADMIN_PASSWORD = 'Admin@12345';         // <-- your admin password

// true = show the real error text in 'Server error' alerts (turn OFF on a live server)
const APP_DEBUG = true;

const SESSION_MINUTES = 30;    // inactivity timeout

// ====== EMAIL (forgot password verification code) ======
// 'log'  = no email is sent; the code is written to includes/mail_outbox.log (for localhost testing)
// 'smtp' = really sends the email (needs a Gmail App Password)
const MAIL_MODE       = 'log';
const MAIL_DEV_SHOW_CODE = true;   // true: in 'log' mode the code is also shown on screen. Set to false on a live server!
const SMTP_HOST   = 'smtp.gmail.com';
const SMTP_PORT   = 587;           // 587 = STARTTLS, 465 = SSL
const SMTP_USER   = '';            // your Gmail, e.g. yourname@gmail.com
const SMTP_PASS   = '';            // Gmail "App password" (16 letters) - not your normal password
const MAIL_FROM   = '';            // if blank, SMTP_USER is used
const MAIL_FROM_NAME = 'Attendance System';
const RESET_CODE_MINUTES = 10;
