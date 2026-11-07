@echo off
rem Starts the WedWorks <-> Hermes bridge. Leave this window open while suppliers chat.
cd /d "%~dp0"
python hermes_bridge.py
echo.
echo The bridge stopped. Press any key to close.
pause >nul
