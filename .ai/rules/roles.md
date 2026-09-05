---
paths:
  - 'app/Domain/Roles/**'
---

# Roles

## Roles are seeded bundles; RoleManager is the only thing that edits them
Two kinds of row live in `roles`. The constitution's offices come from MemberRole and are seeded; roles the administrator adds on /app/settings/roles carry `is_system = false`. Only App\Domain\Roles\RoleManager writes to any of it.

`is_system` defaults to TRUE, in the migration and in App\Models\Role::$attributes. That is deliberate: RoleSeeder::pruneRetiredRoles deletes system roles it does not recognise, which is what stops a legacy row (MySQL matches names case-insensitively, so `findOrCreate('chairperson')` can return `Chairperson`) lingering with live grants. Only RoleManager writes false, so the administrator's own roles are the one thing the prune spares.

An office may be re-scoped but never renamed or deleted — CommitteeRoleSync and MemberInviter assign by name, so a renamed office silently stops being granted. Re-scoping one sets `permissions_customised_at`, and RoleSeeder then skips its syncPermissions; without that the next deploy would quietly undo the committee's decision. RoleController@reset clears the mark and hands the office back to the seeder.

`roles.manage` may never be granted to a second bundle, and the `admin` bundle may never be trimmed — it is defined as every permission and is the only way back in.
