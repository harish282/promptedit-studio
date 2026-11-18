<script setup>
import { router } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
    generations: { type: Object, required: true },
    balanceNano: { type: Number, required: true },
    flash: { type: Object, default: () => ({}) },
});

const items = ref(props.generations.data);
const pollError = ref(false);
let pollTimer;

watch(() => props.generations, (value) => {
    items.value = value.data;
});

const hasPending = computed(() => items.value.some((item) => ['queued', 'processing'].includes(item.status)));

async function refreshItem(item) {
    try {
        const response = await fetch(`/generations/${item.id}/refresh`, {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
        });

        if (! response.ok) {
            pollError.value = true;
            return;
        }

        pollError.value = false;
        const updated = await response.json();
        const index = items.value.findIndex((candidate) => candidate.id === updated.id);

        if (index !== -1) {
            const wasPending = ['queued', 'processing'].includes(items.value[index].status);
            items.value[index] = updated;

            if (wasPending && ['succeeded', 'failed'].includes(updated.status)) {
                router.reload({ only: ['auth', 'balanceNano'] });
            }
        }
    } catch {
        pollError.value = true;
    }
}

onMounted(() => {
    pollTimer = setInterval(() => {
        if (hasPending.value) {
            items.value.filter((item) => ['queued', 'processing'].includes(item.status)).forEach(refreshItem);
        }
    }, 10000);
});

onUnmounted(() => clearInterval(pollTimer));

const statusClasses = {
    queued: 'bg-slate-700 text-slate-200',
    processing: 'bg-amber-500/20 text-amber-300',
    succeeded: 'bg-emerald-500/20 text-emerald-300',
    failed: 'bg-rose-500/20 text-rose-300',
};
</script>

<template>
    <AppLayout title="Generation history">
        <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
            <div class="flex flex-wrap items-center gap-4">
                <p class="text-sm text-slate-400">
                    Balance: <span class="font-mono text-emerald-300">${{ (balanceNano / 1_000_000_000).toFixed(2) }}</span>
                </p>
                <p v-if="pollError" class="text-xs text-amber-300">Live updates are temporarily unavailable; retrying…</p>
            </div>
            <a href="/generate" class="rounded-lg bg-indigo-600 px-4 py-2 text-sm font-medium hover:bg-indigo-500">New image</a>
        </div>

        <div v-if="! items.length" class="rounded-xl border border-dashed border-slate-700 p-12 text-center text-slate-400">
            No generations yet. <a href="/generate" class="text-indigo-300 hover:underline">Create your first image.</a>
        </div>

        <div v-else class="space-y-4">
            <article
                v-for="item in items"
                :key="item.id"
                class="flex flex-col gap-5 rounded-xl border border-slate-800 bg-slate-900 p-5 sm:flex-row"
            >
                <div class="w-full shrink-0 sm:w-52">
                    <div v-if="item.status === 'succeeded' && item.image_url" class="overflow-hidden rounded-lg border border-slate-700 bg-slate-950">
                        <img :src="item.image_url" :alt="item.prompt" class="aspect-square w-full object-cover" loading="lazy">
                    </div>
                    <div v-else class="grid aspect-square w-full place-items-center rounded-lg border border-dashed border-slate-700 bg-slate-950 text-xs text-slate-500">
                        {{ item.status === 'failed' ? 'No image' : `${item.status}…` }}
                    </div>
                </div>

                <div class="min-w-0 flex-1">
                    <div class="flex flex-wrap items-center gap-3">
                        <span :class="statusClasses[item.status]" class="rounded-full px-2.5 py-1 text-xs font-medium capitalize">{{ item.status }}</span>
                        <span class="text-xs text-slate-500">{{ item.size }} · {{ item.quality }}</span>
                        <span v-if="item.created_at" class="text-xs text-slate-500">{{ new Date(item.created_at).toLocaleString() }}</span>
                    </div>

                    <p class="mt-3 break-words text-sm text-slate-200">{{ item.prompt }}</p>

                    <p v-if="item.error_message" class="mt-3 rounded-lg bg-rose-950/50 px-3 py-2 text-xs text-rose-200">
                        {{ item.error_message }}
                    </p>

                    <div class="mt-4 grid gap-2 text-sm sm:grid-cols-2">
                        <p class="text-slate-400">
                            Estimated charge:
                            <span class="font-mono text-slate-200">{{ item.estimated_charge_display }}</span>
                        </p>
                        <p class="text-slate-400">
                            Actual charge:
                            <span v-if="item.actual_charge_usd !== null" class="font-mono text-emerald-300">${{ Number(item.actual_charge_usd).toFixed(6) }}</span>
                            <span v-else class="text-slate-500">pending</span>
                        </p>
                    </div>
                </div>
            </article>
        </div>

        <div v-if="generations.links && generations.links.length > 3" class="mt-8 flex flex-wrap gap-2">
            <a
                v-for="(link, index) in generations.links"
                :key="index"
                :href="link.url || '#'"
                class="rounded-lg border border-slate-700 px-3 py-1.5 text-sm"
                :class="link.active ? 'bg-indigo-600 text-white' : 'text-slate-300 hover:bg-slate-800'"
                v-html="link.label"
            />
        </div>
    </AppLayout>
</template>
