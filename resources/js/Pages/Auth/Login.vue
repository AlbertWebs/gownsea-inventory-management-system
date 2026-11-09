<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowRight, Eye, EyeOff, LockKeyhole, Mail } from '@lucide/vue';
import { ref } from 'vue';
import AuthShell from '../../Components/AuthShell.vue';

defineProps<{ status?: string | null }>();

const form = useForm({ email: '', password: '', remember: false });
const showPassword = ref(false);

function submit(): void {
    form.post('/login', { onFinish: () => form.reset('password') });
}
</script>

<template>
    <Head title="Sign in" />
    <AuthShell title="Welcome back" description="Sign in to manage your GOWNSEA operations.">
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
            <div class="auth-field">
                <label for="password">Password</label>
                <div class="auth-input-wrap">
                    <LockKeyhole class="auth-input-icon" :size="17" aria-hidden="true" />
                    <input id="password" v-model="form.password" class="auth-input auth-password-input" :type="showPassword ? 'text' : 'password'" autocomplete="current-password" required>
                    <button class="auth-password-toggle" type="button" :aria-pressed="showPassword" :aria-label="showPassword ? 'Hide password' : 'Show password'" @click="showPassword = !showPassword">
                        <EyeOff v-if="showPassword" :size="17" aria-hidden="true" />
                        <Eye v-else :size="17" aria-hidden="true" />
                    </button>
                </div>
                <small v-if="form.errors.password" class="form-error" role="alert">{{ form.errors.password }}</small>
            </div>
            <div class="auth-options">
                <label class="auth-remember"><input v-model="form.remember" type="checkbox"> <span>Keep me signed in</span></label>
                <Link href="/forgot-password" class="auth-link">Forgot password?</Link>
            </div>
            <button class="auth-submit" type="submit" :disabled="form.processing">
                <span>{{ form.processing ? 'Signing in…' : 'Sign in' }}</span>
                <ArrowRight v-if="!form.processing" :size="17" aria-hidden="true" />
            </button>
            <p class="auth-help">Staff access is managed by an authorized GOWNSEA administrator. Contact your manager if you need an account.</p>
        </form>
        <a class="auth-back-link" href="https://gownsea.com/">Back to GOWNSEA website</a>
    </AuthShell>
</template>
