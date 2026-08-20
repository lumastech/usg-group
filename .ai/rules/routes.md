---
paths:
  - 'resources/js/routes/**'
---

# Routes

## Always regenerate Wayfinder with --with-form
`vite.config.ts` configures `wayfinder({ formVariants: true })`, so every generated route helper carries a `.form()` variant. The auth and settings pages bind `<Form v-bind="store.form()">` against it.

Running bare `php artisan wayfinder:generate` regenerates WITHOUT form variants and silently strips `.form()` from every helper. Nothing fails loudly: the build still succeeds, tests still pass (they post to routes directly, not through the rendered form), but `v-bind` receives undefined and the login, register, password-reset, 2FA and profile forms stop submitting.

Always run `php artisan wayfinder:generate --with-form`. `npx vue-tsc --noEmit` is the detector — a burst of "Property 'form' does not exist" errors across auth/settings pages means the generation was run wrong.
