---
title: Playground
description: Paste an OpenSearch DSL query and get the line you can log, the shape you can read, and the fingerprint you can group by. Runs the real PHP library in your browser.
template: playground.html
# Both hidden for the width: two panes side by side want the whole grid, which
# is 61rem — the same 1200-odd pixels the page had when it stood alone. The
# strip above and the header are how you leave.
hide:
  - navigation
  - toc
---

# Playground

Paste a DSL query below: you get the line you can log, the shape you can read,
and the fingerprint you can group by.

This page runs the real PHP library, compiled to WebAssembly. Your query never
leaves this browser — there is no server to send it to, and nothing here is
loaded from anywhere but this site. [How it manages
that](explanation/playground.md) is worth a read on its own.
