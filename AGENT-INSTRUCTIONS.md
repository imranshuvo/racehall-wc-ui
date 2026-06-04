# Racehall WC UI agent instructions

## Deployment default
- Bump the version first
- If the user says "deploy" or "push" for this plugin, deploy to the staging site.
- Do not run `git push` unless the user explicitly asks for a git push.
- Preferred staging deployment path: run `scripts/deploy-staging.sh` from the plugin root.

## Release packaging
- Before creating any release zip, bump the plugin header `Version` in `racehall-wc-ui.php`.
- Before creating any release zip, bump `RACEHALL_WC_UI_VERSION` in `racehall-wc-ui.php`.
- Create release zips from the parent directory so the archive contains a top-level `racehall-wc-ui/` folder.
- Never create a flat zip from inside the plugin root; WordPress installs expect the plugin files to live under the `racehall-wc-ui/` directory inside the archive.
- The zip filename may include the version, but the internal plugin folder must remain `racehall-wc-ui` and must not become `racehall-wc-ui-vX.Y.Z`.
- Do not include `.git/`, `.github/`, `.builds/`, `.local/`, `doc/`, `postman/`, or local/dev artifacts in release zips.

## Notes
- The repository includes staging deployment automation in `scripts/deploy-staging.sh`.
- For this project, staging deployment is the operational default unless the user states otherwise.
- This workspace is not a full WordPress installation. It is a plugin repository/workspace only.
- Do not assume the repository root is a WordPress root and do not run WordPress commands from here as if `wp-config.php` should exist in this tree.
- If WordPress or WP-CLI inspection is ever required, first locate the actual site separately and use an explicit `--path=/actual/wordpress/root`.

## API Reference
- Only authoritative BMI public booking API reference for this project: https://bmileisure.atlassian.net/wiki/external/YTYwMTA3YjAyNWVkNDAzMmJhNDkxZWE5OWZiYTc5YmM
- Use only the link above for API contract questions unless the user explicitly replaces it with a newer BMI doc.
- Do not rely on older saved Confluence exports, local PDF/text extracts, or previous BMI doc links for contract validation.
