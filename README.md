# Attendance Management — PHP + MySQL (v7)

## What this version does
- **Separate account tables:** `admins`, `faculty`, `students`. Passwords are stored as **plain text** (no hashing) in the `password` column, so the original password is visible in phpMyAdmin. New table **`student_details`**: name, roll_number, class_name, division, email, password.
- **Admin has no "Create Account".** The admin email and password are set in `includes/config.php`.
- **Admin forgot password:** email -> 6-digit verification code -> new password -> login.
- **Attendance is stored in proper tables** (`classes`, `class_students`, `attendance`, view `attendance_summary`), including archived days (`attendance.archived = 1`).
- **Faculty dashboard:** new **Student Attendance** page (class -> student -> total / present / absent / %, month-wise and date-wise, optional date range) and new **Archive** page (Archive Attendance + Archived Records, only for the faculty member's own classes).
- All user-facing text is in English.

## Setup (XAMPP)
1. Start **Apache** and **MySQL**. Copy this folder to `C:\xampp\htdocs\attendance\`.
2. Edit `includes/config.php`: set `ADMIN_EMAIL` and `ADMIN_PASSWORD`.
3. phpMyAdmin -> **Import** -> `database/schema.sql`.
4. Open `http://localhost/attendance/setup.php` once (renames the old `password_hash` columns to `password`, creates `student_details`, creates the admin, adds the `archived` column if your database is older, moves old accounts, fills the tables from existing data). Then delete `setup.php`.
5. Open `http://localhost/attendance/admin_login.html`.

## Forgot password (admin, faculty, student)
`admin_login.html`, `faculty_login.html`, `student_login.html` -> "Forgot password?" -> email (students: roll number + the email saved at sign-up) -> code -> new password. Old students without an email in `student_details` cannot use it until an email is added to their row.
- Default `MAIL_MODE = 'log'`: no email is sent; the code is shown on the page (TEST MODE) and written to `includes/mail_outbox.log`.
- Real email: set `MAIL_MODE = 'smtp'`, `SMTP_USER` (Gmail) and `SMTP_PASS` (Gmail **App Password**), and set `MAIL_DEV_SHOW_CODE = false`.
- The code is valid for 10 minutes, is stored as plain text, and is blocked after 5 wrong attempts.

## Where the data is in phpMyAdmin
| Table | Content |
|---|---|
| `admins`, `faculty`, `students` | accounts (plain-text `password` column) |
| `student_details` | name, roll number, class, division, email, original password of every student |
| `classes`, `class_students` | classes and their students |
| `attendance` | one row per student per day: date, roll, name, class, subject, Present/Absent, archived |
| `attendance_summary` (view) | per student: total, present, absent, % |
| `app_data` | timetables, notifications, archive (JSON used by the dashboards) |

## Notes
- Timetables, notifications and the archive JSON are still kept in `app_data` (the archived days are also copied into `attendance`).
- A faculty member only sees and archives their own classes; the admin sees everything.

## Old accounts (bcrypt hashes)
A hash cannot be turned back into the original password. After the update, an old account still logs in with its password once, and the plain password is then saved automatically (and shown in `student_details`). Admin password = `ADMIN_PASSWORD` in `config.php`.
