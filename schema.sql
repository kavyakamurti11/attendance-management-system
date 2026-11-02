-- Attendance Management System - MySQL schema (v7: PLAIN passwords, no hashing + student_details table)
-- Import this in phpMyAdmin (the database is created automatically).
CREATE DATABASE IF NOT EXISTS attendance_management
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE attendance_management;

-- ===================== ACCOUNTS (3 separate tables) =====================
CREATE TABLE IF NOT EXISTS admins (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(150) NOT NULL,
  email         VARCHAR(190) NOT NULL,
  password      VARCHAR(255) NOT NULL,          -- plain text (original password)
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_admin_email (email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS faculty (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(150) NOT NULL,
  email         VARCHAR(190) NOT NULL,
  password      VARCHAR(255) NOT NULL,          -- plain text
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_faculty_email (email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS students (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(150) NOT NULL,
  roll          VARCHAR(80)  NOT NULL,
  division      VARCHAR(150) NOT NULL,          -- class name
  password      VARCHAR(255) NOT NULL,          -- plain text
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_student (roll, division)
) ENGINE=InnoDB;

-- Student details (open this table in phpMyAdmin to see name, roll, class, division, email and the original password)
CREATE TABLE IF NOT EXISTS student_details (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(150) NOT NULL,
  roll_number VARCHAR(80)  NOT NULL,
  class_name  VARCHAR(150) NOT NULL,
  division    VARCHAR(150) NOT NULL DEFAULT '',
  email       VARCHAR(190) NOT NULL DEFAULT '',
  password    VARCHAR(255) NOT NULL DEFAULT '',   -- plain text (original password)
  created_at  TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sd (roll_number, class_name)
) ENGINE=InnoDB;

-- Admin forgot-password: verification code sent by email (stored as plain text)
CREATE TABLE IF NOT EXISTS password_resets (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  email      VARCHAR(190) NOT NULL,
  `code`     VARCHAR(255) NOT NULL,
  attempts   TINYINT UNSIGNED NOT NULL DEFAULT 0,
  expires_at DATETIME NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_email (email)
) ENGINE=InnoDB;

-- Failed login tracking (5 wrong attempts => 15 minute lock)
CREATE TABLE IF NOT EXISTS login_attempts (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  ident      VARCHAR(300) NOT NULL,
  ip         VARCHAR(64)  NULL,
  attempt_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY idx_ident (ident, attempt_at)
) ENGINE=InnoDB;

-- ===================== APP DATA (JSON for the dashboards - timetables, notifications ...) =====================
CREATE TABLE IF NOT EXISTS app_data (
  data_key   VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin NOT NULL PRIMARY KEY,
  data_value LONGTEXT NOT NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

-- ===================== PROPER TABLES (visible directly in phpMyAdmin) =====================
-- The server fills these tables automatically when a faculty member saves classes/attendance.
CREATE TABLE IF NOT EXISTS classes (
  class_id      VARCHAR(64)  NOT NULL PRIMARY KEY,
  faculty_email VARCHAR(190) NOT NULL DEFAULT '',
  faculty_name  VARCHAR(150) NULL,
  class_name    VARCHAR(150) NOT NULL,
  division      VARCHAR(150) NULL,
  subject       VARCHAR(150) NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  KEY idx_faculty (faculty_email)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS class_students (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  class_id     VARCHAR(64)  NOT NULL,
  roll         VARCHAR(80)  NOT NULL,
  student_name VARCHAR(150) NOT NULL,
  UNIQUE KEY uq_cs (class_id, roll),
  CONSTRAINT fk_cs_class FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS attendance (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  class_id      VARCHAR(64)  NOT NULL,
  faculty_email VARCHAR(190) NOT NULL DEFAULT '',
  class_name    VARCHAR(150) NOT NULL,
  subject       VARCHAR(150) NULL,
  att_date      DATE NULL,                      -- YYYY-MM-DD
  date_key      VARCHAR(40)  NOT NULL,          -- original date text used by the dashboards
  roll          VARCHAR(80)  NOT NULL,
  student_name  VARCHAR(150) NOT NULL,
  status        ENUM('Present','Absent') NOT NULL,
  archived      TINYINT(1) NOT NULL DEFAULT 0,   -- 1 = day was moved to Archived Records
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_att (class_id, date_key, roll),
  KEY idx_date (att_date),
  KEY idx_roll (roll, class_name),
  CONSTRAINT fk_att_class FOREIGN KEY (class_id) REFERENCES classes(class_id) ON DELETE CASCADE
) ENGINE=InnoDB;

-- Student-wise summary (open attendance_summary in phpMyAdmin)
CREATE OR REPLACE VIEW attendance_summary AS
SELECT class_name, subject, faculty_email, roll, student_name,
       COUNT(*)                                   AS total_lectures,
       SUM(status = 'Present')                    AS present_count,
       SUM(status = 'Absent')                     AS absent_count,
       ROUND(100 * SUM(status = 'Present') / COUNT(*), 1) AS percentage
FROM attendance
GROUP BY class_name, subject, faculty_email, roll, student_name;
