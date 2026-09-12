@echo off
:: ToolTrack — Database Backup Runner
:: Double-click to run, or schedule with Windows Task Scheduler
::
:: Edit the path below to match your project folder:
set PROJECT=C:\xampp\htdocs\tooltrack

echo [%DATE% %TIME%] Starting ToolTrack backup...
"C:\xampp\php\php.exe" "%PROJECT%\backup.php"

if %ERRORLEVEL% == 0 (
    echo Backup completed successfully!
) else (
    echo BACKUP FAILED — Check your USB drive is plugged in.
    echo See backup_log.txt for details.
)

:: Remove the "pause" line below if running silently via Task Scheduler
pause
