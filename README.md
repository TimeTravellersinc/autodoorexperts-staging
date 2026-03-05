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
- Note: first request after a restart is slower while PHP opcode cache warms up.
- Plugins run from a Docker volume for speed. On first run, `start.ps1` seeds that volume from `site/wp-content/plugins`.
- Theme/content files still come from your local repo (`site/wp-content`) for Git + VS Code workflow.
- If you install/update plugins in WordPress admin, those changes stay in the Docker volume (not auto-committed to Git).

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
