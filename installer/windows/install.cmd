@echo off
setlocal
chcp 65001 >nul
powershell.exe -NoLogo -NoProfile -ExecutionPolicy Bypass -File "%~dp0install-guided.ps1" %*
set "setup_exit=%ERRORLEVEL%"
exit /b %setup_exit%
