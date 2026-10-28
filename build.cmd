@echo off
setlocal
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%~dp0tools\build-windows-installer.ps1" -Guided %*
set "build_exit=%ERRORLEVEL%"
exit /b %build_exit%
