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

**The order matters.** Clone *before* creating the subdomain. cPanel refuses to clone into
a directory that already contains anything, and creating a domain whose document root is
`…/oridb-v3/public` makes cPanel create that path — after which the clone is blocked. See
*Troubleshooting* below if you have already hit this.

**1. Deploy key — only if the repository is private.** cPanel accepts `http://`,
`https://`, `ssh://` and `git://` clone URLs. A **public** repository therefore needs no
key at all: clone it over HTTPS and skip to step 2. The SSH route exists because a
password cannot be put in a clone URL, so a private repository must authenticate with a
key.

If the repository is private: cPanel → *SSH Access* → *Manage SSH Keys* → *Generate a New
Key* with **no passphrase** (cPanel's Git integration cannot use a passphrase-protected
key). Copy the public key into the GitHub repository under *Settings → Deploy keys*,
read-only, and use the SSH form of the URL in step 2.

**2. Clone the repository.** cPanel → *Git™ Version Control* → *Create* → toggle *Clone a
Repository* on. Repository path `/home/CPANELUSER/repositories/oridb-v3`, which must not
exist or must be empty. Clone URL:

- public repository: `https://github.com/DNAReplicationLab/oridb-v3.git`
- private repository: `ssh://git@github.com/DNAReplicationLab/oridb-v3.git`

**Let cPanel do the cloning.** A checkout you create yourself over SSH in *Terminal* works
perfectly as a website, but cPanel only offers *Update from Remote* for repositories it
cloned itself. Cloning by hand costs you the button.

After it finishes, confirm `/home/CPANELUSER/repositories/oridb-v3/public/index.php`
exists. The clone created the `public/` directory; the next step points a domain at it.

**3. Create the subdomain.** cPanel → *Domains* → *Create A Domain*. Name it `v3.oridb.org`.
Set the document root to `/home/CPANELUSER/repositories/oridb-v3/public`. Do not accept the
default `public_html/v3`. cPanel is content to point a document root at an existing
directory; it is only Git that insists on an empty one.

**4. Set the PHP version for the subdomain.** cPanel → *MultiPHP Manager* → select the
subdomain → PHP 8.4 (or 8.3). The v2 site keeps its own setting; MultiPHP Manager is
per-domain. Do not change the setting on `oridb.org`.

**5. Issue the certificate.** cPanel → *SSL/TLS Status* → include the new subdomain in
AutoSSL. Wait for it to issue before sharing the URL.

**6. Make the directory chain traversable.** A document root outside `public_html` only
works if the web server — which runs as a different user — can traverse every directory
above it. A clone made by hand in *Terminal* creates them `700` under the default umask,
which blocks it:

```bash
ls -ld ~ ~/repositories ~/repositories/oridb-v3
chmod 711 ~/repositories ~/repositories/oridb-v3
```

`711` allows traverse without allowing a listing, which is what you want at a level
holding `.git/`, `bin/` and `docs/`. The home directory is normally already `711`.

**7. Check it.** Visit `https://v3.oridb.org/`. If you see a directory listing or PHP
source, the document root is wrong — it must end in `/public`. A 500 is almost always file
permissions (`find . -type f -exec chmod 644 {} +`). A 404 on every URL is step 6.

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

## Troubleshooting

### "You cannot use the … directory because it already contains files"

cPanel's Git will only clone into a directory that is empty or does not exist. Something
got there first — almost always because the subdomain was created before the clone, so
cPanel made the document root path for you.

**Look before you touch anything.** cPanel → *Terminal* (or SSH):

```bash
ls -la ~/repositories/oridb-v3
find ~/repositories/oridb-v3 -type f | head -20
ls -d ~/repositories/oridb-v3/.git 2>/dev/null && echo "HAS .git" || echo "NO .git"
```

`find … | head` truncates before it reaches a `.git` directory, so test for that
explicitly — it is what separates a clone that worked from files that arrived another way.

What you find decides what to do:

| Contents | Meaning | Action |
|---|---|---|
| `public/` (empty or with a placeholder), `.well-known/`, `cgi-bin/`, a default `index.html` | cPanel created the document root; nothing of yours is here | Route A or B below |
| A `.git/` directory **and** the repository files | A previous clone actually succeeded | Do not clone again — register it: *Git Version Control* → *Create* with *Clone a Repository* **off**, repository path set to this directory |
| A `.git/` directory but few or no files | A clone failed part-way, usually because the deploy key was not yet on GitHub | Confirm the key first (below), then Route A |
| The repository files (`src/`, `public/`, `bin/`) but **no** `.git/` | The files arrived without Git — an upload or an unpacked tarball | Route C: adopt them in place, no deletion |
| A `.git/` whose remote is an `https://…` URL | A clone made by hand in *Terminal*, not by cPanel | Route D: it works, but *Update from Remote* will be missing |
| Anything you do not recognise | Unknown | Stop; do not delete. Work out what it is first |

**Route A — move the stub aside and clone into the same path.** Preferred, because the
document root you configured keeps working afterwards. Nothing is deleted.

```bash
mv ~/repositories/oridb-v3 ~/repositories/oridb-v3.before-clone
```

Then repeat the clone. The subdomain will 404 for the minute in between, which is
expected. Once the site is up, the `.before-clone` directory can be removed through File
Manager (which moves it to Trash, so it is recoverable).

**Route B — clone somewhere new.** Touches nothing that already exists. Clone to
`/home/CPANELUSER/repositories/oridb-v3-site`, then change the subdomain's document root to
`/home/CPANELUSER/repositories/oridb-v3-site/public` under *Domains*.

**Route C — the files are there but there is no `.git/`.** Turn the directory into a real
checkout in place; nothing is deleted and nothing is moved.

```bash
cd ~/repositories/oridb-v3
git init -b main
git remote add origin git@github.com:DNAReplicationLab/oridb-v3.git
git fetch origin
git reset origin/main      # no --hard: every file on disk is kept
git status
```

`git reset` without `--hard` moves the branch pointer to GitHub's commit while leaving the
working tree alone, so `git status` then reports exactly how the server's copy differs from
GitHub. A clean tree means the two are identical. Modified files mean the server copy is
older — inspect with `git diff`, then take GitHub's version with `git checkout -- .`.

Then register it: *Git Version Control* → *Create* with *Clone a Repository* **off**, and
the repository path set to this directory. cPanel adopts any directory containing a valid
`.git`, which is also the route to use whenever a clone has already succeeded.

**Route D — a working checkout that cPanel did not create.** Check what you have:

```bash
git -C ~/repositories/oridb-v3 remote -v
```

An `https://github.com/…` remote means the clone was made by hand. The site will serve
perfectly from it. What you lose is cPanel's *Update from Remote* button, which appears
only for repositories cPanel cloned itself. Two ways forward:

*Keep it, and pull from the command line.* Nothing to undo; updates become:

```bash
git -C ~/repositories/oridb-v3 pull --ff-only
```

which is also what you would put in a cron job, and is more reliable than the button
anyway.

*Or hand the path to cPanel.* Move the manual checkout aside and let cPanel clone into the
same path, so the document root you planned still applies:

```bash
mv ~/repositories/oridb-v3 ~/repositories/oridb-v3.manual
```

Then *Git Version Control* → *Create* with *Clone a Repository* **on**, the HTTPS URL, and
the original path. Once the site is up, remove the `.manual` copy through File Manager.

**Check the deploy key before retrying** — only relevant if the repository is private. In cPanel → *Terminal*:

```bash
ssh -T git@github.com
```

A working deploy key answers `Hi DNAReplicationLab/oridb-v3! You've successfully
authenticated, but GitHub does not provide shell access.` — note that it names the
*repository*, not a user, which is how you can tell a deploy key from a personal key.
`Permission denied (publickey)` means the key has not been added to the repository's
*Settings → Deploy keys*, or a passphrase-protected key was generated.

### Every URL returns 404, but AutoSSL issued a certificate

A contradiction worth reading carefully: AutoSSL validating proves the document root is
correct and reachable, because cPanel writes its challenge there and fetches it over HTTP.
If every page still 404s, the files are not the problem and neither is DNS.

The usual cause is the directory chain above the document root. cPanel writes the ACME
challenge as **you**; the web server reads pages as a **different user**, so a parent
directory mode `700` stops it reaching `public/` even though the challenge worked.
LiteSpeed reports the failed traverse as 404, not 403, which sends people looking for
missing files.

```bash
ls -ld ~ ~/repositories ~/repositories/oridb-v3 ~/repositories/oridb-v3/public
```

Read the modes down the chain. `drwx------` at any level above the document root is the
fault; `drwxr-xr-x` on `public/` alone is not enough.

```bash
chmod 711 ~/repositories ~/repositories/oridb-v3
```

This is specific to a checkout created by hand — `git clone` in *Terminal* applies your
umask, while a clone cPanel performs comes out traversable.

Locating the true document root, if you are unsure which directory is live:

```bash
find ~ -maxdepth 5 -type d -name ".well-known"
```

AutoSSL's challenge directory is written into the document root, so that is where the
domain is actually served from.

### Blank page or HTTP 500 with no error anywhere

UKhost4U runs PHP in a jail whose filesystem view begins at the document root. Files above
it are not merely unreadable — they do not exist for PHP. Measured on this account:
`open_basedir` is unset, PHP runs as the account user (`uid=1188`), and yet
`scandir('/home/nieduszy/repositories/oridb-v3')` from a page returns exactly one entry,
`public`. `file_exists()` on `../app/bootstrap.php` returns `false`.

This is why `app/` and `data/` live *inside* `public/` with a deny-all `.htaccess` instead
of one level above it. If you ever move them back out, every page returns a bodyless 500.

To confirm the same constraint on another host, drop a file in the web root containing:

```php
<?php header('Content-Type: text/plain');
echo "open_basedir=", ini_get('open_basedir') ?: '(none)', "\n";
echo "parent: ", implode(' ', array_diff(scandir(dirname(__DIR__)), ['.','..'])), "\n";
```

Fetch it over HTTPS and delete it immediately. If the parent listing shows only your web
root directory, the host jails PHP the same way.

Note that a 500 of this kind leaves nothing in an error log and shows nothing in the
browser, because the failure happens on the first `require` before any output. The way in
is a temporary script that calls `ini_set('display_errors', '1')` itself and advances
through the application in labelled stages.

### AutoSSL stops renewing after a re-clone

The certificate challenge is written into `.well-known/` inside the document root. If that
directory was moved aside with the stub, cPanel recreates it on the next AutoSSL run; if a
renewal fails in the meantime, trigger it manually from *SSL/TLS Status* → *Run AutoSSL*.

## What stays off this server

Large binary tracks. GitHub Pages and `raw.githubusercontent.com` were both measured
returning HTTP 206 with `accept-ranges` and `access-control-allow-origin: *`, which is what
a genome browser needs; a shared-hosting account is the wrong place for that traffic and the
wrong place to depend on for a decade. Serve origin pages from here, serve tracks from a
host chosen for range requests, and archive an identical copy in Zenodo for the DOI.

## Risks specific to this account

- **Do not reuse the v2 database user.** Treat the v2 database credentials as compromised
  and rotate them, independently of anything v3 does; the v2 code configures its connection
  in cleartext, so publishing any of that tree would disclose them. The trial needs no
  database at all, which is one reason to start this way.
- **`.htaccess` inheritance.** A subdomain under `public_html` would inherit the parent's
  `.htaccess`; one outside it does not. Another reason to place the checkout in
  `~/repositories`.
- **Keep the trial out of search results** until the URLs are final — the shipped
  `.htaccess` sends `X-Robots-Tag: noindex`. Remove that line at launch, not before.
