---
paths:
  - 'database/seeders/**'
---

# Seeders

## Flush the permission cache before and after seeding roles
`Permission::findOrCreate()` / `Role::findOrCreate()` read through spatie's PermissionRegistrar cache. With a stale cache they cannot see rows that already exist and attempt a duplicate insert (1062), and permissions created earlier in the same run are invisible to `syncPermissions()`. RoleSeeder calls `forgetCachedPermissions()` at the start, again after creating permissions, and once at the end.

Also note MySQL compares strings case-insensitively, so `findOrCreate('chairperson')` happily returns a legacy row still named 'Chairperson'. RoleSeeder force-sets the enum's exact casing and prunes roles no longer in MemberRole, which is what keeps a renamed office from lingering with stale grants.
