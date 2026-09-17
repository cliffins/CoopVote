@echo off
setlocal enabledelayedexpansion
title CoopVote

rem ---------------------------------------------------------------
rem  CoopVote - open the system in your browser
rem
rem  Double-click this file. It works out its own folder name, so it
rem  keeps working if the project is renamed or moved, and it asks
rem  the server whether it is really there before sending a browser
rem  to an address that would only show an error.
rem ---------------------------------------------------------------

rem The name of the folder this file sits in, e.g. CoopVote
for %%I in ("%~dp0.") do set "FOLDER=%%~nxI"
set "URL=http://localhost/%FOLDER%/"

rem curl ships with Windows 10 and 11. Without it we cannot check, so
rem we just open the browser and let the page speak for itself.
where curl >nul 2>&1 || goto :open

set "CODE=000"
for /f %%C in ('curl -s -o nul -m 4 -w "%%{http_code}" "%URL%" 2^>nul') do set "CODE=%%C"

rem Nothing answered at all: Apache is not running.
if "%CODE%"=="000" goto :apache_off

rem Apache answered, but there is nothing at this address: the project
rem is not sitting inside C:\xampp\htdocs under this name.
if "%CODE%"=="404" goto :wrong_place

:open
start "" "%URL%"
exit /b 0

:apache_off
echo.
echo   CoopVote could not be reached at
echo       %URL%
echo.
echo   Nothing answered, so Apache is not running - or it is
echo   listening on a port other than 80.
echo.
echo   Open the XAMPP Control Panel and press Start next to both
echo   Apache and MySQL, then run this file again.
echo.
choice /c YN /n /m "   Open the XAMPP Control Panel now? [Y/N] "
if errorlevel 2 exit /b 1
goto :xampp

:wrong_place
echo.
echo   Apache is running, but there is nothing at
echo       %URL%
echo.
echo   This folder is:
echo       %~dp0
echo.
echo   For Apache to serve it, the project folder has to sit inside
echo       C:\xampp\htdocs
echo.
echo   Move or copy it there, then run this file again from its new
echo   home. The address follows the folder name, so it will be
echo   http://localhost/ plus whatever you call the folder.
echo.
pause
exit /b 1

:xampp
if exist "C:\xampp\xampp-control.exe" (
    start "" "C:\xampp\xampp-control.exe"
    exit /b 1
)
echo.
echo   Could not find C:\xampp\xampp-control.exe
echo   Start XAMPP yourself, then run this file again.
echo.
pause
exit /b 1
