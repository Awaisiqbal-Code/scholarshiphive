"""
High-Performance Student Data Export & Persistent Scheduler
Supports SQLite and MySQL with Asia/Karachi timezone boundaries,
streaming CSV generation, APScheduler background daemon, and CLI commands.
"""

import os
import sys
import csv
import sqlite3
import argparse
from datetime import datetime, timedelta
import zoneinfo

# Timezone for all Pakistan business logic
PKT = zoneinfo.ZoneInfo("Asia/Karachi")

def get_base_dir() -> str:
    """Return the absolute path of the project root."""
    return os.path.abspath(os.path.join(os.path.dirname(__file__), ".."))

def get_storage_exports_dir() -> str:
    """Return the secure directory for storing generated exports."""
    base = get_base_dir()
    d = os.path.join(base, "portal", "app", "storage", "exports")
    try:
        os.makedirs(d, exist_ok=True)
        return d
    except OSError:
        tmp = os.path.join(os.environ.get("TEMP", "/tmp"), "scholarshiphive_exports")
        os.makedirs(tmp, exist_ok=True)
        return tmp

def get_db_connection():
    """Connect to SQLite or MySQL based on environment."""
    base = get_base_dir()
    sqlite_path = os.path.join(base, "portal", "app", "portal.sqlite")
    
    # Check if MySQL env vars are present
    db_host = os.environ.get("MYSQLHOST") or os.environ.get("DB_HOST")
    if db_host:
        try:
            import pymysql
            return pymysql.connect(
                host=db_host,
                port=int(os.environ.get("MYSQLPORT") or os.environ.get("DB_PORT", 3306)),
                user=os.environ.get("MYSQLUSER") or os.environ.get("DB_USER", "root"),
                password=os.environ.get("MYSQLPASSWORD") or os.environ.get("DB_PASS", ""),
                database=os.environ.get("MYSQLDATABASE") or os.environ.get("DB_NAME", "scholarshiphive"),
                cursorclass=pymysql.cursors.DictCursor
            )
        except Exception as e:
            print(f"[WARN] MySQL connection failed ({e}), falling back to SQLite", file=sys.stderr)

    conn = sqlite3.connect(sqlite_path)
    conn.row_factory = sqlite3.Row
    return conn

def init_tables(conn):
    """Ensure scheduler and history tables exist with default schedules."""
    is_sqlite = isinstance(conn, sqlite3.Connection)
    cur = conn.cursor()
    
    if is_sqlite:
        cur.execute("""
        CREATE TABLE IF NOT EXISTS export_schedules (
            id INTEGER PRIMARY KEY,
            name TEXT NOT NULL,
            schedule_time TEXT NOT NULL DEFAULT '09:00',
            export_type TEXT NOT NULL DEFAULT 'daily',
            enabled INTEGER NOT NULL DEFAULT 1,
            last_run_at TEXT NULL,
            last_status TEXT NULL,
            last_file TEXT NULL,
            updated_at TEXT NULL
        );
        """)
        cur.execute("""
        CREATE TABLE IF NOT EXISTS export_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            schedule_id INTEGER NULL,
            export_type TEXT NOT NULL,
            file_name TEXT NOT NULL,
            file_path TEXT NOT NULL,
            file_size INTEGER NOT NULL DEFAULT 0,
            row_count INTEGER NOT NULL DEFAULT 0,
            status TEXT NOT NULL DEFAULT 'completed',
            trigger_type TEXT NOT NULL DEFAULT 'scheduled',
            created_at TEXT NOT NULL
        );
        """)
    else:
        cur.execute("""
        CREATE TABLE IF NOT EXISTS export_schedules (
            id INT PRIMARY KEY,
            name VARCHAR(100) NOT NULL,
            schedule_time VARCHAR(5) NOT NULL DEFAULT '09:00',
            export_type VARCHAR(20) NOT NULL DEFAULT 'daily',
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            last_run_at DATETIME NULL,
            last_status VARCHAR(20) NULL,
            last_file VARCHAR(255) NULL,
            updated_at DATETIME NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        """)
        cur.execute("""
        CREATE TABLE IF NOT EXISTS export_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            schedule_id INT NULL,
            export_type VARCHAR(20) NOT NULL,
            file_name VARCHAR(255) NOT NULL,
            file_path VARCHAR(255) NOT NULL,
            file_size INT NOT NULL DEFAULT 0,
            row_count INT NOT NULL DEFAULT 0,
            status VARCHAR(20) NOT NULL DEFAULT 'completed',
            trigger_type VARCHAR(20) NOT NULL DEFAULT 'scheduled',
            created_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        """)

    # Check if schedules 1 and 2 exist
    cur.execute("SELECT id FROM export_schedules WHERE id IN (1, 2)")
    existing = {r[0] if is_sqlite else r["id"] for r in cur.fetchall()}
    now_str = datetime.now(PKT).strftime("%Y-%m-%d %H:%M:%S")

    if 1 not in existing:
        cur.execute("""
        INSERT INTO export_schedules (id, name, schedule_time, export_type, enabled, updated_at)
        VALUES (1, 'Schedule 1 (Morning Export)', '09:00', 'daily', 1, ?)
        """ if is_sqlite else """
        INSERT INTO export_schedules (id, name, schedule_time, export_type, enabled, updated_at)
        VALUES (1, 'Schedule 1 (Morning Export)', '09:00', 'daily', 1, %s)
        """, (now_str,))

    if 2 not in existing:
        cur.execute("""
        INSERT INTO export_schedules (id, name, schedule_time, export_type, enabled, updated_at)
        VALUES (2, 'Schedule 2 (Evening Export)', '18:00', 'daily', 1, ?)
        """ if is_sqlite else """
        INSERT INTO export_schedules (id, name, schedule_time, export_type, enabled, updated_at)
        VALUES (2, 'Schedule 2 (Evening Export)', '18:00', 'daily', 1, %s)
        """, (now_str,))

    conn.commit()

EXPORT_COLUMNS = [
    ("id", "Student ID"),
    ("sr_no", "Sr #"),
    ("name", "Student Name"),
    ("country", "Country"),
    ("branch", "Branch"),
    ("counselor", "Counselor"),
    ("front_officer", "Front-end Officer"),
    ("back_officer", "Back-end Officer"),
    ("process_status", "Process Status"),
    ("intake", "Intake"),
    ("received_date", "Received Date"),
    ("study_level", "Study Level"),
    ("applied_for", "Applied Degree/Course"),
    ("last_degree", "Last Degree"),
    ("gpa", "GPA / Marks"),
    ("ielts", "IELTS / Test Score"),
    ("contact_no", "Contact Number"),
    ("student_email", "Student Email"),
    ("recovery_email", "Recovery Email"),
    ("portal_id", "Portal ID"),
    ("client_type", "Client Type"),
    ("total_package_fee", "Total Package Fee"),
    ("total_paid", "Total Paid"),
    ("balance_due", "Balance Due"),
    ("payment_status", "Payment Status"),
    ("initial_meeting", "Initial Meeting"),
    ("initial_meeting_mode", "Initial Meeting Mode"),
    ("second_meeting", "Second Meeting"),
    ("second_meeting_mode", "Second Meeting Mode"),
    ("tax_meeting", "Tax Meeting"),
    ("tax_meeting_mode", "Tax Meeting Mode"),
    ("meeting_priority", "Meeting Priority"),
    ("meeting_date", "Meeting Date"),
    ("shortlisting", "Shortlisting Status"),
    ("documents_status", "Documents Status"),
    ("admission_university", "Admission University"),
    ("application_status", "Application Status"),
    ("offer", "Offer Status"),
    ("enrollment", "Enrollment Status"),
    ("visa", "Visa Status"),
    ("appointment_status", "Appointment Status"),
    ("appointment_date", "Appointment Date"),
    ("visa_decision_date", "Visa Decision Date"),
    ("drive_link", "Google Drive Folder"),
    ("applications_count", "Applications Count"),
    ("applications_summary", "Applications Summary"),
    ("created_at", "Record Created (PKT)"),
    ("updated_at", "Record Updated (PKT)"),
]

def sanitize_csv_cell(val) -> str:
    """Format cell value and neutralize formula injection attacks."""
    if val is None:
        return ""
    s = str(val).strip()
    if s.startswith(("=", "+", "-", "@", "\t", "\r")):
        return "'" + s
    return s

def get_date_boundaries(export_type: str):
    """
    Compute start and end datetime in Asia/Karachi timezone.
    Returns (start_str, end_str) or (None, None) for complete.
    """
    now = datetime.now(PKT)
    if export_type == "daily":
        start = now.replace(hour=0, minute=0, second=0, microsecond=0)
        end = now.replace(hour=23, minute=59, second=59, microsecond=999999)
        return start.strftime("%Y-%m-%d %H:%M:%S"), end.strftime("%Y-%m-%d %H:%M:%S")
    elif export_type == "weekly":
        monday = now - timedelta(days=now.weekday())
        start = monday.replace(hour=0, minute=0, second=0, microsecond=0)
        end = now.replace(hour=23, minute=59, second=59, microsecond=999999)
        return start.strftime("%Y-%m-%d %H:%M:%S"), end.strftime("%Y-%m-%d %H:%M:%S")
    return None, None

def export_students_csv(
    export_type: str = "complete",
    country: str = None,
    output_file: str = None,
    trigger_type: str = "manual",
    schedule_id: int = None
) -> dict:
    """
    Generate an optimized CSV export of students.
    Returns dict with file_path, file_name, row_count, file_size, and duration.
    """
    t_start = datetime.now(PKT)
    conn = get_db_connection()
    init_tables(conn)
    is_sqlite = isinstance(conn, sqlite3.Connection)
    param_char = "?" if is_sqlite else "%s"

    start_date, end_date = get_date_boundaries(export_type)
    where_clauses = []
    params = []

    if country:
        where_clauses.append(f"s.country = {param_char}")
        params.append(country)

    if start_date and end_date:
        where_clauses.append(f"((s.created_at >= {param_char} AND s.created_at <= {param_char}) OR (s.updated_at IS NOT NULL AND s.updated_at >= {param_char} AND s.updated_at <= {param_char}))")
        params.extend([start_date, end_date, start_date, end_date])

    where_sql = ("WHERE " + " AND ".join(where_clauses)) if where_clauses else ""

    # Group concat compatibility: SQLite || vs MySQL CONCAT
    app_summary_sql = (
        "(SELECT GROUP_CONCAT(COALESCE(a.university, '') || ' (' || COALESCE(a.program, '') || ' - ' || COALESCE(a.result, 'Pending') || ')', '; ') FROM applications a WHERE a.student_id = s.id)"
        if is_sqlite else
        "(SELECT GROUP_CONCAT(CONCAT(COALESCE(a.university, ''), ' (', COALESCE(a.program, ''), ' - ', COALESCE(a.result, 'Pending'), ')') SEPARATOR '; ') FROM applications a WHERE a.student_id = s.id)"
    )

    sql = f"""
    SELECT 
        s.id, s.sr_no, s.name, s.country, s.branch, s.counselor,
        o.name AS front_officer, b.name AS back_officer, s.process_status,
        s.intake, s.received_date, s.study_level, s.applied_for, s.last_degree, s.gpa, s.ielts,
        s.contact_no, s.student_email, s.recovery_email, s.portal_id, s.client_type,
        s.total_package_fee, s.total_paid, s.balance_due, s.payment_status,
        s.initial_meeting, s.initial_meeting_mode, s.second_meeting, s.second_meeting_mode,
        s.tax_meeting, s.tax_meeting_mode, s.meeting_priority, s.meeting_date,
        s.shortlisting, s.documents_status, s.admission_university, s.application_status,
        s.offer, s.enrollment, s.visa, s.appointment_status, s.appointment_date, s.visa_decision_date,
        s.drive_link, s.created_at, s.updated_at,
        (SELECT COUNT(*) FROM applications a WHERE a.student_id = s.id) AS applications_count,
        {app_summary_sql} AS applications_summary
    FROM students s
    LEFT JOIN users o ON o.id = s.officer_id
    LEFT JOIN users b ON b.id = s.back_officer_id
    {where_sql}
    ORDER BY s.country, s.sr_no IS NULL, s.sr_no, s.id
    """

    cur = conn.cursor()
    cur.execute(sql, tuple(params))

    # Determine file path
    now_str = t_start.strftime("%Y%m%d_%H%M%S")
    type_label = export_type.capitalize()
    country_label = f"_{country}" if country else ""
    file_name = f"ScholarshipHive_Students_{type_label}{country_label}_{now_str}.csv"
    
    if not output_file:
        output_file = os.path.join(get_storage_exports_dir(), file_name)
    else:
        file_name = os.path.basename(output_file)

    headers = [col[1] for col in EXPORT_COLUMNS]
    col_keys = [col[0] for col in EXPORT_COLUMNS]

    row_count = 0
    # Stream directly into file with UTF-8 BOM for flawless Excel and spreadsheet viewing
    with open(output_file, mode="w", newline="", encoding="utf-8-sig") as f:
        writer = csv.writer(f)
        writer.writerow(headers)

        while True:
            batch = cur.fetchmany(100)
            if not batch:
                break
            for r in batch:
                row_count += 1
                row_data = [sanitize_csv_cell(r[k]) for k in col_keys]
                writer.writerow(row_data)

    file_size = os.path.getsize(output_file)
    duration = (datetime.now(PKT) - t_start).total_seconds()
    now_db_str = datetime.now(PKT).strftime("%Y-%m-%d %H:%M:%S")

    # Record in history table
    cur.execute(f"""
    INSERT INTO export_history (schedule_id, export_type, file_name, file_path, file_size, row_count, status, trigger_type, created_at)
    VALUES ({param_char}, {param_char}, {param_char}, {param_char}, {param_char}, {param_char}, {param_char}, {param_char}, {param_char})
    """, (schedule_id, export_type, file_name, output_file, file_size, row_count, "completed", trigger_type, now_db_str))

    # Update schedule if triggered by one
    if schedule_id:
        cur.execute(f"""
        UPDATE export_schedules 
        SET last_run_at = {param_char}, last_status = 'success', last_file = {param_char}, updated_at = {param_char}
        WHERE id = {param_char}
        """, (now_db_str, file_name, now_db_str, schedule_id))

    conn.commit()
    conn.close()

    return {
        "success": True,
        "export_type": export_type,
        "file_name": file_name,
        "file_path": output_file,
        "file_size": file_size,
        "row_count": row_count,
        "duration_seconds": round(duration, 3)
    }

def get_schedules():
    """Fetch schedule 1 and schedule 2 configs."""
    conn = get_db_connection()
    init_tables(conn)
    cur = conn.cursor()
    cur.execute("SELECT * FROM export_schedules ORDER BY id")
    rows = [dict(r) for r in cur.fetchall()]
    conn.close()
    return rows

def check_and_run_due_schedules():
    """
    Inspects schedules and runs any schedule that is due for the current minute/hour today
    and hasn't already been executed for today's slot (prevents duplicate runs).
    """
    conn = get_db_connection()
    init_tables(conn)
    cur = conn.cursor()
    cur.execute("SELECT * FROM export_schedules WHERE enabled = 1")
    schedules = [dict(r) for r in cur.fetchall()]
    conn.close()

    now = datetime.now(PKT)
    today_date = now.strftime("%Y-%m-%d")
    current_time_str = now.strftime("%H:%M")

    executed = []
    for s in schedules:
        sched_time = str(s.get("schedule_time", "09:00")).strip()
        last_run = str(s.get("last_run_at") or "")
        
        # Check if already ran today
        already_ran_today = last_run.startswith(today_date)
        
        # Match time (or within past 5 minutes if scheduler just woke up)
        sched_h, sched_m = map(int, sched_time.split(":"))
        due_dt = now.replace(hour=sched_h, minute=sched_m, second=0, microsecond=0)
        time_diff = (now - due_dt).total_seconds()

        # If it is past the scheduled time today (within 10 minutes) and hasn't run yet today
        if not already_ran_today and 0 <= time_diff <= 600:
            print(f"[SCHEDULER] Triggering {s['name']} (ID {s['id']}) for {today_date} {sched_time} PKT...")
            res = export_students_csv(
                export_type=s.get("export_type", "daily"),
                trigger_type="scheduled",
                schedule_id=s["id"]
            )
            executed.append(res)
            print(f"[SCHEDULER] Successfully exported {res['row_count']} rows to {res['file_name']}")

    return executed

def start_apscheduler_daemon():
    """
    Launch persistent APScheduler daemon that survives restarts
    and triggers Morning and Evening exports reliably.
    """
    from apscheduler.schedulers.blocking import BlockingScheduler
    from apscheduler.triggers.cron import CronTrigger

    scheduler = BlockingScheduler(timezone=PKT)
    print("=" * 60)
    print(" ScholarshipHive Automated Export Scheduler (APScheduler)")
    print(" Timezone: Asia/Karachi (PKT)")
    print("=" * 60)

    def trigger_job(schedule_id: int):
        conn = get_db_connection()
        cur = conn.cursor()
        cur.execute("SELECT * FROM export_schedules WHERE id = ?", (schedule_id,))
        row = cur.fetchone()
        conn.close()
        if not row:
            return
        s = dict(row)
        if not s.get("enabled"):
            print(f"[{datetime.now(PKT)}] Schedule {schedule_id} is disabled. Skipping.")
            return

        now_str = datetime.now(PKT).strftime("%Y-%m-%d")
        last_run = str(s.get("last_run_at") or "")
        if last_run.startswith(now_str):
            print(f"[{datetime.now(PKT)}] Schedule {schedule_id} already executed today. Skipping duplicate.")
            return

        print(f"[{datetime.now(PKT)}] Executing scheduled export: {s['name']} ({s['export_type']})...")
        res = export_students_csv(
            export_type=s.get("export_type", "daily"),
            trigger_type="scheduled",
            schedule_id=schedule_id
        )
        print(f"[{datetime.now(PKT)}] Completed: {res['file_name']} ({res['row_count']} rows in {res['duration_seconds']}s)")

    schedules = get_schedules()
    for s in schedules:
        sched_time = s.get("schedule_time", "09:00")
        try:
            h, m = map(int, sched_time.split(":"))
        except ValueError:
            h, m = 9, 0

        trigger = CronTrigger(hour=h, minute=m, timezone=PKT)
        scheduler.add_job(
            trigger_job,
            trigger=trigger,
            args=[s["id"]],
            id=f"export_job_{s['id']}",
            replace_existing=True,
            name=s["name"]
        )
        print(f"Registered {s['name']}: Daily at {h:02d}:{m:02d} PKT (Type: {s['export_type']}, Enabled: {bool(s['enabled'])})")

    # Also register periodic 1-minute check to handle missed slots / dynamic schedule updates
    scheduler.add_job(
        check_and_run_due_schedules,
        trigger="interval",
        minutes=1,
        id="check_due_interval",
        replace_existing=True,
        name="Missed schedule watchdog"
    )

    print("\nScheduler daemon is active. Press Ctrl+C to exit.\n")
    try:
        scheduler.start()
    except (KeyboardInterrupt, SystemExit):
        print("Scheduler daemon stopped.")

def main():
    parser = argparse.ArgumentParser(description="ScholarshipHive Export & Scheduler Manager")
    subparsers = parser.add_subparsers(dest="command")

    # export command
    p_export = subparsers.add_parser("export", help="Generate an export CSV")
    p_export.add_argument("--type", choices=["daily", "weekly", "complete"], default="complete")
    p_export.add_argument("--country", default=None)
    p_export.add_argument("--output", default=None)
    p_export.add_argument("--schedule-id", type=int, default=None)

    # run-schedule command
    p_run = subparsers.add_parser("run-schedule", help="Run a configured schedule immediately")
    p_run.add_argument("--id", type=int, required=True, choices=[1, 2])

    # scheduler daemon
    subparsers.add_parser("scheduler", help="Run APScheduler background daemon")

    # check-due
    subparsers.add_parser("check-due", help="Run any schedule currently due")

    # status
    subparsers.add_parser("status", help="Print schedule configurations and history")

    args = parser.parse_args()

    if args.command == "export":
        res = export_students_csv(
            export_type=args.type,
            country=args.country,
            output_file=args.output,
            trigger_type="manual",
            schedule_id=args.schedule_id
        )
        print(f"Export successful: {res['file_name']}")
        print(f"Rows: {res['row_count']} | Size: {res['file_size']} bytes | Time: {res['duration_seconds']}s")
        print(f"Saved at: {res['file_path']}")

    elif args.command == "run-schedule":
        schedules = {s["id"]: s for s in get_schedules()}
        if args.id not in schedules:
            print(f"Schedule {args.id} not found.")
            sys.exit(1)
        s = schedules[args.id]
        res = export_students_csv(
            export_type=s["export_type"],
            trigger_type="manual",
            schedule_id=args.id
        )
        print(f"Executed {s['name']}: {res['file_name']}")
        print(f"Exported {res['row_count']} rows in {res['duration_seconds']}s")

    elif args.command == "scheduler":
        start_apscheduler_daemon()

    elif args.command == "check-due":
        ran = check_and_run_due_schedules()
        if ran:
            print(f"Executed {len(ran)} due schedule(s).")
        else:
            print("No schedules due at this moment.")

    elif args.command == "status":
        print("=== SCHEDULER CONFIGURATIONS ===")
        for s in get_schedules():
            print(f"[{s['id']}] {s['name']}")
            print(f"    Time: {s['schedule_time']} PKT | Type: {s['export_type']} | Enabled: {bool(s['enabled'])}")
            print(f"    Last Run: {s['last_run_at'] or 'Never'} | Status: {s['last_status'] or 'None'}")
            print(f"    Last File: {s['last_file'] or 'None'}")
        
        conn = get_db_connection()
        cur = conn.cursor()
        cur.execute("SELECT * FROM export_history ORDER BY id DESC LIMIT 5")
        history = [dict(r) for r in cur.fetchall()]
        conn.close()
        print("\n=== RECENT EXPORT HISTORY ===")
        for h in history:
            print(f"- #{h['id']} [{h['created_at']}] {h['export_type'].upper()} ({h['trigger_type']}): {h['file_name']} ({h['row_count']} rows, {h['file_size']} bytes)")

    else:
        parser.print_help()

if __name__ == "__main__":
    main()
