<#
.SYNOPSIS
  Commits and pushes all changes to GitHub, which triggers Railway's
  auto-deploy for both the backend and frontend services.

.EXAMPLE
  .\deploy.ps1
  .\deploy.ps1 "fix login bug"
#>
param(
    [string]$Message = "Update $(Get-Date -Format 'yyyy-MM-dd HH:mm')"
)

Write-Host "Checking for changes..." -ForegroundColor Cyan
git status --short

$changes = git status --porcelain
if (-not $changes) {
    Write-Host "Nothing to deploy - working tree is clean." -ForegroundColor Yellow
    exit 0
}

git add -A
git commit -m "$Message"
if ($LASTEXITCODE -ne 0) {
    Write-Host "Commit failed - aborting." -ForegroundColor Red
    exit 1
}

git push origin main
if ($LASTEXITCODE -ne 0) {
    Write-Host "Push failed - aborting." -ForegroundColor Red
    exit 1
}

Write-Host "`nPushed to GitHub. Railway is now auto-deploying both services." -ForegroundColor Green
Write-Host "Backend:  https://event-platform-production-2717.up.railway.app" -ForegroundColor Gray
Write-Host "Frontend: https://charismatic-exploration-production-2cec.up.railway.app" -ForegroundColor Gray
Write-Host "Check progress at https://railway.com/project/10d279bc-e40a-479a-bef0-d6844d872df9" -ForegroundColor Gray
