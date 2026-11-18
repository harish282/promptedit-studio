<script setup>
import AppLayout from '../../Layouts/AppLayout.vue';

defineProps({
    rows: { type: Array, required: true },
    daily: { type: Array, required: true },
    providerUsage: { type: Object, default: null },
    usageError: { type: String, default: null },
    startDate: { type: String, required: true },
    endDate: { type: String, required: true },
});

function providerCostFor(date) {
    const entry = providerUsage?.data?.find((item) => item.date === date);

    return entry ? Number(entry.cost_usd).toFixed(9) : '—';
}
</script>

<template>
    <AppLayout title="Operations report">
        <p class="mb-6 text-sm text-slate-400">UTC {{ startDate }} to {{ endDate }} · provider costs reconciled against Pixelforge usage</p>

        <p v-if="usageError" class="mb-6 rounded-lg bg-rose-950/50 px-4 py-3 text-sm text-rose-200">{{ usageError }}</p>

        <section class="mb-10 overflow-x-auto rounded-xl border border-slate-800 bg-slate-900">
            <h2 class="border-b border-slate-800 px-5 py-4 font-semibold">Daily totals</h2>
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-5 py-3">Date</th>
                        <th class="px-5 py-3">Images</th>
                        <th class="px-5 py-3">Charged to users</th>
                        <th class="px-5 py-3">Provider cost</th>
                        <th class="px-5 py-3">Pixelforge reports</th>
                        <th class="px-5 py-3">Profit</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="day in daily" :key="day.date" class="border-t border-slate-800">
                        <td class="px-5 py-3 font-mono">{{ day.date }}</td>
                        <td class="px-5 py-3">{{ day.generations }}</td>
                        <td class="px-5 py-3 font-mono text-slate-200">${{ Number(day.charge_usd).toFixed(6) }}</td>
                        <td class="px-5 py-3 font-mono">{{ Number(day.provider_cost_usd).toFixed(9) }}</td>
                        <td class="px-5 py-3 font-mono text-slate-400">{{ providerCostFor(day.date) }}</td>
                        <td class="px-5 py-3 font-mono text-emerald-300">${{ Number(day.profit_usd).toFixed(6) }}</td>
                    </tr>
                    <tr v-if="! daily.length">
                        <td colspan="6" class="px-5 py-6 text-center text-slate-500">No completed generations in this period.</td>
                    </tr>
                </tbody>
            </table>
        </section>

        <section class="overflow-x-auto rounded-xl border border-slate-800 bg-slate-900">
            <h2 class="border-b border-slate-800 px-5 py-4 font-semibold">By day and user</h2>
            <table class="w-full text-left text-sm">
                <thead class="text-xs uppercase tracking-wide text-slate-500">
                    <tr>
                        <th class="px-5 py-3">Date</th>
                        <th class="px-5 py-3">User</th>
                        <th class="px-5 py-3">Images</th>
                        <th class="px-5 py-3">Charged</th>
                        <th class="px-5 py-3">Provider cost</th>
                        <th class="px-5 py-3">Profit</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="(row, index) in rows" :key="`${row.date}-${row.user_id}-${index}`" class="border-t border-slate-800">
                        <td class="px-5 py-3 font-mono">{{ row.date }}</td>
                        <td class="px-5 py-3">
                            <span class="text-slate-200">{{ row.user_name }}</span>
                            <span class="ml-2 text-xs text-slate-500">{{ row.user_email }}</span>
                        </td>
                        <td class="px-5 py-3">{{ row.generations }}</td>
                        <td class="px-5 py-3 font-mono">${{ Number(row.charge_usd).toFixed(6) }}</td>
                        <td class="px-5 py-3 font-mono">{{ Number(row.provider_cost_usd).toFixed(9) }}</td>
                        <td class="px-5 py-3 font-mono text-emerald-300">${{ Number(row.profit_usd).toFixed(6) }}</td>
                    </tr>
                    <tr v-if="! rows.length">
                        <td colspan="6" class="px-5 py-6 text-center text-slate-500">No user spend in this period.</td>
                    </tr>
                </tbody>
            </table>
        </section>
    </AppLayout>
</template>
