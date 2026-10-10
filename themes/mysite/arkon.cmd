@echo off
setlocal
if /I "%~1"=="validate" goto run
if /I "%~1"=="install" goto run
echo Usage: arkon.cmd validate ^| install
exit /b 1
:run
rem PHP: ARKON_PHP_BINARY if set, else Laravel Herd's PHP 8.4 if installed, else php on PATH.
if not defined ARKON_PHP_BINARY if exist "%USERPROFILE%\.config\herd\bin\php84\php.exe" set "ARKON_PHP_BINARY=%USERPROFILE%\.config\herd\bin\php84\php.exe"
if not defined ARKON_PHP_BINARY set "ARKON_PHP_BINARY=php"
if not exist "%~dp0..\..\artisan" (
  echo Arkon was not found. Keep this theme under ArkonLaravel\themes\mysite.
  exit /b 1
)
pushd "%~dp0..\.."
if errorlevel 1 exit /b 1
"%ARKON_PHP_BINARY%" artisan arkon:theme "%~1" "%~dp0."
set "arkonExit=%ERRORLEVEL%"
popd
exit /b %arkonExit%
