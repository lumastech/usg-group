---
paths:
  - 'resources/js/components/unity/**'
---

# Unity

## Teleported components must be wrapped in ClientOnly
Inertia server-side renders in Vite dev mode, and `<Teleport>` has no server equivalent. The server emits a comment node where the client expects a div, hydration mismatches, and Vue aborts hydration BEFORE binding any event handlers — the whole page renders perfectly and responds to nothing. It is easy to misread as "the button is broken".

Anything that teleports (Modal, Toast, and any future popover/drawer) must sit inside `resources/js/components/unity/ClientOnly.vue`, which renders its slot only after `onMounted`.

Vite also caches the old module: after fixing a hydration issue, re-navigate and confirm the console has zero "Hydration node mismatch" warnings before concluding the fix failed.
