---
name: Victory Yudh workflow port
description: Replit webview port selection when running the PHP tournament app beside the existing API service.
---

For Victory Yudh, keep the PHP preview workflow on port 8099; port 8080 is already occupied by the existing API server workflow.

**Why:** Starting the PHP development server on 8080 failed with “Address already in use”; port 8099 is supported and served the PHP app successfully.

**How to apply:** When reconfiguring `Victory Yudh PHP Server`, set its `waitForPort` to 8099 and leave the existing API workflow on its configured port.
