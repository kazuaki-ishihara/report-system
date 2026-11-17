@echo off
rem PHP install checker (double-click to run)
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0check_php.ps1" %*
set RC=%ERRORLEVEL%
echo.
pause
exit /b %RC%
