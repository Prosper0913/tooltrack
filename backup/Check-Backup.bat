@echo off
title ToolTrack Backup - Check
set "PHP=%~dp0..\..\..\php\php.exe"
if not exist "%PHP%" goto nophp
"%PHP%" "%~dp0run.php" --check
"%PHP%" "%~dp0run.php" --status
goto done
:nophp
echo Could not find XAMPP's php.exe at: %PHP%
:done
echo.
pause
