-- ScholarshipHive Processing Portal — MySQL / MariaDB schema (all countries)
-- FRESH INSTALL: import this first in phpMyAdmin, then import seed.sql.
-- A portal that is ALREADY LIVE: do not import this file. Import upgrade.sql instead (see README).

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS import_notes;
DROP TABLE IF EXISTS applications;
DROP TABLE IF EXISTS activity_log;
DROP TABLE IF EXISTS login_attempts;
DROP TABLE IF EXISTS student_files;
DROP TABLE IF EXISTS student_meetings;
DROP TABLE IF EXISTS student_documents;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS students;

CREATE TABLE students (
  id                INT UNSIGNED NOT NULL AUTO_INCREMENT,
  import_key        VARCHAR(60)  NULL,
  country           VARCHAR(20)  NOT NULL DEFAULT 'italy',
  sr_no             INT NULL,
  process_status    VARCHAR(20)  NULL,
  name              VARCHAR(150) NOT NULL,
  counselor         VARCHAR(100) NULL,
  officer_id        INT UNSIGNED NULL,
  entered_by        INT UNSIGNED NULL,
  refund            VARCHAR(40)  NULL,
  refund_reason     VARCHAR(255) NULL,
  dues              VARCHAR(40)  NULL,
  branch            VARCHAR(60)  NULL,
  intake            VARCHAR(40)  NULL,
  received_date     DATE NULL,
  study_level       VARCHAR(30)  NULL,
  applied_for       VARCHAR(255) NULL,
  last_degree       VARCHAR(255) NULL,
  gpa               VARCHAR(100) NULL,
  ielts             VARCHAR(40)  NULL,
  contact_no        VARCHAR(40)  NULL,
  client_type       VARCHAR(30)  NULL,
  -- front end
  consult1          VARCHAR(20)  NULL,
  consent           VARCHAR(20)  NULL,
  docs_checklist    VARCHAR(20)  NULL,
  initial_meeting   VARCHAR(30)  NULL,
  follow_up_1       DATE NULL,
  recorded_uploaded VARCHAR(30)  NULL,
  drive_link        TEXT NULL,
  recording_note    VARCHAR(255) NULL,
  second_meeting    VARCHAR(20)  NULL,
  follow_up_2       DATE NULL,
  offer             VARCHAR(20)  NULL,
  consult2          VARCHAR(20)  NULL,
  disc2             TEXT NULL,
  enrollment        VARCHAR(20)  NULL,
  consult3          VARCHAR(20)  NULL,
  disc3             TEXT NULL,
  last_installment  VARCHAR(60)  NULL,
  remarks           TEXT NULL,
  remarks_date      DATE NULL,
  -- tax consultant
  tax_meeting       VARCHAR(20)  NULL,
  meeting_priority  VARCHAR(20)  NULL,
  meeting_date      DATE NULL,
  follow_up_3       DATE NULL,
  tax_remarks       TEXT NULL,
  -- back end: admission
  back_officer_id   INT UNSIGNED NULL,
  documents_status  VARCHAR(255) NULL,
  pending_documents TEXT NULL,
  documents_link    TEXT NULL,
  doc_request_date  DATE NULL,
  student_email     VARCHAR(150) NULL,
  recovery_email    VARCHAR(150) NULL,
  email_password_enc TEXT NULL,
  portal_id         VARCHAR(150) NULL,
  portal_password_enc TEXT NULL,
  cv_status         VARCHAR(20)  NULL,
  sop_status        VARCHAR(20)  NULL,
  campus_france     VARCHAR(255) NULL,
  mock_interview    VARCHAR(20)  NULL,
  shortlisting      VARCHAR(30)  NULL,
  shortlisted_course VARCHAR(255) NULL,
  admission_university VARCHAR(255) NULL,
  application_fees  VARCHAR(100) NULL,
  application_status VARCHAR(255) NULL,
  admission_letters VARCHAR(100) NULL,
  embassy_status    VARCHAR(100) NULL,
  legalization_status VARCHAR(60) NULL,
  second_consultancy VARCHAR(100) NULL,
  application_notes TEXT NULL,
  record_remarks    TEXT NULL,
  record_remarks_date DATE NULL,
  enrollment_summary_status VARCHAR(150) NULL,
  enrollment_summary_date DATE NULL,
  inst1             VARCHAR(20)  NULL,
  inst2             VARCHAR(20)  NULL,
  inst3             VARCHAR(20)  NULL,
  inst4             VARCHAR(20)  NULL,
  refund_threat     TEXT NULL,
  back_remarks      TEXT NULL,
  back_remarks_date DATE NULL,
  -- back end: visa filing
  embassy           VARCHAR(100) NULL,
  visa_checklist_shared VARCHAR(3) NULL,
  visa_checklist_date DATE NULL,
  sponsor           VARCHAR(150) NULL,
  financial_verified VARCHAR(3)  NULL,
  financial_missing TEXT NULL,
  cimea_received    VARCHAR(3)   NULL,
  cimea_date        DATE NULL,
  visa_form_by      VARCHAR(10)  NULL,
  health_insurance  VARCHAR(3)   NULL,
  flight_hotel      VARCHAR(3)   NULL,
  appointment_status VARCHAR(10) NULL,
  appointment_date  DATE NULL,
  visa_submitted_date DATE NULL,
  email_checked_date DATE NULL,
  visa              VARCHAR(20)  NULL,
  visa_decision_date DATE NULL,
  visa_remarks      TEXT NULL,
  created_at        DATETIME NOT NULL,
  updated_at        DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_import (import_key),
  KEY idx_country (country),
  KEY idx_officer (officer_id),
  KEY idx_back_officer (back_officer_id),
  KEY idx_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id                   INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name                 VARCHAR(150) NOT NULL,
  username             VARCHAR(80)  NOT NULL,
  password_hash        VARCHAR(255) NOT NULL,
  role                 ENUM('admin','officer','tax','student') NOT NULL,
  team                 ENUM('frontend','backend') NULL,
  countries            VARCHAR(100) NULL,
  student_id           INT UNSIGNED NULL,
  active               TINYINT(1) NOT NULL DEFAULT 1,
  must_change_password TINYINT(1) NOT NULL DEFAULT 1,
  last_login           DATETIME NULL,
  created_at           DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_username (username),
  UNIQUE KEY uq_student (student_id),
  CONSTRAINT fk_users_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE students
  ADD CONSTRAINT fk_students_officer FOREIGN KEY (officer_id) REFERENCES users(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_students_back_officer FOREIGN KEY (back_officer_id) REFERENCES users(id) ON DELETE SET NULL,
  ADD CONSTRAINT fk_students_entered FOREIGN KEY (entered_by) REFERENCES users(id) ON DELETE SET NULL;

CREATE TABLE student_documents (
  student_id  INT UNSIGNED NOT NULL,
  doc_key     VARCHAR(40) NOT NULL DEFAULT '',
  status      VARCHAR(12) NOT NULL,
  updated_by  INT UNSIGNED NULL,
  updated_at  DATETIME NOT NULL,
  PRIMARY KEY (student_id, doc_key),
  CONSTRAINT fk_docs_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Meetings with a student (date, status, join link, recording link, notes)
CREATE TABLE student_meetings (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id    INT UNSIGNED NOT NULL,
  kind          VARCHAR(20)  NOT NULL DEFAULT 'other',
  title         VARCHAR(150) NOT NULL,
  meeting_date  DATE         NULL,
  meeting_time  VARCHAR(10)  NULL,
  status        VARCHAR(20)  NOT NULL DEFAULT 'Scheduled',
  join_link     VARCHAR(500) NULL,
  recording_link VARCHAR(500) NULL,
  notes         TEXT         NULL,
  student_can_see TINYINT(1) NOT NULL DEFAULT 1,
  created_by    INT UNSIGNED NULL,
  created_at    DATETIME     NOT NULL,
  updated_at    DATETIME     NULL,
  PRIMARY KEY (id),
  KEY idx_meet_student (student_id, meeting_date),
  CONSTRAINT fk_meet_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Uploaded files: documents (doc_key) or a meeting's recording / minutes (meeting_id).
-- Local uploads are stored in portal/app/storage/files/, outside the public folders;
-- Google Drive-linked files store 'gdrive:<file_id>' in stored_name.
CREATE TABLE student_files (
  id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id    INT UNSIGNED NOT NULL,
  doc_key       VARCHAR(40)  NOT NULL DEFAULT 'other',
  meeting_id    INT UNSIGNED NULL,
  original_name VARCHAR(255) NOT NULL,
  stored_name   VARCHAR(120) NOT NULL,
  mime          VARCHAR(100) NOT NULL,
  size          INT UNSIGNED NOT NULL,
  uploaded_by   INT UNSIGNED NULL,
  uploaded_role VARCHAR(12)  NULL,
  created_at    DATETIME     NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_file_student_stored (student_id, doc_key, stored_name),
  KEY idx_file_student (student_id, doc_key),
  KEY idx_file_meeting (meeting_id),
  CONSTRAINT fk_file_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
  CONSTRAINT fk_file_meeting FOREIGN KEY (meeting_id) REFERENCES student_meetings(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- University / programme applications (one row per university the student applies to)
CREATE TABLE applications (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT,
  import_key   VARCHAR(80)  NULL,
  student_id   INT UNSIGNED NOT NULL,
  list_name    VARCHAR(60)  NULL,
  sort_no      VARCHAR(10)  NULL,
  university   VARCHAR(255) NULL,
  program      TEXT NULL,
  app_type     VARCHAR(150) NULL,
  deadline     VARCHAR(150) NULL,
  requirements TEXT NULL,
  fee          VARCHAR(100) NULL,
  applied_date VARCHAR(60)  NULL,
  status       VARCHAR(255) NULL,
  result       VARCHAR(30)  NULL,
  link         TEXT NULL,
  submitted_by VARCHAR(60)  NULL,
  notes        TEXT NULL,
  updated_by   INT UNSIGNED NULL,
  created_at   DATETIME NOT NULL,
  updated_at   DATETIME NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_app_import (import_key),
  KEY idx_app_student (student_id),
  CONSTRAINT fk_app_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- What the import could not put in a field: sheet disagreements, unreadable dates, skipped rows
CREATE TABLE import_notes (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
  student_id  INT UNSIGNED NULL,
  country     VARCHAR(20)  NULL,
  sheet       VARCHAR(120) NOT NULL,
  ref         VARCHAR(40)  NULL,
  field       VARCHAR(60)  NULL,
  note        TEXT NOT NULL,
  resolved    TINYINT(1) NOT NULL DEFAULT 0,
  created_at  DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_note_student (student_id),
  CONSTRAINT fk_note_student FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_attempts (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ip         VARCHAR(45) NOT NULL,
  username   VARCHAR(80) NOT NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_ip (ip, created_at),
  KEY idx_user (username, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE activity_log (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id    INT UNSIGNED NULL,
  student_id INT UNSIGNED NULL,
  action     VARCHAR(255) NOT NULL,
  ip         VARCHAR(45) NULL,
  created_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  KEY idx_student (student_id),
  KEY idx_user (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;
