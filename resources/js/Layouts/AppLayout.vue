<script setup>
import { Link, router } from '@inertiajs/vue3';

defineProps({
    title: { type: String, default: 'PromptEdit Studio' },
});

function formatCredits(nano) {
    return `$${(Number(nano) / 1_000_000_000).toFixed(2)}`;
}
</script>

<template>
    <div class="min-h-screen bg-slate-950">
        <header class="border-b border-slate-800 bg-slate-900/70 backdrop-blur">
            <div class="mx-auto flex max-w-6xl items-center justify-between gap-4 px-6 py-4">
                <Link :href="'/generate'" class="flex items-center gap-2 text-lg font-semibold tracking-tight">
                    <span class="grid size-8 place-items-center rounded-lg bg-indigo-500 text-sm font-bold">P</span>
                    PromptEdit Studio
                </Link>

                <nav v-if="$page.props.auth.user" class="flex items-center gap-4 text-sm">
                    <Link :href="'/generate'" class="text-slate-300 hover:text-white">Generate</Link>
                    <Link :href="'/generations'" class="text-slate-300 hover:text-white">History</Link>
                    <Link
                        v-if="$page.props.auth.user.is_admin"
                        :href="'/admin/report'"
                        class="text-slate-300 hover:text-white"
                    >Report</Link>
                    <span class="rounded-full bg-slate-800 px-3 py-1 font-mono text-xs text-emerald-300">
                        {{ formatCredits($page.props.auth.user.balance_nano) }}
                    </span>
                    <button
                        type="button"
                        class="rounded-lg bg-emerald-600 px-3 py-1.5 font-medium text-white hover:bg-emerald-500"
                        @click="router.post('/credits/purchase')"
                    >
                        Buy credits
                    </button>
                    <button
                        type="button"
                        class="text-slate-400 hover:text-white"
                        @click="router.post('/logout')"
                    >
                        Log out
                    </button>
                </nav>
                <nav v-else class="flex items-center gap-4 text-sm">
                    <Link :href="'/login'" class="text-slate-300 hover:text-white">Log in</Link>
                    <Link :href="'/register'" class="rounded-lg bg-indigo-600 px-3 py-1.5 hover:bg-indigo-500">Register</Link>
                </nav>
            </div>
        </header>

        <main class="mx-auto max-w-6xl px-6 py-10">
            <div
                v-if="$page.props.flash?.success"
                class="mb-6 rounded-lg border border-emerald-800 bg-emerald-950/60 px-4 py-3 text-sm text-emerald-200"
            >
                {{ $page.props.flash.success }}
            </div>

            <div
                v-if="$page.props.flash?.error"
                class="mb-6 rounded-lg border border-rose-800 bg-rose-950/60 px-4 py-3 text-sm text-rose-200"
            >
                {{ $page.props.flash.error }}
            </div>

            <h1 class="mb-6 text-2xl font-semibold tracking-tight">{{ title }}</h1>
            <slot />
        </main>
    </div>
</template>
