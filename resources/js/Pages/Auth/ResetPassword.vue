<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ArrowLeft, Eye, EyeOff, LockKeyhole, Mail } from '@lucide/vue';
import { ref } from 'vue';
import AuthShell from '../../Components/AuthShell.vue';

const props = defineProps<{ token: string; email: string }>();
const form = useForm({ token: props.token, email: props.email, password: '', password_confirmation: '' });
const showPassword = ref(false);

function submit(): void {
    form.post('/reset-password', { onFinish: () => form.reset('password', 'password_confirmation') });
}
</script>

<template>
    <Head title="Choose a new password" />
    <AuthShell title="Choose a new password" description="Use at least 12 characters, including letters and numbers." eyebrow="SECURE ACCOUNT SETUP">
        <form class="auth-form" @submit.prevent="submit">
            <div class="auth-field">
                <label for="email">Work email</label>
                <div class="auth-input-wrap">
                    <Mail class="auth-input-icon" :size="17" aria-hidden="true" />
                    <input id="email" v-model="form.email" class="auth-input" type="email" autocomplete="username" required>
                </div>
                <small v-if="form.errors.email" class="form-error" role="alert">{{ form.errors.email }}</small>
            </div>
            <div class="auth-field">
                <label for="password">New password</label>
                <div class="auth-input-wrap">
                    <LockKeyhole class="auth-input-icon" :size="17" aria-hidden="true" />
                    <input id="password" v-model="form.password" class="auth-input auth-password-input" :type="showPassword ? 'text' : 'password'" autocomplete="new-password" required>
                    <button class="auth-password-toggle" type="button" :aria-pressed="showPassword" :aria-label="showPassword ? 'Hide password' : 'Show password'" @click="showPassword = !showPassword">
                        <EyeOff v-if="showPassword" :size="17" aria-hidden="true" />
                        <Eye v-else :size="17" aria-hidden="true" />
                    </button>
                </div>
                <small v-if="form.errors.password" class="form-error" role="alert">{{ form.errors.password }}</small>
            </div>
            <div class="auth-field">
                <label for="password_confirmation">Confirm new password</label>
                <div class="auth-input-wrap">
                    <LockKeyhole class="auth-input-icon" :size="17" aria-hidden="true" />
                    <input id="password_confirmation" v-model="form.password_confirmation" class="auth-input" type="password" autocomplete="new-password" required>
                </div>
            </div>
            <button class="auth-submit" type="submit" :disabled="form.processing">
                <span>{{ form.processing ? 'Updating…' : 'Reset password' }}</span>
                <LockKeyhole v-if="!form.processing" :size="17" aria-hidden="true" />
            </button>
        </form>
        <Link href="/login" class="auth-back-link"><ArrowLeft :size="15" aria-hidden="true" /> Back to sign in</Link>
    </AuthShell>
</template>
