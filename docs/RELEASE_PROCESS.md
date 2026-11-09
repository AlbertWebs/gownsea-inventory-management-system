# GOWNSEA production release process

This is the standard release workflow for changes intended for `https://app.gownsea.com/`. Push the change to GitHub `main` before deploying. Build the release from that pushed commit, deploy it to the active EC2 release directory, and verify the live health endpoint.

The current EC2 host is shared with unrelated sites. Keep each deployment inside `/var/www/gownsea`; do not run `prepare-ubuntu-24.04.sh`, edit host-wide service configuration, or apply AWS infrastructure templates during an application release.

## 1. Push the change

Review the worktree and diff, commit the intended files, and push `main`:

```powershell
git status --short
git diff --check
git add <intended-files>
git commit -m "Describe the change"
git push origin main
git rev-parse --short HEAD
```

Use the pushed commit hash as the release ID. Check required GitHub Actions for that commit before deployment when CI is configured for the repository.

## 2. Build a clean artifact from the pushed commit

Run from the repository root in PowerShell. This packages only committed files, then generates the production Vite assets in a temporary working directory:

```powershell
$releaseId = "r-$(git rev-parse --short HEAD)"
$work = Join-Path $env:TEMP "gownsea-$releaseId"
$sourceTar = Join-Path $env:TEMP "gownsea-$releaseId-source.tar"
$bundle = Join-Path $env:TEMP "gownsea-$releaseId.tar.gz"

New-Item -ItemType Directory -Force -Path $work | Out-Null
git archive --format=tar --output=$sourceTar HEAD
tar.exe -xf $sourceTar -C $work
Push-Location $work
npm ci
npm run build
Pop-Location
tar.exe -czf $bundle -C $work .
```

The deploy script installs production Composer dependencies on EC2. Do not copy `.env`, `storage`, `.git`, `vendor`, `node_modules`, or private keys into the artifact. The application release script links `/var/www/gownsea/shared/.env` and shared storage itself.

## 3. Check migrations and current health

Before deploying, record the current release for rollback and check the health endpoint. Review any new database migrations and confirm a current backup before applying schema changes. The release script enters maintenance mode and runs `php artisan migrate --force` as part of rollout.

```powershell
$key = $env:GOWNSEA_EC2_KEY_PATH
ssh.exe -i $key ubuntu@app.gownsea.com "sudo readlink -f /var/www/gownsea/current; curl -fsS https://app.gownsea.com/up"
```

Set `GOWNSEA_EC2_KEY_PATH` to the operator's PEM path in the local environment. Do not commit that key or write its contents into a command, log, or project document.

## 4. Transfer and deploy

Transfer the archive to the Ubuntu operator account, extract it in a unique staging directory, and invoke the repository's release script as root. Example:

```powershell
$remoteArchive = "/home/ubuntu/gownsea-$releaseId.tar.gz"
$remoteStage = "/home/ubuntu/gownsea-$releaseId"
scp.exe -i $key $bundle "ubuntu@app.gownsea.com:$remoteArchive"
ssh.exe -i $key ubuntu@app.gownsea.com "mkdir -p '$remoteStage' && tar -xzf '$remoteArchive' -C '$remoteStage' && sudo bash '$remoteStage/deploy/aws/ec2/deploy-release.sh' '$remoteStage' '$releaseId' && rm -rf '$remoteStage' '$remoteArchive'"
```

The release script refuses a release ID already in use and requires the production environment file and `public/build/manifest.json`. It installs production dependencies, enters maintenance mode, applies migrations, warms Laravel caches, swaps `/var/www/gownsea/current`, brings the app up, restarts the GOWNSEA queue worker when installed, and reloads Nginx.

## 5. Verify and recover

Verify the app and login page return successfully, then inspect recent Laravel and queue logs if the response is unhealthy:

```powershell
curl.exe -fsS -o NUL -w "health=%{http_code}`n" https://app.gownsea.com/up
curl.exe -sS -o NUL -w "login=%{http_code}`n" https://app.gownsea.com/login
```

If the release fails before the symlink swap, the previous release remains active. If a post-swap check fails, point `/var/www/gownsea/current` back to the recorded release using the same atomic `current.next` symlink swap, bring Laravel out of maintenance mode, reload Nginx, and verify `/up` again. A code rollback does not reverse database migrations; restore data only through the approved recovery procedure and a compatible backup.
