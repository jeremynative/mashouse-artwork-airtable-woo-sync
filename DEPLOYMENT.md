# Ma's House GitHub Deployment Workflow

This plugin should be managed from GitHub instead of editing files directly in SiteGround File Manager.

## Recommended Repo

Create or use a GitHub repo just for this plugin:

`mashouse-artwork-airtable-woo-sync`

The repo root should contain:

- `ma-artwork-airtable-woo-sync.php`
- `assets/`
- `README.md`
- `.github/workflows/deploy-mashouse-plugin.yml`

## GitHub Secrets

Add these in GitHub:

Settings -> Secrets and variables -> Actions -> New repository secret

- `SITEGROUND_HOST`
- `SITEGROUND_PORT`
- `SITEGROUND_USER`
- `SITEGROUND_SSH_KEY`
- `MASHOUSE_PLUGIN_PATH`

Current working values:

- `SITEGROUND_HOST`: `c1108516.sgvps.net`
- `SITEGROUND_PORT`: `18765`
- `SITEGROUND_USER`: `u4-tvqwqtej8qbs`
- `MASHOUSE_PLUGIN_PATH`: `/home/customer/www/mashouse.studio/public_html/wp-content/plugins/ma-artwork-airtable-woo-sync`

Use the direct SiteGround host above for deploys. Do not use `ssh.mashouse.studio`, because that hostname currently resolves through Cloudflare and GitHub Actions cannot SSH through it.

The local deploy key generated for this workflow is stored outside the plugin repo here:

- Private key for GitHub secret `SITEGROUND_SSH_KEY`:
  `C:\Users\JD\Documents\New project 4\.deploy-keys\mashouse_github_actions_siteground_ed25519`
- Public key to authorize in SiteGround SSH:
  `C:\Users\JD\Documents\New project 4\.deploy-keys\mashouse_github_actions_siteground_ed25519.pub`

Public key:

```text
ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIMnr30giE3+hG5dSFUUuxd+AUvUSHgpzXq0kFCQs0had github-actions-mashouse-plugin-deploy
```

The workflow has been tested from GitHub Actions and verifies the deployed plugin file exists after upload.

## Deployment Rules

- Edit plugin files locally or through GitHub.
- Commit to a branch first for larger changes.
- Merge to `main` only after testing.
- A push to `main` deploys the plugin to Ma's House automatically.
- You can also run the workflow manually from GitHub Actions.

## Safer Rollback

If a deploy breaks the site:

1. In GitHub, open the last good commit.
2. Revert the bad commit, or re-run the workflow from the previous good commit.
3. The plugin files on SiteGround will be replaced by the repo version.

This is safer than SiteGround File Manager edits because every change has a commit history.

## Emergency Edits

Avoid emergency File Manager edits unless the site is broken and GitHub deploy is unavailable.

If an emergency edit is made directly on SiteGround, copy that change back into GitHub immediately so GitHub stays the source of truth.
