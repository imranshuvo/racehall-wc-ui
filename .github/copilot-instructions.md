# Copilot instructions for `racehall-wc-ui`

## Release packaging (mandatory)
- Always create distributable zip files in `.builds/` inside this repository.
- Never create release zip files outside the repo root (e.g. `/var/www/html`).
- Zip must contain exactly one top-level folder: `racehall-wc-ui/`.
- Zip content must include plugin runtime files only.

## Exclusions (mandatory)
Do **not** include these paths in release zips:
- `.git/`
- `.github/`
- `.builds/`
- `.local/`
- `doc/`
- `postman/`
- Any local/dev artifacts

## Versioning before zipping (mandatory)
Before creating a release zip:
1. Bump plugin header `Version` in `racehall-wc-ui.php`.
2. Bump `RACEHALL_WC_UI_VERSION` in `racehall-wc-ui.php`.
3. Commit those version changes.

## Preferred build command
From `/var/www/html`:

```bash
zip -r racehall-wc-ui/.builds/racehall-wc-ui-v<version>.zip racehall-wc-ui \
  -x 'racehall-wc-ui/.git/*' \
     'racehall-wc-ui/.github/*' \
     'racehall-wc-ui/.builds/*' \
     'racehall-wc-ui/.local/*' \
     'racehall-wc-ui/doc/*' \
     'racehall-wc-ui/postman/*'
```
