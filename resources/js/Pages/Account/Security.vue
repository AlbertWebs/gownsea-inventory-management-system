<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import { Check, Copy, KeyRound, LogOut, ShieldCheck, ShieldPlus } from '@lucide/vue';
import QRCode from 'qrcode';
import { computed, ref, watch } from 'vue';
import AppShell from '../../Components/AppShell.vue';

const props = defineProps<{
    eligible: boolean;
    enabled: boolean;
    pendingSecret: string | null;
    provisioningUri: string | null;
    recoveryCodesRemaining: number;
    recoveryCodes: string[];
    otherSessionCount: number;
    canManageSessions: boolean;
}>();
const startForm = useForm<Record<string, never>>({});
const confirmForm = useForm({ code: '' });
const disableForm = useForm({ password: '', code: '' });
const sessionsForm = useForm<Record<string, never>>({});
const copied = ref(false);
const qrCodeImage = ref('');
const qrCodeError = ref(false);
const hasPendingSetup = computed(() => props.pendingSecret !== null && props.provisioningUri !== null);

watch(() => props.provisioningUri, async (uri) => {
    qrCodeImage.value = '';
    qrCodeError.value = false;

    if (uri === null) {
        return;
    }

    try {
        qrCodeImage.value = await QRCode.toDataURL(uri, {
            width: 224,
            margin: 2,
            errorCorrectionLevel: 'M',
            color: { dark: '#171b24', light: '#ffffff' },
        });
    } catch {
        qrCodeError.value = true;
    }
}, { immediate: true });

function startSetup(): void {
    startForm.post('/account/security/two-factor/start', { preserveScroll: true });
}

function confirmSetup(): void {
    confirmForm.post('/account/security/two-factor/confirm', { preserveScroll: true, onSuccess: () => confirmForm.reset() });
}

function disableTwoFactor(): void {
    disableForm.delete('/account/security/two-factor', { preserveScroll: true, onSuccess: () => disableForm.reset() });
}

function signOutOtherSessions(): void {
    sessionsForm.delete('/account/security/sessions', { preserveScroll: true });
}

async function copyRecoveryCodes(): Promise<void> {
    await navigator.clipboard.writeText(props.recoveryCodes.join('\n'));
    copied.value = true;
}
</script>

<template>
    <Head title="Account security" />
    <AppShell active="settings" title="Account security">
        <div class="security-page">
            <header class="security-heading">
                <div><p class="security-eyebrow">ACCOUNT</p><h1>Security settings</h1><p>Protect your GOWNSEA account with an authenticator app and one-time recovery codes.</p></div>
                <ShieldCheck class="security-heading-icon" aria-hidden="true" />
            </header>

            <section class="security-panel session-panel" aria-labelledby="sessions-title">
                <div class="session-copy"><h2 id="sessions-title">Active sessions</h2><p v-if="canManageSessions">This account has <strong>{{ otherSessionCount }}</strong> other active session(s). Signing them out keeps this device signed in.</p><p v-else>Session revocation is available when the application uses database-backed sessions.</p></div>
                <button v-if="canManageSessions" class="security-button secondary" type="button" :disabled="sessionsForm.processing || otherSessionCount === 0" @click="signOutOtherSessions"><LogOut :size="15" aria-hidden="true" />{{ sessionsForm.processing ? 'Signing out…' : 'Sign out other sessions' }}</button>
            </section>

            <section v-if="!eligible" class="security-panel" role="status">
                <h2>Two-factor authentication is managed for privileged accounts</h2>
                <p>Your current role does not have access to change these security settings.</p>
            </section>

            <template v-else>
                <section v-if="recoveryCodes.length" class="security-panel recovery-panel" aria-labelledby="recovery-title">
                    <h2 id="recovery-title">Save your recovery codes</h2>
                    <p>Each code works once if you lose access to your authenticator. This list will be shown only once; store it somewhere private.</p>
                    <div class="recovery-codes" aria-label="One-time recovery codes"><code v-for="code in recoveryCodes" :key="code">{{ code }}</code></div>
                    <button class="security-button secondary" type="button" @click="copyRecoveryCodes"><Check v-if="copied" :size="16" /><Copy v-else :size="16" />{{ copied ? 'Copied' : 'Copy codes' }}</button>
                </section>

                <section class="security-panel" aria-labelledby="two-factor-title">
                    <div class="security-panel-heading"><span class="security-icon"><ShieldPlus :size="20" aria-hidden="true" /></span><div><h2 id="two-factor-title">Authenticator verification</h2><p>Require a current authenticator code whenever this account signs in.</p></div><span class="security-status" :class="enabled ? 'is-enabled' : ''">{{ enabled ? 'Enabled' : 'Not enabled' }}</span></div>

                    <div v-if="enabled" class="security-enabled">
                        <p>Authenticator verification is active. <strong>{{ recoveryCodesRemaining }}</strong> recovery codes remain.</p>
                        <form class="security-form disable-form" @submit.prevent="disableTwoFactor">
                            <label>Password<input v-model="disableForm.password" type="password" autocomplete="current-password" required></label>
                            <label>Current six-digit code<input v-model="disableForm.code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" required></label>
                            <button class="security-button danger" type="submit" :disabled="disableForm.processing">{{ disableForm.processing ? 'Disabling…' : 'Disable two-factor authentication' }}</button>
                            <small v-if="disableForm.errors.password || disableForm.errors.code" class="security-error" role="alert">{{ disableForm.errors.password ?? disableForm.errors.code }}</small>
                        </form>
                    </div>

                    <div v-else-if="hasPendingSetup" class="setup-content">
                        <ol><li>Open your authenticator app and scan this QR code.</li><li>If scanning is unavailable, enter the setup key manually.</li><li>Enter the current six-digit code to finish setup.</li></ol>
                        <div v-if="qrCodeImage" class="authenticator-qr"><img :src="qrCodeImage" alt="Authenticator setup QR code" width="224" height="224"><span>Generated in this browser. Your setup secret is not sent to a QR service.</span></div>
                        <p v-else-if="qrCodeError" class="security-error" role="alert">The QR code could not be displayed. Use the setup key below instead.</p>
                        <label class="secret-label">Setup key<code>{{ pendingSecret }}</code></label>
                        <details class="provisioning-details"><summary>Show provisioning link</summary><code>{{ provisioningUri }}</code></details>
                        <form class="security-form confirm-form" @submit.prevent="confirmSetup">
                            <label>Authenticator code<input v-model="confirmForm.code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6" placeholder="000000" required autofocus></label>
                            <button class="security-button" type="submit" :disabled="confirmForm.processing">{{ confirmForm.processing ? 'Verifying…' : 'Verify and enable' }}</button>
                            <small v-if="confirmForm.errors.code" class="security-error" role="alert">{{ confirmForm.errors.code }}</small>
                        </form>
                    </div>

                    <div v-else class="security-start">
                        <p>Use an authenticator such as 1Password, Google Authenticator, or Microsoft Authenticator. Recovery codes are generated after setup.</p>
                        <button class="security-button" type="button" :disabled="startForm.processing" @click="startSetup"><KeyRound :size="16" aria-hidden="true" />{{ startForm.processing ? 'Preparing…' : 'Set up authenticator' }}</button>
                    </div>
                </section>
            </template>
        </div>
    </AppShell>
</template>

<style scoped>
.security-page { max-width: 920px; margin: 0 auto; }
.security-heading { display:flex; align-items:center; justify-content:space-between; margin: 5px 0 18px; }
.security-heading h1 { margin:0; color:#171b24; font-size:25px; letter-spacing:-.03em; }
.security-heading p:last-child { margin:6px 0 0; color:#606874; font-size:12px; }
.security-eyebrow { margin:0 0 5px; color:#c6212a; font-size:10px; font-weight:700; letter-spacing:.1em; }
.security-heading-icon { width:34px; height:34px; color:#c6212a; }
.security-panel { margin-bottom:13px; border:1px solid #e8e9ea; border-radius:10px; background:#fff; padding:19px; box-shadow:0 1px 2px #161a2205; }
.security-panel h2 { margin:0; color:#202633; font-size:15px; }
.security-panel p { color:#606874; font-size:12px; line-height:1.6; }
.security-panel-heading { display:flex; align-items:center; gap:12px; }
.session-panel { display:flex; align-items:center; justify-content:space-between; gap:16px; }
.session-copy h2 { margin:0; color:#202633; font-size:14px; }
.session-copy p { margin:5px 0 0; }
.security-panel-heading h2 { margin-bottom:4px; }
.security-panel-heading p { margin:0; }
.security-icon { display:grid; width:40px; height:40px; flex:0 0 40px; place-items:center; border-radius:50%; background:#fff0f0; color:#c6212a; }
.security-status { margin-left:auto; border-radius:18px; background:#f1f2f4; padding:6px 10px; color:#606874; font-size:10px; font-weight:700; white-space:nowrap; }
.security-status.is-enabled { background:#e5f5ec; color:#087a4d; }
.security-start,.security-enabled,.setup-content { margin:17px 0 0 52px; }
.security-start p,.security-enabled p { max-width:650px; margin:0 0 13px; }
.security-button { display:inline-flex; min-height:37px; align-items:center; justify-content:center; gap:7px; border:0; border-radius:7px; background:#ce2029; padding:0 13px; color:#fff; cursor:pointer; font-size:11px; font-weight:650; }
.security-button:hover { background:#b91d25; }
.security-button:disabled { cursor:wait; opacity:.65; }
.security-button.secondary { border:1px solid #e0e2e5; background:#fff; color:#3f4652; }
.security-button.danger { background:#fff; color:#c6212a; border:1px solid #edcacc; }
.setup-content ol { margin:0 0 15px; padding-left:18px; color:#515866; font-size:12px; line-height:1.8; }
.authenticator-qr { display:grid; width:max-content; max-width:100%; justify-items:center; gap:8px; margin:0 0 15px; border:1px solid #e6e7e9; border-radius:9px; background:#fff; padding:10px; }
.authenticator-qr img { display:block; width:min(224px, 100%); height:auto; image-rendering:pixelated; }
.authenticator-qr span { max-width:224px; color:#68707c; font-size:10px; line-height:1.4; text-align:center; }
.secret-label { display:flex; max-width:480px; flex-direction:column; gap:6px; color:#606874; font-size:11px; }
.secret-label code { border:1px solid #e6e7e9; border-radius:7px; background:#fafafa; padding:10px; color:#202633; font-size:13px; letter-spacing:.12em; user-select:all; }
.provisioning-details { margin-top:10px; color:#626977; font-size:11px; }
.provisioning-details summary { cursor:pointer; }
.provisioning-details code { display:block; overflow-wrap:anywhere; margin-top:7px; color:#4c5360; }
.security-form { display:flex; flex-wrap:wrap; align-items:flex-end; gap:10px; margin-top:15px; }
.security-form label { display:grid; min-width:200px; gap:5px; color:#515866; font-size:10px; font-weight:600; }
.security-form input { min-height:36px; border:1px solid #dfe1e4; border-radius:6px; padding:0 10px; color:#232936; font-size:12px; }
.security-form input:focus { border-color:#c6212a; outline:3px solid #c6212a20; }
.security-error { flex-basis:100%; color:#c6212a; font-size:11px; }
.disable-form { max-width:750px; }
.recovery-panel { border-color:#f0d9a8; background:#fffdf7; }
.recovery-codes { display:grid; grid-template-columns:repeat(2,minmax(0,1fr)); gap:7px; max-width:580px; margin:13px 0; }
.recovery-codes code { border:1px solid #eee4cb; border-radius:5px; background:#fff; padding:8px; color:#252a34; font-size:12px; }
@media(max-width:700px) { .security-panel { padding:15px; }.session-panel { align-items:flex-start; flex-direction:column; }.security-panel-heading { align-items:flex-start; flex-wrap:wrap; }.security-status { margin-left:52px; }.security-start,.security-enabled,.setup-content { margin-left:0; }.security-form label { width:100%; }.recovery-codes { grid-template-columns:1fr; } }
</style>
