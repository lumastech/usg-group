import { createInertiaApp } from '@inertiajs/vue3';
import { initializeTheme } from '@/composables/useAppearance';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import AccountLayout from '@/layouts/unity/AccountLayout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            // The committee and member portals bring their own shell
            // (AdminLayout / MemberLayout), so they must not be wrapped again.
            case name.startsWith('app/'):
            case name.startsWith('my/'):
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            // Account screens keep the portal shell the user already knows,
            // so /settings/profile is reachable from the sidebar and the
            // member portal rather than stranded in the starter kit's shell.
            case name.startsWith('settings/'):
                return AccountLayout;
            default:
                return AppLayout;
        }
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();
