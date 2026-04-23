# Deploy via GitHub Actions

Deployment target:
- User: `xelopat_ru_usr`
- Path: `/var/www/xelopat_ru_usr/data/www/xelopat.ru`

## GitHub Secrets
Add these repository secrets in GitHub:

- `DEPLOY_HOST` - server host or IP
- `DEPLOY_SSH_KEY` - private SSH key for `xelopat_ru_usr`
- `DEPLOY_PORT` - optional SSH port (default `22`)

## Notes
- Workflow file: `.github/workflows/deploy.yml`
- Deployment excludes are controlled by `.deployignore`
- Sensitive/local files are ignored by `.gitignore`
