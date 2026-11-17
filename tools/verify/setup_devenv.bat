@echo off
rem Build local verification environment (double-click to run)
rem Options are passed through, e.g. setup_devenv.bat -SkipPhp -ResetDb
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0setup_devenv.ps1" %*
set RC=%ERRORLEVEL%
echo.
pause
exit /b %RC%
