# Strict artwork matching rollout

## Behavior and operator workflow

The Google Drive service account and its read-only scope are unchanged. Only the configured Group A and Group B roots, their direct series folders, and files directly within those series folders are indexed. Nested folders and shortcuts are never followed. MOCKUP(S), PREVIEW(S), and ARCHIVE files are excluded. Empty and duplicate series folders are retained as evidence so they cannot trigger a fallback search elsewhere.

The Shopify SKU format is `YEAR-DESIGN-SERIES-NUMBER`. Every current or future design follows the same filename rule: `[YEAR-]SERIES-DESIGN[-COLOR][-SIDE].ext`. DESIGN is either the exact SKU design code or the exact full Shopify design title after removing the standard Lucky Bags/year prefix and optional ACL Cornhole Bags suffix. Case and separator differences are normalized. The optional year must match the SKU. PSE/PSN-ELT are established series aliases; there are no image-specific aliases, guessed abbreviations or substring expansion.

For example, Bubble Fusion accepts `CLV-BUB.jpg` or `CLV-BUBBLE-FUSION.jpg`; `CLV-BUBBLES.jpg` needs a standard rename. Earth accepts `CLV-EARTH-BLACK.jpg` as well as its code convention. Friendly series folder names (including Clover, Celtics, Surefire and Pro Snipers) identify scope only; filenames must independently prove series and design.

Explicit Shopify colors/variants must match exactly, including compound labels such as Black Leather. Series-only options such as Clover leave color unspecified: a unique eligible artwork set can be presented for operator approval, but multiple colors or files require review. A double-sided set must contain exactly one front and one back with the same color. No filename match grants automatic approval.


## Follow-up validation and compact interface

The reported failed rematch was traced to the missing artwork_review column, rather than the renamed PSE-DUC-RED.jpg filename. A read-only check confirmed that the file exists directly in the approved PSE folder and matches the Red variant. The user authorized adding the nullable column only; no rematch action was run against saved orders. Schema preflight now explains the exact missing migration before integration work.

The top rematch action is a compact row. Missing-artwork instructions use a short status, folder link, small filename/copy rows and an action row; verbose diagnostics are collapsed under Naming guidance & details. Legacy records say Check production artwork until a fresh lookup, rather than falsely claiming the series folder does not exist. Correct previews and locked batches remain visible.

The final generic rule was validated against real Drive listings and isolated fixtures. Nonstandard names without series tokens, shortened design synonyms, wrong colors, duplicates and side typos still need review or a standard rename. No per-design mapping table is used.
