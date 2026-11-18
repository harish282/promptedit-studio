<script setup>
import { useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

function submit() {
    form.post('/login', { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Log in">
        <div class="mx-auto max-w-md rounded-xl border border-slate-800 bg-slate-900 p-8">
            <form class="space-y-5" @submit.prevent="submit">
                <div>
                    <label class="mb-1 block text-sm text-slate-300" for="email">Email address</label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        required
                        autocomplete="email"
                        class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 focus:border-indigo-500 focus:outline-none"
                    >
                    <p v-if="form.errors.email" class="mt-1 text-sm text-rose-400">{{ form.errors.email }}</p>
                </div>

                <div>
                    <label class="mb-1 block text-sm text-slate-300" for="password">Password</label>
                    <input
                        id="password"
                        v-model="form.password"
                        type="password"
                        required
                        autocomplete="current-password"
                        class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 focus:border-indigo-500 focus:outline-none"
                    >
                    <p v-if="form.errors.password" class="mt-1 text-sm text-rose-400">{{ form.errors.password }}</p>
                </div>

                <label class="flex items-center gap-2 text-sm text-slate-400">
                    <input v-model="form.remember" type="checkbox" class="accent-indigo-500">
                    Remember me
                </label>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full rounded-lg bg-indigo-600 py-2.5 font-medium hover:bg-indigo-500 disabled:opacity-50"
                >
                    Log in
                </button>

                <p class="text-center text-sm text-slate-400">
                    No account?
                    <a href="/register" class="text-indigo-300 hover:underline">Create one</a>
                </p>
            </form>
        </div>
    </AppLayout>
</template>
