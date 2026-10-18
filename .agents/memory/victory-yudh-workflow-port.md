---
name: Victory Yudh workflow port
description: Replit webview port selection when running the PHP tournament app beside the existing API service.
---

For Victory Yudh, keep the PHP server and workflow on port 8099. Replit's Networking port map must forward local 8099 to external 80 for root preview traffic.

**Why:** The PHP server bound to `0.0.0.0:8099` and returned HTTP 200 locally, while the development domain returned “Backend Not Configured” without an external port mapping. Port mappings should be managed through Replit's Networking tool; a validated `.replit` replacement did not persist a manual `[[ports]]` block.

**How to apply:** Keep `waitForPort` at 8099 and do not move PHP to 8080 just because Preview is blank. In Networking, map 8099 to external 80; if adding explicit mappings, preserve mappings for every sibling artifact service port.
