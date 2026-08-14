# Deploy

**Status: wired up and live.** Push-to-deploy via a bare git repo + `post-receive` hook on
Hostinger.

## How it works

- Bare repo on the server: `~/repo-abilisto.git` (outside the web root, not public).
- `~/repo-abilisto.git/hooks/post-receive` runs `git checkout -f master` with
  `GIT_WORK_TREE=/home/u942667021/domains/abilisto.site/public_html` — every push lands directly
  in the live site.
- Local remote: `git remote -v` shows `production` →
  `ssh://145.223.108.65:65002/home/u942667021/repo-abilisto.git`. SSH auth uses a dedicated
  deploy key (`~/.ssh/id_ed25519_hostinger`), configured in `~/.ssh/config` under the
  `hostinger-abilisto` host alias and a matching entry for the raw IP (git connects via the IP
  in the remote URL, not the alias).
- `.env` and `storage/secrets/<firebase-service-account>.json` live directly on the server,
  created once by hand — they are never part of the git history (see `.gitignore`), so a push
  never touches them.

## Ongoing deploys

**Primary path:** push to GitHub — `.github/workflows/deploy.yml` auto-deploys on every push to
`master` (it SSHs into Hostinger using the `HOSTINGER_SSH_KEY`/`HOSTINGER_KNOWN_HOSTS` repo
secrets and pushes to the `production` remote itself, triggering the same hook below).

```bash
git push origin master
```

**Manual fallback**, if you ever need to deploy without GitHub (e.g. CI is down):

```bash
git push production master
```

Either way, the hook checks the new code out over the live files. `uploads/`, `private_uploads/`,
`.env`, and `storage/secrets/` are never tracked, so they're untouched by every push.

**Caveat:** a `git checkout -f` only updates paths that are part of the repo. If you ever add a
file on the server by hand (outside of a push) that shares a path with something you later delete
from the repo, the push won't remove it — you'd clean that up manually over SSH, the same way the
original `key.txt`/`info.php`/test-script cleanup was done by hand alongside the first deploy.

## First deploy (already done, kept here for reference)

1. Moved the live `api/<firebase-service-account>.json` into `storage/secrets/` and added a
   denying `.htaccess` there, *before* the first push (the new code expects it at that path).
2. Created `.env` on the server with production values, *before* the first push (avoids a window
   where deployed code has no secrets to read).
3. `git push production master` → hook deployed the cleaned-up code.
4. Manually removed the leftover files a checkout can't touch because they were never tracked:
   `key.txt`, `info.php`, `abilisto.zip`, `Logo.zip`, `OneSignalSDK-v16-ServiceWorker(.zip)`, and
   the 10 test/debug scripts.
5. Verified: `https://abilisto.site` loads, `/info.php` and `/key.txt` 404, `/.env` and
   `/storage/secrets/*.json` return 403, Google OAuth login still renders with the right client ID.

## Secret rotation checklist

These were found hardcoded/exposed in the live web root before this cleanup. Since they were
publicly reachable, treat them as compromised and rotate in each provider's dashboard:

- [ ] Xendit secret key
- [ ] Google Gemini API key
- [ ] Firebase service-account key (`storage/secrets/*.json`) — regenerate in Firebase console
- [ ] Google OAuth client secret
- [ ] OpenRouter API key
- [ ] Resend API key
- [ ] OneSignal REST API key
- [ ] iProgSMS API token
- [ ] The Hostinger DB password (was duplicated in two files)
- [ ] Revoke the live Google OAuth access token that was cached in `includes/token_cache.json`
      (deleted from the repo, but was live at the time it was found)

After rotating each one, update `.env` locally and on the server.
