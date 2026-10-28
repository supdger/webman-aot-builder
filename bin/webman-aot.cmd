@echo off
:: WEBMAN_AOT_BUILDER_PUBLIC_LAUNCHER
setlocal

if /I "%PROCESSOR_ARCHITECTURE%"=="AMD64" goto architecture_ok
if /I "%PROCESSOR_ARCHITEW6432%"=="AMD64" goto architecture_ok
1>&2 echo webman-aot requires Windows x64.
exit /b 1

:architecture_ok
if defined WEBMAN_AOT_BUILDER_HOME (
    set "AOT_HOME=%WEBMAN_AOT_BUILDER_HOME%"
) else (
    if not defined LOCALAPPDATA (
        1>&2 echo webman-aot cannot resolve the current user data directory.
        exit /b 1
    )
    set "AOT_HOME=%LOCALAPPDATA%\webman-aot-builder"
)

set "PRIVATE_PHP=%AOT_HOME%\current\runtime\php.exe"
set "APPLICATION=%AOT_HOME%\current\app\bin\webman-aot-builder.php"

if not exist "%PRIVATE_PHP%" (
    1>&2 echo webman-aot private PHP runtime is missing: %PRIVATE_PHP%
    exit /b 1
)
if not exist "%APPLICATION%" (
    1>&2 echo webman-aot application is missing: %APPLICATION%
    exit /b 1
)

if /I "%~1"=="uninstall" goto uninstall_dispatch

set "WEBMAN_AOT_CALLER_CWD=%CD%"
pushd "%AOT_HOME%\current\runtime"
if errorlevel 1 exit /b 70
".\php.exe" -c php.ini -d extension_dir=ext "..\app\tools\windows-php-bootstrap.php" "%APPLICATION%" %*
set "runtime_exit=%ERRORLEVEL%"
popd
exit /b %runtime_exit%

:uninstall_dispatch
for %%I in ("%~dp0.") do set "PUBLIC_BIN=%%~fI"
if not "%~3"=="" goto uninstall_usage
if /I "%~2"=="--list" goto uninstall_list
if not "%~2"=="" goto uninstall_usage
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%AOT_HOME%\current\app\installer\windows\uninstall.ps1" -InstallRoot "%AOT_HOME%" -BinDir "%PUBLIC_BIN%"
exit /b %ERRORLEVEL%
:uninstall_list
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%AOT_HOME%\current\app\installer\windows\uninstall.ps1" -InstallRoot "%AOT_HOME%" -BinDir "%PUBLIC_BIN%" -List
exit /b %ERRORLEVEL%
:uninstall_usage
1>&2 echo Usage: webman-aot uninstall [--list]
exit /b 64
