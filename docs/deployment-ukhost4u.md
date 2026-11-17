# Deploying the trial to the existing UKhost4U account

UKhost4U shared hosting is cPanel on LiteSpeed. The account already runs PHP 8.4 and
MariaDB 11.4 — the v2 site is stuck on PHP 5.6 only because its code requires the removed
`ext/mysql` extension, not because the server is old. This application needs neither the
old PHP nor a database, so it can go live alongside the existing site without touching it.

## The shape to aim for

```
oridb.org            -> public_html/                 (v2, left exactly as it is)
v3.oridb.org         -> repositories/oridb-v3/public (this repository)
```

A subdomain is the whole trick. It isolates the trial completely: a mistake in v3 cannot
take down the live site, and the live site's `.htaccess`, PHP version and database are
untouched. It also means the cut-over, when it comes, is a document-root change rather
than a migration.

## Step by step

**1. Create the subdomain.** cPanel → *Domains* → *Create A Domain*. Name it `v3.oridb.org`.
Set the document root to `/home/CPANELUSER/repositories/oridb-v3/public`. Do not accept the
default `public_html/v3`.

**2. Set the PHP version for the subdomain.** cPanel → *MultiPHP Manager* → select the
subdomain → PHP 8.4 (or 8.3). The v2 site keeps its own setting; MultiPHP Manager is
per-domain. Do not change the setting on `oridb.org`.

**3. Enable SSH and add a deploy key.** cPanel → *SSH Access* → *Manage SSH Keys* →
*Generate a New Key* with **no passphrase** (cPanel's Git integration cannot use a
passphrase-protected key). Copy the public key into the GitHub repository under
*Settings → Deploy keys*, read-only.

**4. Clone the repository.** cPanel → *Git™ Version Control* → *Create* → toggle *Clone a
Repository* on. Clone URL `git@github.com:ORG/oridb-v3.git`, repository path
`/home/CPANELUSER/repositories/oridb-v3`. cPanel's Git supports SSH URLs only, not HTTPS.

**5. Issue the certificate.** cPanel → *SSL/TLS Status* → include the new subdomain in
AutoSSL. Wait for it to issue before sharing the URL.

**6. Check it.** Visit `https://v3.oridb.org/`. If you see a directory listing or PHP
source, the document root is wrong. If you see a 500, check *Errors* in cPanel — the usual
cause on a fresh clone is file permissions (`find . -type f -exec chmod 644 {} +`).

## Updating the site

cPanel → *Git Version Control* → *Manage* → **Update from Remote**. That is a `git pull` in
the checkout; because the document root points into the checkout, the new code is live
immediately. There is no build step.

cPanel does not pull automatically on a GitHub push. Either press the button, or add a cron
job: `cd ~/repositories/oridb-v3 && git pull --ff-only`.

If the host will not let the document root point outside `public_html`, use the
`.cpanel.yml` in the repository root instead: set `DEPLOYPATH`, press *Deploy HEAD Commit*,
and point the subdomain at `$DEPLOYPATH/public`. Keep `data/` and `src/` out of any
web-reachable directory either way.

## What stays off this server

Large binary tracks. GitHub Pages and `raw.githubusercontent.com` were both measured
returning HTTP 206 with `accept-ranges` and `access-control-allow-origin: *`, which is what
a genome browser needs; a shared-hosting account is the wrong place for that traffic and the
wrong place to depend on for a decade. Serve origin pages from here, serve tracks from a
host chosen for range requests, and archive an identical copy in Zenodo for the DOI.

## Risks specific to this account

- **Do not reuse the v2 database user.** The v2 credentials are in cleartext in
  `utilities/ori_connect.php` and must be rotated before any of that code is published.
  The trial needs no database at all, which is one reason to start this way.
- **`.htaccess` inheritance.** A subdomain under `public_html` would inherit the parent's
  `.htaccess`; one outside it does not. Another reason to place the checkout in
  `~/repositories`.
- **Keep the trial out of search results** until the URLs are final — the shipped
  `.htaccess` sends `X-Robots-Tag: noindex`. Remove that line at launch, not before.
