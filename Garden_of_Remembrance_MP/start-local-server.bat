@echo off
REM Start the PHP built-in web server for this project.
REM Usage: double-click this file or run it from the project folder.
cd /d "%~dp0"
php -S 127.0.0.1:8000 -t "%~dp0"
pause
