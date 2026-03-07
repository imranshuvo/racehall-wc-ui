# Racehall WC UI agent instructions

## Deployment default
- Bump the version first
- If the user says "deploy" or "push" for this plugin, deploy to the staging site.
- Do not run `git push` unless the user explicitly asks for a git push.
- Preferred staging deployment path: run `scripts/deploy-staging.sh` from the plugin root.

## Release packaging
- Before creating any release zip, bump the plugin header `Version` in `racehall-wc-ui.php`.
- Before creating any release zip, bump `RACEHALL_WC_UI_VERSION` in `racehall-wc-ui.php`.
- Do not include `.git/`, `.github/`, `.builds/`, `.local/`, `doc/`, `postman/`, or local/dev artifacts in release zips.

## Notes
- The repository includes staging deployment automation in `scripts/deploy-staging.sh`.
- For this project, staging deployment is the operational default unless the user states otherwise.
