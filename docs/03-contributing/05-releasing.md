# Cutting a release

This fork publishes its own releases, independently of upstream. This page is the
procedure, for humans and for agents.

## What this fork is based on

The deployment line is built on **upstream's most recent stable tag**, never on
upstream's development line.

`v1.11.0+paisans` was cut from a branch tracking upstream's unreleased work. That
carried an in-progress PDO to Redis session migration together with a `Redis`
service that never sends `AUTH`, and every request touching a session returned
`NOAUTH Authentication required` against a password-protected Valkey. Upstream had
not released that work because it was not finished. We shipped it anyway.

So the line is assembled as:

1. Upstream's latest stable tag.
2. Upstream's **environment, dependencies and toolchain**, taken as whole files
   from the development line rather than cherry-picked, because `composer.lock`
   cannot be replayed piecemeal. The full set:

   ```
   composer.json composer.lock package.json package-lock.json
   docker/ ci/ .devcontainer/ .github/
   tools/ .php-cs-fixer.dist.php psalm.xml
   ```

   Miss the last three and CI fails in a way that looks like a formatting error
   but is php-cs-fixer refusing to run on a PHP version it does not support.

3. Our own commits.

Upstream's **behavioural** changes from the unreleased line are excluded. Adopt
them when upstream releases them, or cherry-pick one deliberately with a reason.

After importing the environment, confirm it touched no application code: nothing
under `src/`, `config/`, `templates/`, `assets/`, `migrations/` or `translations/`.

Feature branches are unaffected by all of this. They are still cut from
`upstream-mirror`, because they are destined for upstream pull requests and must
apply to upstream's development line.

## Versioning

The version lives in exactly one place, `config/services.yaml`:

```yaml
mbin_version: '1.11.0+paisans'
```

Everything else derives from it: the git tag, the container image tag, the
version reported in nodeinfo, and the federation User-Agent. Never write a
version anywhere else.

**`+paisans` is a constant.** It is SemVer build metadata marking the build as
this fork, and it never changes or gains a counter.

**The primary version is what moves**, and this fork owns it:

| Kind of change | Example |
|---|---|
| Bug fixes, documentation, packaging | `1.11.0+paisans` -> `1.11.1+paisans` |
| New features, backported upstream features | `1.11.0+paisans` -> `1.12.0+paisans` |
| Breaking changes for instance operators | `1.11.0+paisans` -> `2.0.0+paisans` |

The number is the fork's, not upstream's. `1.11.1+paisans` means "this fork's
1.11.1", not "upstream 1.11.1 plus our changes". Do not try to keep it in step
with upstream's numbering: absorbing upstream work is an ordinary change and
takes whichever bump its content deserves.

Build metadata is ignored for SemVer precedence, so ordering comes entirely from
the primary version. That is why the counter goes there and not in the suffix:
`1.11.0+paisans` and `1.11.0+paisans.2` would compare *equal*, which would make
"which build is newer" unanswerable.

### Tag and image tag are spelled differently

A Docker tag cannot contain `+`. The release job maps it to `-` and drops the
leading `v`:

| | |
|---|---|
| git tag | `v1.11.1+paisans` |
| `mbin_version` | `1.11.1+paisans` |
| image tag | `1.11.1-paisans` |

## Procedure

### 1. Bump the version

Branch from `develop`, edit `mbin_version` in `config/services.yaml`, open a pull
request into `develop`. **Give the bump its own pull request**, changing nothing
else.

The bump is what triggers the release, so it should be an explicit, reviewable
act rather than a line buried in a feature pull request. Keeping it separate also
makes the trigger easy to find in history when a release misfires, and stops a
half-merged feature branch from leaving the version describing something other
than what is on `develop`.

Merging it releases nothing on its own: the release job is gated on
`refs/heads/main` before it ever reads the version. The release happens in step 3.

### 2. Open the release pull request

`develop` into `main`, titled `Release v<version>`.

The body becomes the GitHub Release notes verbatim, so write it for an operator
deciding whether and how to deploy. Required sections:

- **What applies to every deploy.** Database migrations, changed settings, new
  environment keys, anything that changes federation behaviour.
- **Deploying from the container image.** Which tag to pull, and any change to
  `compose.yaml` or the image contents.
- **Deploying on bare metal.** Anything a container operator gets for free but
  a bare-metal operator must do by hand. A system dependency added to
  `docker/Dockerfile` is the usual case: containers get it by pulling, everyone
  else has to install it, and until they do the feature silently does nothing.

Do not list the changes themselves. That half is generated automatically from
the merged pull requests and appended below your text.

Check these before writing the body:

```sh
git diff --stat origin/main..origin/develop -- migrations/   # migrations?
git diff --stat origin/main..origin/develop -- assets/       # asset rebuild?
git diff origin/main..origin/develop -- .env.example         # new settings?
git diff --name-only origin/main..origin/develop -- docker/  # image changes?
```

### 3. Merge it

`main` is protected: six required checks, and merging is the only way in. If the
checks are red the release does not happen, which is the point.

Merging is a human action. Agents do not merge and do not open the release pull
request without being asked.

### 4. Everything else is automatic

On merge, the `Build and publish fork Docker image` workflow:

1. Builds and pushes `:main`, `:latest` and `:sha-<commit>`.
2. Reads `mbin_version`, and stops if a tag for it already exists. **A merge
   that does not bump the version is not a release**, which is what makes
   ordinary merges to `main` safe.
3. Creates and pushes the annotated tag `v<version>`.
4. Publishes the GitHub Release: your pull request body, then generated notes
   bounded to the previous fork tag.
5. Adds the version tag to the image digest step 1 already built, rather than
   rebuilding, so the release tag and `:latest` are provably the same bits.

Verify:

```sh
gh run list --workflow=build-and-publish-fork-image.yaml --branch main --limit 1
gh release list --limit 1
docker pull ghcr.io/paisans-software/mbin:<version with + as ->
```

## If something goes wrong

The tag and the release are created near the end, so a failure usually means no
tag exists and the fix is to correct the problem and re-run the job.

If the tag was created but the release or the image retag failed, delete the tag
and re-run, because step 2 treats an existing tag as "already released":

```sh
git push --delete origin v<version>
gh release delete v<version> --yes    # if it was created
```

Never delete a tag that other people may already have deployed. If the release
is public and wrong, cut a new patch release instead.

## What is not automated, on purpose

- Opening the release pull request.
- Merging it.
- Deleting a published release.

## Reference

`.github/workflows/build-and-publish-fork-image.yaml` is the implementation.
`docs/02-admin/01-installation/02-docker.md` is what operators read.
