@echo off
REM The vendor assets are already committed to this folder - the app works
REM offline out of the box. Run this only to RESTORE them if they go missing.
echo Restoring vendor assets (Bootstrap Icons + Chart.js + Inter)...
powershell -ExecutionPolicy Bypass -File "%~dp0download-vendor.ps1"
echo.
pause
