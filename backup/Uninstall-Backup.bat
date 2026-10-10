@echo off
title ToolTrack Backup - Uninstall
powershell -NoProfile -ExecutionPolicy Bypass -Command "Unregister-ScheduledTask -TaskName 'ToolTrack Backup' -Confirm:$false; Write-Host 'Scheduled backup removed. Existing backups were NOT deleted.'"
echo.
pause
