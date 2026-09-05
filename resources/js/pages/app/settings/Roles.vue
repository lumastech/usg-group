<script setup lang="ts">
/**
 * Roles and what each one may do.
 *
 * A role is only a bundle: every guard in the portal asks for a permission, so
 * re-scoping an office here is the whole of changing what its holders may do. The
 * group's own offices may be re-scoped but not renamed or deleted — code elsewhere
 * grants them by name when a committee term is recorded — while roles added on this
 * screen belong to the administrator entirely.
 */
import { useForm } from '@inertiajs/vue3';
import { RotateCcw, ShieldCheck, Trash2, TriangleAlert } from '@lucide/vue';
import { computed, ref } from 'vue';

import {
    AppButton,
    AppCard,
    CheckboxInput,
    ConfirmDialog,
    EmptyState,
    FormField,
    Modal,
    TextInput,
} from '@/components/unity';
import AdminLayout from '@/layouts/unity/AdminLayout.vue';
import type { ManagedRole, PermissionGroup } from '@/types/settings';

const props = defineProps<{
    roles: ManagedRole[];
    permissionGroups: PermissionGroup[];
}>();

const editing = ref<ManagedRole | null>(null);
const editOpen = ref(false);
const createOpen = ref(false);
const deleting = ref<ManagedRole | null>(null);
const deleteOpen = ref(false);
const resetting = ref<ManagedRole | null>(null);
const resetOpen = ref(false);

const form = useForm<{
    name: string;
    description: string;
    permissions: string[];
}>({
    name: '',
    description: '',
    permissions: [],
});

const bareForm = useForm({});

const totalPermissions = computed<number>(() =>
    props.permissionGroups.reduce(
        (count, group) => count + group.permissions.length,
        0,
    ),
);

/** Labels for a role's granted permissions, so the row reads in words not slugs. */
const permissionLabels = computed<Record<string, string>>(() =>
    Object.fromEntries(
        props.permissionGroups.flatMap((group) =>
            group.permissions.map((permission) => [
                permission.name,
                permission.label,
            ]),
        ),
    ),
);

/** The administrator's bundle is not listed group by group — it is simply everything. */
function isFixed(role: ManagedRole): boolean {
    return !role.abilities.editPermissions;
}

function summarise(role: ManagedRole): string {
    if (isFixed(role)) {
        return 'Every permission in the portal.';
    }

    if (role.permissions.length === 0) {
        return 'No permissions yet.';
    }

    return role.permissions
        .map((name) => permissionLabels.value[name] ?? name)
        .join(' · ');
}

function startCreate(): void {
    form.clearErrors();
    form.defaults({ name: '', description: '', permissions: [] });
    form.reset();
    createOpen.value = true;
}

function edit(role: ManagedRole): void {
    editing.value = role;
    form.clearErrors();
    form.defaults({
        name: role.name,
        description: role.description ?? '',
        permissions: [...role.permissions],
    });
    form.reset();
    editOpen.value = true;
}

function groupState(group: PermissionGroup): 'all' | 'some' | 'none' {
    const held = group.permissions.filter((permission) =>
        form.permissions.includes(permission.name),
    ).length;

    if (held === 0) {
        return 'none';
    }

    return held === group.permissions.length ? 'all' : 'some';
}

/** One click for a whole section — the common case is "everything about loans". */
function toggleGroup(group: PermissionGroup): void {
    const names = group.permissions.map((permission) => permission.name);

    form.permissions =
        groupState(group) === 'all'
            ? form.permissions.filter((name) => !names.includes(name))
            : [...new Set([...form.permissions, ...names])];
}

function togglePermission(name: string, granted: boolean): void {
    form.permissions = granted
        ? [...new Set([...form.permissions, name])]
        : form.permissions.filter((held) => held !== name);
}

function submitCreate(): void {
    form.post('/app/settings/roles', {
        preserveScroll: true,
        onSuccess: () => {
            createOpen.value = false;
        },
    });
}

function submitEdit(): void {
    if (!editing.value) {
        return;
    }

    form.put(`/app/settings/roles/${editing.value.id}`, {
        preserveScroll: true,
        onSuccess: () => {
            editOpen.value = false;
        },
    });
}

function askDelete(role: ManagedRole): void {
    deleting.value = role;
    deleteOpen.value = true;
}

function confirmDelete(): void {
    if (!deleting.value) {
        return;
    }

    bareForm.delete(`/app/settings/roles/${deleting.value.id}`, {
        preserveScroll: true,
        onFinish: () => {
            deleteOpen.value = false;
        },
    });
}

function askReset(role: ManagedRole): void {
    resetting.value = role;
    resetOpen.value = true;
}

function confirmReset(): void {
    if (!resetting.value) {
        return;
    }

    bareForm.post(`/app/settings/roles/${resetting.value.id}/reset`, {
        preserveScroll: true,
        onFinish: () => {
            resetOpen.value = false;
        },
    });
}
</script>

<template>
    <AdminLayout
        title="Roles and permissions"
        heading="Roles and permissions"
        description="What each office may do in the portal, and any roles you add of your own."
    >
        <div class="space-y-5">
            <AppCard>
                <div class="flex flex-col gap-3 sm:flex-row sm:items-start">
                    <TriangleAlert
                        class="size-5 shrink-0 text-gold-600"
                        aria-hidden="true"
                    />
                    <div class="space-y-1 text-sm">
                        <p class="font-medium text-foreground">
                            A role is a bundle of permissions, nothing more.
                        </p>
                        <p class="text-muted-foreground">
                            Every action in the portal is guarded by a
                            permission, so what sits in a bundle is exactly what
                            its holders may do. The group's offices —
                            chairperson, treasurer and the rest — may be
                            re-scoped here but never renamed or deleted:
                            recording a committee term grants them by name.
                            Changes take effect the next time the holder loads a
                            page, and every one of them is written to the audit
                            log.
                        </p>
                    </div>
                </div>
            </AppCard>

            <AppCard title="Roles" flush>
                <template #actions>
                    <AppButton size="sm" @click="startCreate">
                        Add a role
                    </AppButton>
                </template>

                <EmptyState
                    v-if="roles.length === 0"
                    :icon="ShieldCheck"
                    title="No roles"
                    description="Seed the group's offices to get started."
                    class="m-5"
                />

                <ul v-else class="divide-y divide-border">
                    <li
                        v-for="role in roles"
                        :key="role.id"
                        class="flex flex-col gap-3 px-5 py-4 sm:flex-row sm:items-start sm:justify-between"
                    >
                        <div class="min-w-0 space-y-1">
                            <div class="flex flex-wrap items-center gap-2">
                                <p class="font-medium text-foreground">
                                    {{ role.label }}
                                </p>
                                <span
                                    class="rounded-md bg-muted px-1.5 py-0.5 font-mono text-xs text-muted-foreground"
                                >
                                    {{ role.name }}
                                </span>
                                <span
                                    v-if="role.is_system"
                                    class="rounded-md bg-brand-50 px-1.5 py-0.5 text-xs text-brand-800"
                                >
                                    Office
                                </span>
                                <span
                                    v-if="role.customised"
                                    class="rounded-md bg-gold-100 px-1.5 py-0.5 text-xs text-gold-800"
                                >
                                    Re-scoped
                                </span>
                            </div>

                            <p
                                v-if="role.description"
                                class="text-sm text-muted-foreground"
                            >
                                {{ role.description }}
                            </p>

                            <p class="text-xs text-muted-foreground">
                                {{
                                    isFixed(role)
                                        ? totalPermissions
                                        : role.permissions.length
                                }}
                                of {{ totalPermissions }} permissions ·
                                {{ role.holders }}
                                {{ role.holders === 1 ? 'holder' : 'holders' }}
                            </p>

                            <p class="text-xs text-muted-foreground">
                                {{ summarise(role) }}
                            </p>
                        </div>

                        <div class="flex shrink-0 items-center gap-2">
                            <AppButton
                                size="sm"
                                variant="secondary"
                                :disabled="!role.abilities.editPermissions"
                                @click="edit(role)"
                            >
                                {{
                                    role.abilities.editPermissions
                                        ? 'Edit'
                                        : 'Fixed'
                                }}
                            </AppButton>
                            <AppButton
                                v-if="role.abilities.reset"
                                size="sm"
                                variant="ghost"
                                :aria-label="`Put ${role.label} back on the constitution's permissions`"
                                @click="askReset(role)"
                            >
                                <RotateCcw class="size-4" />
                            </AppButton>
                            <AppButton
                                v-if="role.abilities.delete"
                                size="sm"
                                variant="ghost"
                                :aria-label="`Delete ${role.label}`"
                                @click="askDelete(role)"
                            >
                                <Trash2 class="size-4" />
                            </AppButton>
                        </div>
                    </li>
                </ul>
            </AppCard>
        </div>

        <Modal
            v-model:open="createOpen"
            title="Add a role"
            description="A bundle you define. Give it the permissions its holders need and nothing more."
            size="xl"
        >
            <form
                id="create-role-form"
                class="space-y-4"
                @submit.prevent="submitCreate"
            >
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        label="Name"
                        :error="form.errors.name"
                        hint="Saved as a handle, e.g. “Loans clerk” becomes loans_clerk."
                        required
                    >
                        <TextInput v-model="form.name" />
                    </FormField>

                    <FormField
                        label="What it is for"
                        :error="form.errors.description"
                    >
                        <TextInput v-model="form.description" />
                    </FormField>
                </div>

                <FormField label="Permissions" :error="form.errors.permissions">
                    <div class="space-y-4">
                        <div
                            v-for="group in permissionGroups"
                            :key="group.key"
                            class="rounded-lg border border-border p-3"
                        >
                            <div class="mb-2 flex items-center justify-between">
                                <p
                                    class="text-xs font-semibold tracking-wide text-foreground uppercase"
                                >
                                    {{ group.label }}
                                </p>
                                <button
                                    type="button"
                                    class="text-xs font-medium text-brand-700 underline underline-offset-2"
                                    @click="toggleGroup(group)"
                                >
                                    {{
                                        groupState(group) === 'all'
                                            ? 'Clear'
                                            : 'Select all'
                                    }}
                                </button>
                            </div>
                            <div class="grid gap-2 sm:grid-cols-2">
                                <CheckboxInput
                                    v-for="permission in group.permissions"
                                    :key="permission.name"
                                    :label="permission.label"
                                    :hint="permission.name"
                                    :model-value="
                                        form.permissions.includes(
                                            permission.name,
                                        )
                                    "
                                    @update:model-value="
                                        togglePermission(
                                            permission.name,
                                            $event,
                                        )
                                    "
                                />
                            </div>
                        </div>
                    </div>
                </FormField>
            </form>

            <template #footer>
                <AppButton variant="ghost" @click="createOpen = false">
                    Cancel
                </AppButton>
                <AppButton
                    type="submit"
                    form="create-role-form"
                    :loading="form.processing"
                >
                    Add role
                </AppButton>
            </template>
        </Modal>

        <Modal
            v-model:open="editOpen"
            :title="editing ? editing.label : 'Role'"
            size="xl"
        >
            <form
                id="edit-role-form"
                class="space-y-4"
                @submit.prevent="submitEdit"
            >
                <div class="grid gap-4 sm:grid-cols-2">
                    <FormField
                        label="Name"
                        :error="form.errors.name"
                        :hint="
                            editing?.abilities.rename
                                ? 'Saved as a handle, e.g. “Loans clerk” becomes loans_clerk.'
                                : 'An office keeps its name: committee terms grant it by name.'
                        "
                    >
                        <TextInput
                            v-model="form.name"
                            :disabled="!editing?.abilities.rename"
                        />
                    </FormField>

                    <FormField
                        label="What it is for"
                        :error="form.errors.description"
                    >
                        <TextInput v-model="form.description" />
                    </FormField>
                </div>

                <div
                    v-if="editing?.is_system"
                    class="rounded-lg bg-muted px-3 py-2 text-xs text-muted-foreground"
                >
                    Changing an office's permissions makes it the group's own:
                    reseeding will no longer put it back on the constitution's
                    bundle, and the roles list offers a reset for when you want
                    that.
                </div>

                <FormField label="Permissions" :error="form.errors.permissions">
                    <div class="space-y-4">
                        <div
                            v-for="group in permissionGroups"
                            :key="group.key"
                            class="rounded-lg border border-border p-3"
                        >
                            <div class="mb-2 flex items-center justify-between">
                                <p
                                    class="text-xs font-semibold tracking-wide text-foreground uppercase"
                                >
                                    {{ group.label }}
                                </p>
                                <button
                                    type="button"
                                    class="text-xs font-medium text-brand-700 underline underline-offset-2"
                                    @click="toggleGroup(group)"
                                >
                                    {{
                                        groupState(group) === 'all'
                                            ? 'Clear'
                                            : 'Select all'
                                    }}
                                </button>
                            </div>
                            <div class="grid gap-2 sm:grid-cols-2">
                                <CheckboxInput
                                    v-for="permission in group.permissions"
                                    :key="permission.name"
                                    :label="permission.label"
                                    :hint="permission.name"
                                    :model-value="
                                        form.permissions.includes(
                                            permission.name,
                                        )
                                    "
                                    @update:model-value="
                                        togglePermission(
                                            permission.name,
                                            $event,
                                        )
                                    "
                                />
                            </div>
                        </div>
                    </div>
                </FormField>
            </form>

            <template #footer>
                <AppButton variant="ghost" @click="editOpen = false">
                    Cancel
                </AppButton>
                <AppButton
                    type="submit"
                    form="edit-role-form"
                    :loading="form.processing"
                >
                    Save changes
                </AppButton>
            </template>
        </Modal>

        <ConfirmDialog
            v-model:open="deleteOpen"
            title="Delete this role?"
            variant="destructive"
            confirm-label="Delete role"
            :message="
                deleting
                    ? `${deleting.label} will be removed, and the ${deleting.holders} ${
                          deleting.holders === 1 ? 'person' : 'people'
                      } holding it will lose every permission it granted.`
                    : ''
            "
            :processing="bareForm.processing"
            @confirm="confirmDelete"
        />

        <ConfirmDialog
            v-model:open="resetOpen"
            :title="
                resetting
                    ? `Reset ${resetting.label} to the constitution?`
                    : 'Reset role'
            "
            confirm-label="Reset permissions"
            :message="
                resetting
                    ? `${resetting.label} goes back to the permissions the group's rules define for the office, and any changes made here are dropped.`
                    : ''
            "
            :processing="bareForm.processing"
            @confirm="confirmReset"
        />
    </AdminLayout>
</template>
