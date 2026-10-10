@echo off
title ToolTrack Backup - Backup now
set "PHP=%~dp0..\..\..\php\php.exe"
if not exist "%PHP%" goto nophp
echo Taking a database backup and a project snapshot right now...
echo.
"%PHP%" "%~dp0run.php" --force-db --force-files
"%PHP%" "%~dp0run.php" --status
goto done
:nophp
echo Could not find XAMPP's php.exe at: %PHP%
:done
echo.
pause
