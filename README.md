# Temporary incident-cleanup tooling

This isolated branch contains no application code. Do not merge this branch into main.

verified_branch_cleanup.py.gz is gzip-compressed Python authored for the explicitly authorized repository cleanup on 2026-10-27. The decompressed UTF-8 source is 28671 bytes; SHA-256: 92e097acc094d74cde7b5e0b6fbcd294e1a54f2c06a173d7576156ace68c4f46.

The runner reads Git blobs in a bare repository. It does not check out or execute application code, install dependencies, or run project hooks. It removes verified malware, records ordinary cleanup commits, merges only when the resulting files are byte-identical to the default branch, and deletes only fully merged non-environment branches with no open PR reference, with an exact-SHA lease. Functional differences, ambiguous findings, conflicts, inaccessible data and failures are reported rather than silently discarded.

This is a known-indicator incident-response tool, not a certification that unknown malware is absent. Existing Git history, tags, LFS objects, submodules, developer devices and servers are not certified. GitHub policy restrictions are not bypassed. It uses only each caller repository's ephemeral GITHUB_TOKEN.
