# Deploy

Chosen approach: **SSH into the Hostinger server, deploy via `git pull`.**

This is not wired up yet — it needs your Hostinger SSH host/username and for SSH key auth to be
set up (no passwords handled here). Once you have that:

## One-time setup

1. **Enable SSH access** in hPanel (Hostinger → Advanced → SSH Access) if not already on.
2. **Generate/add an SSH key** for your local machine and add the public key in hPanel, or use
   `ssh-copy-id` if Hostinger allows it. Test with `ssh -p <port> <user>@<host>`.
3. On the server, turn the live `public_html` into a git repo that tracks this one:
   ```bash
   ssh -p <port> <user>@<host>
   cd ~/public_html
   git init
   git remote add origin <your local/GitHub repo URL, however you choose to host it>
   git fetch origin
   git reset --hard origin/main   # first sync — review what this overwrites first!
   ```
   ⚠️ `public_html` on Hostinger currently has live `uploads/`, `private_uploads/`, and `.env` —
   none of those are in the git repo (by design, see `.gitignore`), so a `git reset --hard` won't
   touch them as long as they're untracked on the server too. Double-check with `git status`
   before running `reset --hard` on production.
4. Create `.env` directly on the server (via SSH, not committed) with the real production values.
5. Confirm `storage/secrets/<firebase-service-account>.json` exists on the server at the path set
   in `FIREBASE_SERVICE_ACCOUNT_PATH`.

## Ongoing deploys

```bash
ssh -p <port> <user>@<host>
cd ~/public_html
git pull origin main
```

Consider adding a `post-merge` git hook on the server later if you want this to run automatically
after every pull (e.g. clearing a cache, restarting the chat server).

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
