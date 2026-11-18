<script setup>
import { useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

function submit() {
    form.post('/register', { preserveScroll: true });
}
</script>

<template>
    <AppLayout title="Create your account">
        <div class="mx-auto max-w-md rounded-xl border border-slate-800 bg-slate-900 p-8">
            <form class="space-y-5" @submit.prevent="submit">
                <div>
                    <label class="mb-1 block text-sm text-slate-300" for="name">Name</label>
                    <input
                        id="name"
                        v-model="form.name"
                        type="text"
                        required
                        class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 focus:border-indigo-500 focus:outline-none"
                    >
                    <p v-if="form.errors.name" class="mt-1 text-sm text-rose-400">{{ form.errors.name }}</p>
                </div>

                <div>
                    <label class="mb-1 block text-sm text-slate-300" for="email">Email address</label>
                    <input
                        id="email"
                        v-model="form.email"
                        type="email"
                        required
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
                        class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 focus:border-indigo-500 focus:outline-none"
                    >
                    <p v-if="form.errors.password" class="mt-1 text-sm text-rose-400">{{ form.errors.password }}</p>
                </div>

                <div>
                    <label class="mb-1 block text-sm text-slate-300" for="password_confirmation">Confirm password</label>
                    <input
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        type="password"
                        required
                        class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2 focus:border-indigo-500 focus:outline-none"
                    >
                </div>

                <button
                    type="submit"
                    :disabled="form.processing"
                    class="w-full rounded-lg bg-indigo-600 py-2.5 font-medium hover:bg-indigo-500 disabled:opacity-50"
                >
                    Create account
                </button>
            </form>
        </div>
    </AppLayout>
</template>
