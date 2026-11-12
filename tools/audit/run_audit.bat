@echo off
rem Report system audit launcher (double-click to run)
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0Invoke-ReportAudit.ps1" %*
echo.
pause
