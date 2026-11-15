@echo off
rem 2FA code for: ippan_a2
rem Requires tools\test\output_dev_users.json (made by tools\test\dev_seed.php). Dev/test accounts only.
chcp 65001 >nul
set PYTHONIOENCODING=utf-8
where python >nul 2>nul || (echo python not found. Install Python 3 and add it to PATH. & pause & exit /b 1)
if not exist "%~dp0..\test\output_dev_users.json" (echo tools\test\output_dev_users.json not found. & pause & exit /b 1)
:loop
cls
python "%~dp0..\test\dev_code.py" ippan_a2
echo.
echo [Enter] refresh   [Ctrl+C] quit
pause >nul
goto loop
