@echo off
rem Installs the WedWorks plugin into a Hermes profile.   install.bat PROFILE_NAME   (for example: install.bat wedworks-chat)
if "%~1"=="" (
  echo Usage: install.bat PROFILE_NAME
  echo Example: install.bat wedworks-chat
  pause
  exit /b 1
)
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0install.ps1" -Profile %1
echo.
pause
