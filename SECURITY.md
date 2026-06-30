# Security Policy

## Supported versions

We release security fixes for the **latest** tagged version of each Feedico open-source repository. Older tags may not receive patches.

## Reporting a vulnerability

**Do not** open a public GitHub issue for security-sensitive reports.

Email **support@feedico.io** with:

- A description of the issue and impact
- Steps to reproduce
- Affected repository and version (if known)

We aim to acknowledge reports within a few business days.

## API tokens and credentials

- Feedico API tokens (`fdco_…`) are **secrets**. Never commit them, paste them in issues, or share them in screenshots.
- Use `.env` files locally and keep them out of git (see `.gitignore`).
- Rotate tokens from your [Feedico dashboard](https://feedico.io) if you suspect exposure.

## WordPress plugin note

`feedico-wp-plugin` stores credentials encrypted when OpenSSL is available. Review server access controls and database backups as part of your site security posture.

---

[feedico.io](https://feedico.io) · [Documentation](https://feedico.io/docs)
