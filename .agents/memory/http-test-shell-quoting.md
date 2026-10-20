---
name: HTTP smoke test shell quoting
description: Avoiding shell/PHP quoting bugs in HTTP smoke test database assertions.
---

In shell scripts that pass PHP source through a single-quoted `php -r '…'` argument, bind SQL values with placeholders rather than writing single-quoted SQL literals inside the snippet.

**Why:** The shell closes its quoted argument at embedded single quotes, which can strip string quotes from SQL and make a value appear as an unknown column. The test can then fail even when the application behavior is correct.

**How to apply:** For SQL in PHP CLI snippets in shell tests, use prepared-statement placeholders and pass values through `execute([...])`; run the complete HTTP smoke suite after changing these snippets.
