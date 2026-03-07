#!/usr/bin/env bash
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"
ENV_FILE="${1:-$REPO_ROOT/.local/ftp.env}"

if [[ ! -f "$ENV_FILE" ]]; then
  echo "Missing env file: $ENV_FILE"
  exit 1
fi

# shellcheck disable=SC1090
source "$ENV_FILE"

required_vars=(FTP_HOST FTP_PORT FTP_USER FTP_PASS FTP_REMOTE_PATH)
for var_name in "${required_vars[@]}"; do
  if [[ -z "${!var_name:-}" ]]; then
    echo "Missing required value: $var_name"
    exit 1
  fi
done

if ! command -v lftp >/dev/null 2>&1; then
  echo "lftp is required but not installed. Install it with: sudo apt-get install -y lftp"
  exit 1
fi

cd "$REPO_ROOT"

echo "Deploying plugin to ftp://${FTP_HOST}:${FTP_PORT}/${FTP_REMOTE_PATH}"

lftp -u "$FTP_USER","$FTP_PASS" -p "$FTP_PORT" "$FTP_HOST" <<LFTP_CMDS
set ssl:verify-certificate no
set ftp:passive-mode true
mkdir -p "$FTP_REMOTE_PATH"
mirror -R --delete --verbose \
  --exclude-glob AGENT-INSTRUCTIONS.md \
  --exclude-glob .git/ \
  --exclude-glob .github/ \
  --exclude-glob .gitignore \
  --exclude-glob .builds/ \
  --exclude-glob .local/ \
  --exclude-glob scripts/ \
  --exclude-glob doc/ \
  --exclude-glob postman/ \
  "$REPO_ROOT/" "$FTP_REMOTE_PATH"
bye
LFTP_CMDS

echo "Deploy completed."
