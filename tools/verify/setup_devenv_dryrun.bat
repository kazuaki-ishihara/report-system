@echo off
rem Show the setup plan only (changes nothing)
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup_devenv.ps1" -DryRun %*
set RC=%ERRORLEVEL%
echo.
pause
exit /b %RC%
