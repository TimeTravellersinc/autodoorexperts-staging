# autodoorexperts.ca local staging

## Prerequisites
- Docker Desktop running
- Git installed
- VS Code installed

## Start local staging
```powershell
cd C:\Users\marcr.TIME_MACHINE\Downloads\wordpress-dev\autodoorexperts-staging
.\scripts\start.ps1
```

- WordPress: http://localhost:8080
- phpMyAdmin: http://localhost:8081

## Reset DB from production dump
```powershell
cd C:\Users\marcr.TIME_MACHINE\Downloads\wordpress-dev\autodoorexperts-staging
.\scripts\reset-db.ps1
```

## Git workflow
```powershell
cd C:\Users\marcr.TIME_MACHINE\Downloads\wordpress-dev\autodoorexperts-staging
git checkout -b codex/staging-setup
git add .
git commit -m "Set up local WordPress staging environment"
# set your remote once:
# git remote add origin <your-repo-url>
git push -u origin codex/staging-setup
```

## Push to production safely
- Develop and test locally first.
- Merge to your main branch only after validation.
- Deploy via your preferred Hostinger workflow (Git deployment or SFTP/SSH rsync).
