-- NOTE (Nov 2026): this file uses "ADD COLUMN IF NOT EXISTS", which only MariaDB understands (MySQL stops with an error).
-- You do not need to import it any more: Admin > System > "Run database update" (or php database/migrate.php) applies
-- everything in it, and the newer changes, safely on both MySQL and MariaDB.
-- ScholarshipHive Processing Portal — Upgrade 3: CRM Automation, Tasks & Multi-Country Support
SET NAMES utf8mb4;

-- 1. Upgrade student_meetings with Conditional Meeting logic
ALTER TABLE student_meetings
  ADD COLUMN IF NOT EXISTS meeting_type VARCHAR(30) NOT NULL DEFAULT 'Face-to-Face',
  ADD COLUMN IF NOT EXISTS location VARCHAR(150) NULL,
  ADD COLUMN IF NOT EXISTS staff_member VARCHAR(150) NULL,
  ADD COLUMN IF NOT EXISTS recording_available VARCHAR(10) NULL,
  ADD COLUMN IF NOT EXISTS recording_upload_status VARCHAR(30) NULL,
  ADD COLUMN IF NOT EXISTS exception_reason TEXT NULL,
  ADD COLUMN IF NOT EXISTS exception_authorized_by INT UNSIGNED NULL;

-- 2. Upgrade student_files with duplicate detection hash and versioning
ALTER TABLE student_files
  ADD COLUMN IF NOT EXISTS file_hash VARCHAR(64) NULL,
  ADD COLUMN IF NOT EXISTS version INT UNSIGNED NOT NULL DEFAULT 1;

-- 3. Upgrade students with financial calculations and multi-country specific columns
ALTER TABLE students
  ADD COLUMN IF NOT EXISTS total_package_fee VARCHAR(40) NULL,
  ADD COLUMN IF NOT EXISTS total_paid VARCHAR(40) NULL,
  ADD COLUMN IF NOT EXISTS balance_due VARCHAR(40) NULL,
  ADD COLUMN IF NOT EXISTS payment_status VARCHAR(40) NULL,
  ADD COLUMN IF NOT EXISTS draft_step INT UNSIGNED NOT NULL DEFAULT 1,
  ADD COLUMN IF NOT EXISTS is_draft TINYINT(1) NOT NULL DEFAULT 0,
  ADD COLUMN IF NOT EXISTS cas_number VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS tb_test_status VARCHAR(30) NULL,
  ADD COLUMN IF NOT EXISTS aps_number VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS coe_number VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS pal_status VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS gic_status VARCHAR(100) NULL,
  ADD COLUMN IF NOT EXISTS nostrification_status VARCHAR(100) NULL;

-- 4. Centralized student_tasks table
CREATE TABLE IF NOT EXISTS student_tasks (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id INT UNSIGNED NOT NULL,
  task_type VARCHAR(50) NOT NULL,
  title VARCHAR(255) NOT NULL,
  description TEXT NULL,
  assigned_to INT UNSIGNED NULL,
  assigned_team VARCHAR(20) NULL,
  due_date DATE NULL,
  priority ENUM('high', 'medium', 'low') NOT NULL DEFAULT 'medium',
  status ENUM('pending', 'in_progress', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
  auto_generated TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NULL,
  completed_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_task_student (student_id),
  KEY idx_task_status (status),
  KEY idx_task_assigned (assigned_to),
  CONSTRAINT fk_task_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
