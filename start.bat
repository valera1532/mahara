@echo off
cd /d "%~dp0"
if exist ".runtime\php\php.exe" (
  ".runtime\php\php.exe" -S localhost:8000 server/router.php
) else (
  php -S localhost:8000 server/router.php
)
