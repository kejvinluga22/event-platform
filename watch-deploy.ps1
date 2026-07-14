<#
.SYNOPSIS
  Watches the project for saved changes and auto-commits + pushes to GitHub,
  so every edit made in VS Code goes live on Railway within a few seconds.

.EXAMPLE
  .\watch-deploy.ps1
  .\watch-deploy.ps1 -IntervalSeconds 3
#>
param(
    [int]$IntervalSeconds = 5
)

Write-Host "Watching for changes every $IntervalSeconds s. Press Ctrl+C to stop." -ForegroundColor Cyan

while ($true) {
    Start-Sleep -Seconds $IntervalSeconds

    $changes = git status --porcelain
    if (-not $changes) {
        continue
    }

    $message = "Auto: $(Get-Date -Format 'yyyy-MM-dd HH:mm:ss')"
    git add -A
    git commit -m "$message" | Out-Null
    if ($LASTEXITCODE -ne 0) {
        Write-Host "Commit failed, retrying next cycle." -ForegroundColor Red
        continue
    }

    git push origin main
    if ($LASTEXITCODE -ne 0) {
        Write-Host "Push failed, retrying next cycle." -ForegroundColor Red
        continue
    }

    Write-Host "$message -> pushed, Railway is redeploying." -ForegroundColor Green
}
