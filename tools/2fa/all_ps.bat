@echo off
rem 2FA code for: all users (PowerShell version, no Python needed). Refreshes every 30 seconds.
rem Requires tools\test\output_dev_users.json (made by tools\test\dev_seed.php). Dev/test accounts only.
chcp 65001 >nul
if not exist "%~dp0..\test\output_dev_users.json" (echo tools\test\output_dev_users.json not found. & pause & exit /b 1)
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0dev_code.ps1" -Watch -Interval 30
pause
