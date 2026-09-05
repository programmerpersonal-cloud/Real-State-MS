@echo off
rem ══════════════════════════════════════════════════════════════════════
rem  Saxane — install the automatic backup scheduler (Windows)
rem
rem  Right-click this file and choose "Run as administrator", or run
rem  it from an elevated prompt. It registers ONE Windows scheduled task
rem  that ticks every five minutes; the runner it starts decides which
rem  backup schedules are actually due.
rem
rem  Usage:
rem    install_scheduler.bat                 install, ticking every 5 minutes
rem    install_scheduler.bat 15              install, ticking every 15 minutes
rem    install_scheduler.bat /uninstall      remove the task
rem
rem  This file is a shim. Everything it does is in install_scheduler.ps1
rem  next to it — batch cannot express a repeating trigger with no end date,
rem  and that is the one setting a backup scheduler must not get wrong.
rem ══════════════════════════════════════════════════════════════════════

setlocal
set "SCRIPT=%~dp0install_scheduler.ps1"

if not exist "%SCRIPT%" (
    echo.
    echo   ERROR: install_scheduler.ps1 is missing from %~dp0
    echo.
    exit /b 1
)

rem /uninstall in any position, otherwise a bare number is the interval.
if /i "%~1"=="/uninstall" goto uninstall
if /i "%~1"=="-uninstall" goto uninstall
if /i "%~1"=="--uninstall" goto uninstall

set "INTERVAL=5"
if not "%~1"=="" set "INTERVAL=%~1"

powershell -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT%" -IntervalMinutes %INTERVAL%
exit /b %ERRORLEVEL%

:uninstall
powershell -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT%" -Uninstall
exit /b %ERRORLEVEL%
