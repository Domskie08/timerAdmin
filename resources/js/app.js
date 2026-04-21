import '../css/app.css';
import { createInertiaApp } from '@inertiajs/svelte';
import { mount } from 'svelte';

const pages = import.meta.glob('./pages/**/*.svelte', { eager: true });

createInertiaApp({
    resolve: (name) => pages[`./pages/${name}.svelte`],
    setup({ el, App, props }) {
        mount(App, { target: el, props });
    },
    progress: {
        color: '#38d6ff',
    },
});
