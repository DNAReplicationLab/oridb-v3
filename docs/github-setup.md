# Getting the trial repository onto GitHub

Written for a Mac with Git already installed (2.54, Apple Git) but with no global Git
identity, no SSH key and no `gh` command line tool — which is the state this machine was
in when these notes were written. Every step is either the Terminal or the GitHub website;
nothing else needs installing.

Two choices to make before you start, because they appear in every command afterwards.

**Who owns the repository.** A GitHub *organisation* rather than a personal account.
OriDB needs to outlive any one person's account, and an organisation lets you add
maintainers without transferring ownership. If the group has no organisation yet, create
one first: github.com → your avatar → *Your organizations* → *New organization* → the free
plan is sufficient. The organisation for this work is **DNAReplicationLab**, and the repository
below is `oridb-v3` — so the commands assume `github.com/DNAReplicationLab/oridb-v3`. Substitute your
own username throughout if you would rather start it under a personal account.

**Public or private.** Public from the start is defensible here: this repository contains
no credentials, no unpublished data, and nothing from the v2 tree. The checks in step 4
confirm that before you push. If you would rather review in private first, create it
private — GitHub Pages on a private repository requires a paid plan, so the preview site
would have to wait until you flip it public.

---

## 1. Set your Git identity (once per machine)

```bash
git config --global user.name  "Your Name"
git config --global user.email "you@example.ac.uk"
git config --global init.defaultBranch main
```

Use the address attached to your GitHub account, or GitHub will not link commits to you.

## 2. Create an SSH key and give the public half to GitHub

```bash
ssh-keygen -t ed25519 -C "oridb on $(hostname -s)"
```

Accept the default path (`~/.ssh/id_ed25519`) and set a passphrase. Then:

```bash
ssh-add --apple-use-keychain ~/.ssh/id_ed25519   # remember the passphrase
pbcopy < ~/.ssh/id_ed25519.pub                   # public key now on the clipboard
```

On github.com: avatar → *Settings* → *SSH and GPG keys* → *New SSH key* → paste →
*Add SSH key*. Confirm it works:

```bash
ssh -T git@github.com
```

You want `Hi USERNAME! You've successfully authenticated`. A warning about the host's
authenticity on first connection is normal — answer `yes`.

> This key is **yours**, for pushing from your Mac. If the repository is private, the
> server needs its own separate key, generated inside cPanel — see
> `docs/deployment-ukhost4u.md` step 1. Never use one key for both.

## 3. Put the files where you want them

Unpack the trial repository somewhere you will keep it — not in Downloads, and ideally
not inside OneDrive, which can corrupt a `.git` directory by syncing it mid-write.

```bash
mkdir -p ~/code && cd ~/code
tar -xzf ~/Downloads/oridb-v3-trial.tar.gz
cd oridb-v3
ls
```

You should see `bin data docs public src LICENSE README.md`.

## 4. Check that nothing sensitive is about to be committed

Do this before the first commit, not after. Anything pushed to a public repository must be
treated as disclosed even if you delete it a minute later.

```bash
# No database passwords, no connection strings:
grep -rniE "password|passwd|mysql_connect|mysql_pconnect" --exclude-dir=.git . || echo "clean"

# No v2 dump or v2 code accidentally sitting in the tree:
find . -name '*.sql' -o -name '*.tar' -o -name 'ori_connect.php' | grep . || echo "clean"

# What Git would actually add:
git status --porcelain --untracked-files=all | wc -l
```

The first command is expected to return exactly nine hits, all harmless — read them and
satisfy yourself:

- `public/app/config.php.dist` (two hits) — a template naming the environment variable
  `ORIDB_DB_PASSWORD`, with no value in it
- `bin/smoke_test.php` and `docs/page-structure.md` — the string `../../etc/passwd`, which
  is hostile input the test suite deliberately feeds to the pages
- `docs/github-setup.md` (three hits) and `docs/deployment-ukhost4u.md` (one) — the
  documentation describing the check you are running and when a key is needed

Anything else is a real finding. Stop and read it before you continue.

**Never add `nieduszy_oridb.sql` or `oridb.tar` to this repository.** The v2 tree contains
live credentials in `utilities/ori_connect.php`, and those credentials still need rotating
regardless of what happens to v3. The shipped `.gitignore` excludes `*.sql` and `*.tar` so
a stray copy cannot be committed by accident.

## 5. Make the first commit

```bash
git init
git add .
git commit -m "OriDB v3.0 trial: every origin tier, flat-file PHP application"
```

Check what you committed:

```bash
git log --stat --oneline | head -40
```

37 files, under 300 KB in total. If you see hundreds, or a file of several megabytes, something
unwanted was picked up — `git reset` and look at step 4 again.

## 6. Create the empty repository on GitHub

On github.com → *+* (top right) → *New repository*.

- Owner: `DNAReplicationLab`
- Name: `oridb-v3`
- Description: *OriDB v3.0 — rebuild of the DNA replication origin database*
- Public or private, as decided above
- **Do not** tick "Add a README", "Add .gitignore" or "Choose a license"

An empty repository is important: initialising it on the website creates a commit that
your local history does not share, and the first push is then rejected.

## 7. Push

```bash
git remote add origin git@github.com:DNAReplicationLab/oridb-v3.git
git branch -M main
git push -u origin main
```

Reload the GitHub page: the files are there, and the README renders on the front page.

## 8. Turn on the automated checks

The repository ships two workflows in `.github/workflows/`.

**Tests** (`test.yml`) run on every push with no setup: PHP 8.1, 8.3 and 8.4 in parallel,
linting every file, running `bin/smoke_test.php`, and verifying the data checksums recorded
in `data/release.json`. Watch the first run under the *Actions* tab. If Actions are
disabled by an organisation policy, enable them at *Settings* → *Actions* → *General* →
*Allow all actions*.

**Preview site** (`pages.yml`) publishes the static mirror. Enable it once:
*Settings* → *Pages* → *Build and deployment* → Source: **GitHub Actions**. Then re-run the
`preview` workflow from the *Actions* tab, or push any commit. After a minute the site is at

```
https://dnareplicationlab.github.io/oridb-v3/
```

That is the URL to circulate for comment while the PHP version is still being reviewed.
It is generated from the same templates as the live site, so it cannot drift from it.

## 9. Connect the hosting account

Now do steps 1–2 of `docs/deployment-ukhost4u.md`.

If you made the repository **public**, there is nothing to do here: cPanel clones a public
repository over HTTPS with no key at all.

If you made it **private**, generate a key **in cPanel** and add its public half to this
repository under *Settings* → *Deploy keys* → *Add deploy key*, with *Allow write access*
left **off**. The server only ever reads.

## 10. Working on it from here

Commit directly to `main` while the trial is yours alone. Once anyone else is involved,
branch:

```bash
git checkout -b lift-to-saccer3
# ... edit ...
git add -A && git commit -m "Lift cerevisiae coordinates to sacCer3"
git push -u origin lift-to-saccer3
```

GitHub then offers a *Compare & pull request* button. The tests run on the branch, so you
see whether it is sound before merging. After merging:

```bash
git checkout main && git pull
```

To refresh the site on UKhost4U afterwards: cPanel → *Git Version Control* → *Manage* →
*Update from Remote*.

## 11. Open Discussions, so reviewers can comment

The footer of every page points colleagues at GitHub Discussions. Discussions is off by
default and cannot be enabled from a file in the repository, so this is four clicks, once:

1. Repository → **Settings** → *General* → *Features* → tick **Discussions**, then
   **Set up discussions**. The welcome post it offers can be deleted.
2. Discussions tab → the pencil beside *Categories* → make the set match what the footer
   asks for. Suggested:

   | Category | Format | For |
   |---|---|---|
   | Data corrections | Open-ended | A record that is wrong: the accession, what is wrong, and the source that says so |
   | Tier arguments | Open-ended | Whether a particular site belongs in the tier it is in |
   | Site feedback | Open-ended | Anything on the pages that reads wrongly or does not work |
   | Questions | Q&A | How to read something, or how to get at the data |

   Delete *Announcements*, *Ideas*, *Polls* and *Show and tell* unless you want them; a
   short list is what makes people post in the right place.
3. Settings → *Moderation options* → decide whether posting is open to anyone with a GitHub
   account (the default, and what the footer promises) or restricted.
4. Watch the repository (**Watch** → *Custom* → tick *Discussions*) or nothing will reach
   your inbox.

A thread that turns out to be a real correction converts to an issue from the thread's own
menu (**⋯** → *Create issue from discussion*), which is how a comment becomes tracked work
without the reviewer having to know that distinction exists.

## 12. Marking a release

When the trial is settled enough to cite:

```bash
git tag -a v3.0.0-trial.1 -m "First trial release: every origin tier, recomputed"
git push origin v3.0.0-trial.1
```

Connect the repository to Zenodo (zenodo.org → *GitHub* → flip the switch for
`DNAReplicationLab/oridb-v3`) **before** pushing the tag, and Zenodo archives that tag automatically and
mints a DOI. The checksums in `data/release.json` are what tie the Zenodo copy to the
served one.

---

## If something goes wrong

| Symptom | Cause | Fix |
|---|---|---|
| `Permission denied (publickey)` on push | Key not added, or agent not holding it | Re-run step 2; `ssh -T git@github.com` must greet you by name |
| `Updates were rejected because the remote contains work that you do not have locally` | The GitHub repository was initialised with a README | Delete it and recreate it empty, or `git pull --rebase origin main` and push again |
| `public/app/config.local.php` shows in `git status` | You created a local config | Correct — it is gitignored; if it appears, you added it with `git add -f` |
| Pages workflow succeeds but the URL 404s | Pages source still set to a branch | *Settings* → *Pages* → Source: GitHub Actions, then re-run |
| Tests fail on `Verify released data checksums` | `public/data/*.tsv` edited by hand | Regenerate with `bin/build_from_v2.py`, which rewrites the checksums |
