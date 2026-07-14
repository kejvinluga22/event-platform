@echo off
REM Double-click this file after finishing a change.
REM It commits and pushes to GitHub, which triggers Railway's auto-deploy.

cd /d "%~dp0"

echo Checking for changes...
git status --short

set haschanges=
for /f %%i in ('git status --porcelain') do set haschanges=1
if not defined haschanges (
    echo Nothing to deploy - working tree is clean.
    pause
    exit /b 0
)

git add -A
git commit -m "Update %date% %time%"
if errorlevel 1 (
    echo Commit failed - aborting.
    pause
    exit /b 1
)

git push origin main
if errorlevel 1 (
    echo Push failed - aborting.
    pause
    exit /b 1
)

echo.
echo Pushed to GitHub. Railway is now auto-deploying both services.
echo Backend:  https://event-platform-production-2717.up.railway.app
echo Frontend: https://charismatic-exploration-production-2cec.up.railway.app
echo Check progress at https://railway.com/project/10d279bc-e40a-479a-bef0-d6844d872df9
echo.
pause
