#!/usr/bin/env bash
# BEGREEN DEV VPS only. Bind mounts; never build images or reload fixtures.
# make dev-vps-update [SCHEMA=1] [ALLOW_DESTRUCTIVE_SCHEMA=YES]
set -Eeuo pipefail
umask 022

cd /home/fede/www/begreen || exit 1
[[ "$(pwd -P)" == /home/fede/www/begreen ]] || { echo 'DEV_PATH=UNEXPECTED'; exit 2; }

REPORT=$(mktemp /tmp/begreen-dev-vps-update.XXXXXX.txt)
exec > >(tee -a "$REPORT") 2>&1
STAGE=PREFLIGHT
trap 'rc=$?; if (( rc != 0 )); then printf "DEV_VPS_UPDATE=BLOCKED\nBLOCKED_AT=%s\nEXIT_CODE=%d\n" "$STAGE" "$rc"; fi' EXIT

SCHEMA=${SCHEMA:-0}
ALLOW_DESTRUCTIVE_SCHEMA=${ALLOW_DESTRUCTIVE_SCHEMA:-NO}
[[ "$SCHEMA" == 0 || "$SCHEMA" == 1 ]] || { echo 'SCHEMA_OPTION=INVALID'; exit 2; }
[[ "$ALLOW_DESTRUCTIVE_SCHEMA" == NO || "$ALLOW_DESTRUCTIVE_SCHEMA" == YES ]] || { echo 'DESTRUCTIVE_OPTION=INVALID'; exit 2; }

printf 'DEV_VPS_UPDATE=START\nREPORT=%s\nSCHEMA_APPROVED=%s\n' "$REPORT" "$SCHEMA"
[[ "$(git branch --show-current)" == main ]] || { echo 'DEV_BRANCH=UNEXPECTED'; exit 2; }

# Exclude exactly the four historical .env.local.*.bak untracked files; nothing else.
python3 - <<'PY'
import fnmatch
import pathlib
import subprocess

raw = subprocess.check_output(['git', 'status', '--porcelain=v1', '-z', '-uall'])
entries = [x for x in raw.decode('utf-8', 'surrogateescape').split('\0') if x]
if len(entries) != 4 or any(
    not x.startswith('?? ') or
    not fnmatch.fnmatch(pathlib.PurePosixPath(x[3:]).name, '.env.local.*.bak')
    for x in entries
):
    raise SystemExit('DEV_GIT_STATUS=UNEXPECTED')
print('DEV_GIT_STATUS=ONLY_4_PREEXISTING_BACKUPS')
PY

STAGE=FETCH
git fetch --quiet origin main
git merge-base --is-ancestor HEAD origin/main || { echo 'DEV_GIT_DIVERGENCE=BLOCKED'; exit 2; }

# Check the incoming changes *before* touching working-tree code.
# Conservative: any entity/mapping/migration changes require an explicit schema decision.
STAGE=SCHEMA_PREFLIGHT
if git diff --name-only -z HEAD..origin/main -- | python3 -c '
import sys
paths=[p.decode("utf-8", "surrogateescape") for p in sys.stdin.buffer.read().split(b"\0") if p]
risky=[p for p in paths if p.startswith(("app/src/Entity/", "app/migrations/", "app/config/doctrine/")) or p.startswith("app/config/packages/doctrine")]
for path in risky: print("SCHEMA_REVIEW_FILE="+path)
sys.exit(10 if risky else 0)
'; then
  schema_candidates=0
else
  rc=$?
  [[ "$rc" == 10 ]] || { echo 'SCHEMA_PREFLIGHT=ERROR'; exit "$rc"; }
  schema_candidates=1
fi
if (( schema_candidates )) && [[ "$SCHEMA" != 1 ]]; then
  echo 'SCHEMA_APPROVAL_REQUIRED=YES'
  echo 'NO_PULL=YES (run make dev-vps-update SCHEMA=1 after approving schema/backup)'
  exit 2
fi

# A schema-authorized deploy always gets a fresh, complete backup BEFORE git pull.
# This target never purges any data.
if [[ "$SCHEMA" == 1 ]]; then
  STAGE=BACKUP
  BACKUP_LOG=$(mktemp /tmp/begreen-dev-vps-backup.XXXXXX.txt)
  make db-dump-fixtures | tee "$BACKUP_LOG"
  BACKUP_PATH=$(sed -n 's/^Creating dev DB dump: //p' "$BACKUP_LOG" | tail -n 1)
  [[ "$BACKUP_PATH" == backups/begreen_clean_fixtures_*.sql && -s "$BACKUP_PATH" ]] || { echo 'BACKUP_VERIFY=FAIL'; exit 1; }
  printf 'BACKUP_VERIFY=PASS\nBACKUP_PATH=%s\nBACKUP_BYTES=%s\n' "$BACKUP_PATH" "$(wc -c < "$BACKUP_PATH")"
fi

STAGE=GIT_PULL
# Fast-forward only. umask 022 (inherited from this script) prevents new 0600 code files.
git pull --ff-only origin main

STAGE=CODE_PERMISSIONS
# Only versioned runtime code can get public-read bits; secrets/backups excluded.
python3 - <<'PY'
import os
import pathlib
import stat
import subprocess

allowed = ('app/src/', 'app/config/', 'app/templates/', 'app/translations/',
           'app/assets/', 'app/public/fixtures/', 'app/migrations/', 'app/bin/')
extra = {'app/public/index.php'}
items = subprocess.check_output(['git', 'ls-files', '-z']).split(b'\0')
checked = fixed = 0
for raw in items:
    if not raw:
        continue
    path = pathlib.Path(os.fsdecode(raw))
    value = path.as_posix()
    if not (value.startswith(allowed) or value in extra):
        continue
    # Never change tracked keys, private config or credentials.
    if value.startswith('app/config/secrets/') or path.suffix in ('.key', '.pem') or path.name.startswith('.env'):
        continue
    if path.is_symlink():
        raise SystemExit('TRACKED_RUNTIME_SYMLINK=UNEXPECTED: ' + value)
    if not path.is_file():
        raise SystemExit('TRACKED_RUNTIME_FILE_MISSING: ' + value)
    info = path.stat()
    checked += 1
    # Add group/other read without modifying executable bits or ownership.
    missing = (stat.S_IRGRP | stat.S_IROTH) & ~info.st_mode
    if missing:
        os.chmod(path, stat.S_IMODE(info.st_mode) | missing)
        fixed += 1
print(f'TRACKED_RUNTIME_FILES_CHECKED={checked}')
print(f'TRACKED_RUNTIME_FILES_FIXED={fixed}')
PY

STAGE=START_SERVICES
# --no-build is deliberate: DEV runs the repository by bind-mounted volumes.
make dev-vps-up
STAGE=COMPOSER
make composer-install

# Check Doctrine mapping before assets/cache and before reporting success.
STAGE=SCHEMA_SYNC
SCHEMA_LOG=$(mktemp /tmp/begreen-dev-vps-schema.XXXXXX.txt)
make schema-dump > "$SCHEMA_LOG" 2>&1 || { echo 'SCHEMA_DUMP=ERROR'; cat "$SCHEMA_LOG"; exit 1; }
if grep -q 'Nothing to update' "$SCHEMA_LOG"; then
  echo 'SCHEMA_SYNC=ALREADY_IN_SYNC'
else
  echo 'SCHEMA_DIFF=DETECTED'
  if [[ "$SCHEMA" != 1 ]]; then
    echo 'SCHEMA_REQUIRED=YES; HALT (do not report deploy success)'
    exit 2
  fi
  # Refuse unexpectedly destructive DDL without a second, explicit approval.
  if grep -Eiq '\b(DROP|TRUNCATE|RENAME|CHANGE|MODIFY)\b' "$SCHEMA_LOG" && [[ "$ALLOW_DESTRUCTIVE_SCHEMA" != YES ]]; then
    echo 'SCHEMA_DESTRUCTIVE_REVIEW_REQUIRED=YES; HALT'
    echo 'Review schema SQL on DEV and rerun with ALLOW_DESTRUCTIVE_SCHEMA=YES if authorized.'
    exit 2
  fi
  make schema-update
  make schema-dump > "$SCHEMA_LOG" 2>&1
  grep -q 'Nothing to update' "$SCHEMA_LOG" || { echo 'SCHEMA_SYNC=FAIL'; exit 1; }
  echo 'SCHEMA_SYNC=PASS'
fi

STAGE=ASSETS
make assets-build
STAGE=CACHE
make cache-clear

STAGE=RUNTIME_SMOKE
# Verify PHP as the actual web user and both previously failing source paths.
docker compose -p begreen --env-file app/.env --env-file app/.env.local \
  -f app/docker-compose.yml -f app/docker-compose.dev.yml -f app/docker-compose.vps.yml \
  exec -T -u www-data php php -r '
foreach (["/app/config/bundles.php", "/app/src/Entity/Measure.php"] as $file) {
    if (!is_readable($file)) { fwrite(STDERR, "WEB_READ=FAIL ".$file."\n"); exit(1); }
}
echo "WEB_READ=PASS\n";
'
docker compose -p begreen --env-file app/.env --env-file app/.env.local \
  -f app/docker-compose.yml -f app/docker-compose.dev.yml -f app/docker-compose.vps.yml \
  exec -T -u www-data php php bin/console about --no-ansi --quiet

HTTP_CODE=$(curl --silent --show-error --location --output /dev/null --write-out '%{http_code}' \
  --max-time 15 https://begreen.tech-investments.net/login)
[[ "$HTTP_CODE" == 200 ]] || { printf 'HTTP_LOGIN=%s\n' "$HTTP_CODE"; exit 1; }
echo 'HTTP_LOGIN=200'

STAGE=FINAL_GIT
[[ "$(git rev-parse HEAD)" == "$(git rev-parse origin/main)" ]] || { echo 'DEV_HEAD=UNEXPECTED'; exit 1; }
# Rerun the same exclusion guard after deployment.
python3 - <<'PY'
import fnmatch, pathlib, subprocess
raw = subprocess.check_output(['git', 'status', '--porcelain=v1', '-z', '-uall'])
entries = [x for x in raw.decode('utf-8', 'surrogateescape').split('\0') if x]
if len(entries) != 4 or any(not x.startswith('?? ') or not fnmatch.fnmatch(pathlib.PurePosixPath(x[3:]).name, '.env.local.*.bak') for x in entries):
    raise SystemExit('DEV_GIT_FINAL=UNEXPECTED')
print('DEV_GIT_FINAL=PASS')
PY
printf 'DEV_HEAD=%s\nIMAGE_BUILD=NO\nFIXTURES=NO\nAUTHENTICATED_QA=MANUAL\nDEV_VPS_UPDATE=PASS\n' "$(git rev-parse HEAD)"
