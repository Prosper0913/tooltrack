@echo off
rem CLI only; suitable for Task Scheduler, with no interactive pause.
"C:\xampp\php\php.exe" "%~dp0backup.php" backup
exit /b %ERRORLEVEL%