<script setup>
import { useForm } from '@inertiajs/vue3';
import { onUnmounted, ref, watch } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';

const props = defineProps({
    initialEstimate: { type: Object, required: true },
    options: { type: Object, required: true },
});

const form = useForm({
    prompt: '',
    size: '1024x1024',
    quality: 'medium',
    reference_images: '',
});

const estimate = ref(props.initialEstimate);
const estimating = ref(false);
const estimateError = ref(false);
let debounceTimer;

function referenceUrls() {
    return form.reference_images
        .split(/[\n,]+/)
        .map((value) => value.trim())
        .filter(Boolean);
}

function formatNano(nano) {
    return `$${(Number(nano) / 1_000_000_000).toFixed(6)}`;
}

async function fetchEstimate() {
    if (!form.prompt.trim()) {
        estimate.value = props.initialEstimate;
        estimateError.value = false;
        return;
    }

    estimating.value = true;

    try {
        const response = await fetch('/generations/estimate', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                Accept: 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content ?? '',
            },
            body: JSON.stringify({
                prompt: form.prompt,
                size: form.size,
                quality: form.quality,
                reference_images: referenceUrls(),
            }),
        });

        if (response.ok) {
            estimate.value = await response.json();
            estimateError.value = false;
        } else {
            estimateError.value = true;
        }
    } catch {
        estimateError.value = true;
    } finally {
        estimating.value = false;
    }
}

watch(
    () => [form.prompt, form.size, form.quality, form.reference_images],
    () => {
        clearTimeout(debounceTimer);
        debounceTimer = setTimeout(fetchEstimate, 250);
    },
);

onUnmounted(() => clearTimeout(debounceTimer));

function submit() {
    form.transform((data) => ({ ...data, reference_images: referenceUrls() })).post('/generations', {
        preserveScroll: true,
    });
}

function usePrompt(prompt) {
    form.prompt = prompt;
}
</script>

<template>
    <AppLayout title="Generate an image">
        <div class="grid gap-8 lg:grid-cols-[1.4fr_1fr]">
            <form class="rounded-xl border border-slate-800 bg-slate-900 p-6" @submit.prevent="submit">
                <div class="space-y-6">
                    <div>
                        <label for="prompt" class="mb-2 block text-sm font-medium text-slate-200">Prompt</label>
                        <textarea
                            id="prompt"
                            v-model="form.prompt"
                            rows="6"
                            maxlength="4000"
                            placeholder="A lighthouse on a cliff at dawn, oil painting"
                            class="w-full rounded-lg border border-slate-700 bg-slate-950 px-4 py-3 leading-relaxed focus:border-indigo-500 focus:outline-none"
                        />
                        <p v-if="form.errors.prompt" class="mt-1 text-sm text-rose-400">{{ form.errors.prompt }}</p>
                        <div class="mt-3 flex flex-wrap gap-2 text-xs">
                            <span class="text-slate-500">Try:</span>
                            <button type="button" class="rounded bg-slate-800 px-2 py-1 text-slate-300 hover:bg-slate-700" @click="usePrompt('[succeed] A serene mountain lake at golden hour')">[succeed]</button>
                            <button type="button" class="rounded bg-slate-800 px-2 py-1 text-slate-300 hover:bg-slate-700" @click="usePrompt('[fail] A storm over the ocean')">[fail]</button>
                            <button type="button" class="rounded bg-slate-800 px-2 py-1 text-slate-300 hover:bg-slate-700" @click="usePrompt('[duplicate] A neon city at night')">[duplicate]</button>
                            <button type="button" class="rounded bg-slate-800 px-2 py-1 text-slate-300 hover:bg-slate-700" @click="usePrompt('[slow] A desert mirage')">[slow]</button>
                        </div>
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <div>
                            <label for="size" class="mb-2 block text-sm font-medium text-slate-200">Size</label>
                            <select id="size" v-model="form.size" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 focus:border-indigo-500 focus:outline-none">
                                <option v-for="size in options.sizes" :key="size" :value="size">{{ size }}</option>
                            </select>
                            <p v-if="form.errors.size" class="mt-1 text-sm text-rose-400">{{ form.errors.size }}</p>
                        </div>
                        <div>
                            <label for="quality" class="mb-2 block text-sm font-medium text-slate-200">Quality</label>
                            <select id="quality" v-model="form.quality" class="w-full rounded-lg border border-slate-700 bg-slate-950 px-3 py-2.5 focus:border-indigo-500 focus:outline-none">
                                <option v-for="quality in options.qualities" :key="quality" :value="quality">{{ quality }}</option>
                            </select>
                            <p v-if="form.errors.quality" class="mt-1 text-sm text-rose-400">{{ form.errors.quality }}</p>
                        </div>
                    </div>

                    <div>
                        <label for="references" class="mb-2 block text-sm font-medium text-slate-200">Reference image URLs <span class="text-slate-500">(optional, one per line)</span></label>
                        <textarea
                            id="references"
                            v-model="form.reference_images"
                            rows="2"
                            placeholder="https://example.com/style.png"
                            class="w-full rounded-lg border border-slate-700 bg-slate-950 px-4 py-3 focus:border-indigo-500 focus:outline-none"
                        />
                        <p v-if="form.errors['reference_images']" class="mt-1 text-sm text-rose-400">{{ form.errors['reference_images'] }}</p>
                    </div>

                    <p v-if="form.errors.balance" class="rounded-lg bg-rose-950/60 px-4 py-3 text-sm text-rose-200">{{ form.errors.balance }}</p>

                    <button
                        type="submit"
                        :disabled="form.processing || estimating || estimateError"
                        class="w-full rounded-lg bg-indigo-600 py-3 font-semibold hover:bg-indigo-500 disabled:opacity-50"
                    >
                        {{ form.processing ? 'Submitting…' : 'Generate image' }}
                    </button>
                </div>
            </form>

            <aside class="h-fit rounded-xl border border-slate-800 bg-slate-900 p-6">
                <div class="mb-4 flex items-center justify-between">
                    <h2 class="font-semibold">Estimated cost</h2>
                    <span v-if="estimating" class="text-xs text-slate-500">Updating…</span>
                </div>

                <p class="text-3xl font-semibold text-emerald-300">
                    {{ formatNano(estimate.charge_nano) }}
                </p>
                <p class="mt-1 text-sm text-slate-400">
                    Provider estimate {{ formatNano(estimate.provider_cost_nano) }} + 25% markup
                </p>
                <p v-if="estimateError" class="mt-2 text-xs text-amber-300">
                    The price could not be refreshed. Try again before generating.
                </p>

                <div class="mt-5 space-y-2 border-t border-slate-800 pt-4 text-sm">
                    <div class="flex justify-between"><span class="text-slate-400">Input text tokens</span><span>{{ estimate.tokens.input_text_tokens.toLocaleString() }}</span></div>
                    <div class="flex justify-between"><span class="text-slate-400">Input image tokens</span><span>{{ estimate.tokens.input_image_tokens.toLocaleString() }}</span></div>
                    <div class="flex justify-between"><span class="text-slate-400">Output tokens</span><span>{{ estimate.tokens.output_tokens.toLocaleString() }}</span></div>
                    <div class="flex justify-between"><span class="text-slate-400">Reasoning tokens</span><span>{{ estimate.tokens.reasoning_tokens.toLocaleString() }}</span></div>
                </div>

                <p class="mt-5 rounded-lg bg-slate-950/70 p-3 text-xs leading-relaxed text-slate-400">
                    This is the maximum you will be charged. The final price depends on token counts known only when the image finishes, and failed generations are free. We reserve the estimate now and refund the difference when it completes.
                </p>
            </aside>
        </div>
    </AppLayout>
</template>
