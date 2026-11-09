<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { KeyRound, ShieldCheck } from '@lucide/vue';
import { ref } from 'vue';
import AuthShell from '../../Components/AuthShell.vue';

defineProps<{ email: string }>();
const form = useForm({ code: '', recovery_code: '' });
const useRecoveryCode = ref(false);

function submit(): void {
    form.post('/two-factor-challenge', { onFinish: () => form.reset() });
}
</script>

<template>
    <Head title="Verify your account" />
    <AuthShell title="Verify it’s you" description="Enter a current code from your authenticator to continue." eyebrow="GOWNSEA ACCOUNT SECURITY">
        <div class="challenge-account"><ShieldCheck :size="17" aria-hidden="true" /><span>{{ email }}</span></div>
        <form class="auth-form" @submit.prevent="submit">
            <div v-if="!useRecoveryCode" class="auth-field">
                <label for="code">Six-digit authenticator code</label>
                <div class="auth-input-wrap"><KeyRound class="auth-input-icon" :size="17" aria-hidden="true" /><input id="code" v-model="form.code" class="auth-input challenge-code" type="text" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="000000" required autofocus></div>
                <small v-if="form.errors.code" class="form-error" role="alert">{{ form.errors.code }}</small>
            </div>
            <div v-else class="auth-field">
                <label for="recovery-code">One-time recovery code</label>
                <div class="auth-input-wrap"><KeyRound class="auth-input-icon" :size="17" aria-hidden="true" /><input id="recovery-code" v-model="form.recovery_code" class="auth-input" type="text" autocomplete="off" autocapitalize="characters" minlength="16" maxlength="64" required autofocus></div>
                <small v-if="form.errors.recovery_code || form.errors.code" class="form-error" role="alert">{{ form.errors.recovery_code ?? form.errors.code }}</small>
            </div>
            <button class="auth-submit" type="submit" :disabled="form.processing"><span>{{ form.processing ? 'Verifying…' : 'Continue securely' }}</span><ShieldCheck v-if="!form.processing" :size="17" aria-hidden="true" /></button>
            <button class="challenge-switch" type="button" @click="useRecoveryCode = !useRecoveryCode; form.reset(); form.clearErrors()">{{ useRecoveryCode ? 'Use an authenticator code instead' : 'Use a recovery code instead' }}</button>
            <p class="auth-help">Recovery codes can only be used once. If you cannot access either method, contact your GOWNSEA administrator.</p>
        </form>
    </AuthShell>
</template>

<style scoped>
.challenge-account { display:flex; align-items:center; gap:8px; margin:0 0 17px; border:1px solid #eceef0; border-radius:7px; background:#fafafa; padding:9px 11px; color:#555d69; font-size:11px; }
.challenge-account svg { color:#c6212a; }
.challenge-code { padding-left:40px !important; font-size:18px !important; font-variant-numeric:tabular-nums; letter-spacing:.3em; }
.challenge-switch { border:0; background:transparent; padding:2px 0; color:#b91d25; cursor:pointer; font-size:11px; font-weight:600; }
.challenge-switch:hover { text-decoration:underline; }
</style>
