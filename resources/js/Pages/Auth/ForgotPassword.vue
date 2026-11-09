<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Mail } from '@lucide/vue';
import AuthShell from '../../Components/AuthShell.vue';

defineProps<{ status?: string | null }>();
const form = useForm({ email: '' });

function submit(): void {
    form.post('/forgot-password');
}
</script>

<template>
    <Head title="Reset password" />
    <AuthShell title="Reset your password" description="Enter your work email and we’ll send a secure reset link if the account is registered." eyebrow="ACCOUNT RECOVERY">
        <p v-if="status" class="auth-status" role="status">{{ status }}</p>
        <form class="auth-form" @submit.prevent="submit">
            <div class="auth-field">
                <label for="email">Work email</label>
                <div class="auth-input-wrap">
                    <Mail class="auth-input-icon" :size="17" aria-hidden="true" />
                    <input id="email" v-model="form.email" class="auth-input" type="email" autocomplete="username" required autofocus>
                </div>
                <small v-if="form.errors.email" class="form-error" role="alert">{{ form.errors.email }}</small>
            </div>
            <button class="auth-submit" type="submit" :disabled="form.processing">
                <span>{{ form.processing ? 'Sending…' : 'Send reset link' }}</span>
                <Mail v-if="!form.processing" :size="17" aria-hidden="true" />
            </button>
        </form>
        <Link href="/login" class="auth-back-link"><ArrowLeft :size="15" aria-hidden="true" /> Back to sign in</Link>
    </AuthShell>
</template>
