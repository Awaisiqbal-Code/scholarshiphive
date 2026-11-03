SCHOLARSHIPHIVE PROCESSING PORTAL — INSTALL / UPDATE (cPanel or any PHP hosting)
==============================================================================

Requirements: PHP 8.1 or newer (extensions: pdo_mysql, openssl, fileinfo, curl, zip, zlib), MySQL 5.7+/8 or MariaDB 10.3+,
HTTPS. In cPanel: "Select PHP Version" > tick curl and zip if they are not ticked.


A) UPDATE THE LIVE PORTAL (keeps every record already in it)
   1. cPanel > Backup: download a database backup and a copy of the current "portal" folder.
   2. Upload the new "portal" folder over the old one, but KEEP your live portal/app/config.php
      (it holds the database password and the app_key that unlocks stored student passwords).
   3. Sign in as an admin. The portal sends you to Admin > System: press "Run database update".
      Until that is done, other users see "The portal is being updated".
      (Command line alternative: php database/migrate.php)
      The update only ADDS columns/tables and WIDENS short columns. It never deletes data.
      You can run it again at any time; it does nothing the second time.
   You do NOT need to import upgrade.sql, upgrade-2 or upgrade-3 again.

B) FRESH INSTALL (empty database)
   1. cPanel > MySQL Databases: create a database and a user with ALL PRIVILEGES.
   2. phpMyAdmin > Import database/schema.sql, then database/seed.sql.  (schema.sql DELETES existing tables.)
   3. Upload the "portal" folder. Open portal/app/config.php and fill in db_name, db_user, db_pass.
      Do not change app_key: the stored student account passwords are encrypted with it.
   4. Sign in as admin and run Admin > System > "Run database update".

C) DOCUMENTS AND DOWNLOADS
   - Documents status and Pending documents are worked out by the portal from the documents checklist (files saved
     under each document, or documents marked "Received outside the portal"). Nobody types them any more.
     The database update (step A3) turns what the sheets said into the checklist once, so no student changes
     status; the old values are written to each student's activity.
   - A file added in the Documents section shows at once and can be removed until Save is pressed. Saved files
     (documents and meeting recordings) cannot be deleted from the portal.
   - Downloads contain the real files, never links. Google Drive files are copied into the portal the first time
     they are opened or downloaded (folder portal/app/storage/files/gdrive_cache). Copying all of them takes about
     4 GB of hosting space today. A Drive file that is not shared as "Anyone with the link" cannot be copied; such
     files are listed in "Files not downloaded.txt" inside the ZIP.
   - Admin > Download all: one ZIP with a folder per country and per student (Student details.pdf + all documents)
     and each country's sheet as Excel. The ZIP is written straight to the browser, so it needs no extra space.
   - Document OCR (iLovePDF) is switched off. Its code is kept in extras/ocr-ilovepdf (not uploaded) for later.

D) TURN ON HTTPS
   cPanel > SSL/TLS Status > Run AutoSSL. If the site shows a redirect loop, remove the two
   "Force HTTPS" lines in portal/.htaccess.

E) WHERE THINGS ARE
   .../portal/admin/     Admin and sub-admin portal (Overview, Students, Tax meetings, Data check, Download all,
                         Staff, Student logins, Activity, System). Sheet view and Excel export are on the Students page.
   .../portal/officer/   Processing officers (their own students; Sheet view and Export too)
   .../portal/tax/       Tax consultant
   .../portal/student/   Students (their own steps, universities, documents, meetings)

F) ROLES
   Admin        everything; manages admins/sub-admins; deletes students; can export stored passwords.
   Sub-admin    admin portal limited to the countries ticked for them (all if none): add/edit students,
                assign officers, student logins, data check, activity, export (never passwords), add/reset
                processing officers. Cannot delete students, manage admins, or run the database update.
   Officer      Italy: front-end and back-end teams each edit their own sheet columns.
                France / Czech Republic / Austria / Germany: the assigned processor edits the whole record.
   Tax          sees students (profile, meetings, documents, visa); edits only the tax meeting columns.

G) FILES IN THIS ZIP
   portal/      the website (upload this)
   database/    schema.sql + seed.sql (fresh install), migrate.php (update), older upgrade files (history)
   *.xlsx/*.csv the country sheets the forms and exports are built from (do NOT upload them: they contain passwords)
   scratch/     developer test scripts from earlier work (do NOT upload; contains session cookie files)
   extras/      Document OCR with iLovePDF, switched off for now (do NOT upload; see extras/ocr-ilovepdf/README.txt)

BACKUPS
   cPanel > Backup > download a database backup every week.
