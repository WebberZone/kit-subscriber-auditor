---
title: Releasing
description: Build and publish a versioned Kit Subscriber Audit release.
permalink: /docs/releasing/
---

## Build a distribution ZIP

The release package is built from a clean Git tree and contains only the runnable application, migrations, public assets, configuration example, README, and license. It excludes the Jekyll site, CI files, tests, Git metadata, local storage, and development files through `.gitattributes`.

```sh
git status
git tag -a v1.0.0 -m "Release 1.0.0"
./build-zip.sh 1.0.0
```

The result is `build/kit-subscriber-auditor-1.0.0.zip`. The script validates the archive before reporting success. It will refuse to build with uncommitted or untracked non-ignored files.

## Publish on GitHub

Push the commit and tag, then create a GitHub release for the tag. The release workflow builds the ZIP on GitHub and uploads it as a release asset.

```sh
git push origin main --follow-tags
gh release create v1.0.0 --generate-notes
```

The companion public `kit-subscriber-auditor-gh-pages` repository publishes the mirrored documentation at `https://ajay.social/kit-subscriber-auditor-gh-pages/`. The private application repository keeps the canonical source alongside the code; update the public docs repository when documentation changes.
