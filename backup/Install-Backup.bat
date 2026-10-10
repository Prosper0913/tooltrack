@echo off
title ToolTrack Backup - Install
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0install-task.ps1"
echo.
pause
