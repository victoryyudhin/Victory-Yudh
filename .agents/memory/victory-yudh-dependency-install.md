---
name: Victory Yudh dependency install
description: Keeping the PHP app runnable when the workspace-wide pnpm install is blocked.
---

Install Victory Yudh's own workspace package with `pnpm install --filter @workspace/victory-yudh --frozen-lockfile` when it only needs Tailwind for its CSS build. A workspace-wide install may be blocked by the package firewall on an unrelated transitive dependency (`proxy-addr`).

**Why:** The PHP preview depends on the local Tailwind CLI, not on Node services elsewhere in the pnpm workspace. Restricting installation lets the app run without altering its existing PHP/MySQL architecture.

**How to apply:** Prefer the filtered install for Victory Yudh startup. Investigate and resolve a firewall-blocked dependency separately before attempting a full workspace install.
