# One-time setup (Windows): makes the two automatic backup schedules survive restarts.
# Creates a Task Scheduler job that runs "php portal\backup_worker.php tick" every minute.
# The tick only starts schedules that are due (09:00 / 18:00 PKT by default, editable in Admin > Download all),
# never starts the same schedule twice in a day, and catches up a run missed while the PC was off (up to 6 h).
# Run in PowerShell:  powershell -ExecutionPolicy Bypass -File scripts\install_backup_scheduler.ps1
# Remove later with:  schtasks /Delete /TN "ScholarshipHive Backup Tick" /F
$php = (Get-Command php -ErrorAction Stop).Source
$worker = Join-Path (Split-Path $PSScriptRoot -Parent) 'portal\backup_worker.php'
$cmd = "`"$php`" `"$worker`" tick"
schtasks /Create /TN "ScholarshipHive Backup Tick" /SC MINUTE /MO 1 /TR $cmd /F
Write-Host "Installed. Check with: php `"$worker`" status"
# Linux / cPanel cron equivalent:  * * * * * /usr/bin/php /path/to/portal/backup_worker.php tick >/dev/null 2>&1
