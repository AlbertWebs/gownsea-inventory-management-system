<script setup lang="ts">
import { onBeforeUnmount, ref } from 'vue';
import { Camera, CameraOff, Flashlight, X } from '@lucide/vue';

const emit = defineEmits<{ detected: [value: string] }>();
const open = ref(false);
const message = ref('');
const video = ref<HTMLVideoElement | null>(null);
const stream = ref<MediaStream | null>(null);
const torchSupported = ref(false);
const torchOn = ref(false);
let scanTimer: number | undefined;

type DetectedBarcode = { rawValue: string };
type Detector = { detect: (source: HTMLVideoElement) => Promise<DetectedBarcode[]> };
type DetectorConstructor = new (options: { formats: string[] }) => Detector;
type TorchTrack = Omit<MediaStreamTrack, 'getCapabilities' | 'applyConstraints'> & {
    getCapabilities?: () => { torch?: boolean };
    applyConstraints: (constraints: unknown) => Promise<void>;
};

async function start(): Promise<void> {
    message.value = '';
    const DetectorClass = (window as unknown as { BarcodeDetector?: DetectorConstructor }).BarcodeDetector;
    if (!window.isSecureContext || !navigator.mediaDevices?.getUserMedia) {
        message.value = 'Camera scanning requires a secure HTTPS connection and a supported browser. Use the focused barcode field with a hardware scanner or type the asset code.';
        open.value = true;
        return;
    }
    if (!DetectorClass) {
        message.value = 'This browser does not provide native barcode detection. Use the focused barcode field with a hardware scanner or type the asset code.';
        open.value = true;
        return;
    }

    open.value = true;
    try {
        stream.value = await navigator.mediaDevices.getUserMedia({ video: { facingMode: { ideal: 'environment' } }, audio: false });
        if (!video.value) return;
        video.value.srcObject = stream.value;
        await video.value.play();
        const track = stream.value.getVideoTracks()[0] as TorchTrack | undefined;
        torchSupported.value = track?.getCapabilities?.().torch === true;
        const detector = new DetectorClass({ formats: ['qr_code', 'code_128', 'code_39', 'ean_13', 'ean_8', 'upc_a'] });
        scanTimer = window.setInterval(async () => {
            if (!video.value || video.value.readyState < HTMLMediaElement.HAVE_CURRENT_DATA) return;
            try {
                const matches = await detector.detect(video.value);
                if (matches[0]?.rawValue) {
                    emit('detected', matches[0].rawValue.trim());
                    stop();
                }
            } catch {
                message.value = 'Barcode detection was interrupted. Reposition the gown label and try again.';
            }
        }, 160);
    } catch {
        message.value = 'Camera access was not granted or the camera is unavailable. You can continue with manual entry or a hardware scanner.';
        stopStream();
    }
}

function stopStream(): void {
    if (scanTimer) window.clearInterval(scanTimer);
    scanTimer = undefined;
    stream.value?.getTracks().forEach((track) => track.stop());
    stream.value = null;
    torchSupported.value = false;
    torchOn.value = false;
}

function stop(): void {
    stopStream();
    open.value = false;
}

async function toggleTorch(): Promise<void> {
    const track = stream.value?.getVideoTracks()[0] as TorchTrack | undefined;
    if (!track) return;
    try {
        torchOn.value = !torchOn.value;
        await track.applyConstraints({ advanced: [{ torch: torchOn.value }] });
    } catch {
        torchOn.value = false;
        message.value = 'The flashlight could not be enabled on this camera.';
    }
}

onBeforeUnmount(stopStream);
</script>

<template>
    <button type="button" class="camera-scan-button" @click="start"><Camera aria-hidden="true" /> Use camera scanner</button>
    <div v-if="open" class="scanner-backdrop" role="presentation" @click.self="stop">
        <section class="scanner-dialog" role="dialog" aria-modal="true" aria-labelledby="scanner-title">
            <header><div><h2 id="scanner-title">Scan gown barcode</h2><p>Hold the label steady inside the frame.</p></div><button class="icon-button" aria-label="Close scanner" @click="stop"><X aria-hidden="true" /></button></header>
            <div v-if="stream" class="scanner-video-wrap"><video ref="video" playsinline muted /><span class="scanner-reticle" aria-hidden="true" /></div>
            <div v-else class="scanner-message"><CameraOff aria-hidden="true" /><p>{{ message || 'Starting camera…' }}</p></div>
            <p v-if="message && stream" class="scanner-warning" role="status">{{ message }}</p>
            <footer><button v-if="torchSupported" type="button" class="secondary-link" @click="toggleTorch"><Flashlight aria-hidden="true" /> {{ torchOn ? 'Turn off light' : 'Turn on light' }}</button><span v-else class="scanner-hint">Keyboard scanner and manual entry remain available.</span><button type="button" class="primary-button" @click="stop">Close</button></footer>
        </section>
    </div>
</template>

<style scoped>
.camera-scan-button { display: inline-flex; min-height: 36px; align-items: center; gap: 7px; border: 1px solid #e1e3e6; border-radius: 7px; background: #fff; padding: 0 10px; color: #454d5a; cursor: pointer; font-size: 10px; font-weight: 600; }
.camera-scan-button:hover { border-color: #cbd0d7; background: #fafafa; }
.camera-scan-button svg { width: 15px; height: 15px; }
.scanner-backdrop { position: fixed; inset: 0; z-index: 80; display: grid; place-items: center; background: #10131ab0; padding: 18px; }
.scanner-dialog { width: min(560px, 100%); overflow: hidden; border: 1px solid #e9ebee; border-radius: 14px; background: #fff; box-shadow: 0 20px 70px #10131a50; }
.scanner-dialog header, .scanner-dialog footer { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 15px 17px; }
.scanner-dialog h2 { margin: 0; color: #171c26; font-size: 15px; }
.scanner-dialog header p { margin: 4px 0 0; color: #838a95; font-size: 10px; }
.scanner-video-wrap { position: relative; aspect-ratio: 4 / 3; overflow: hidden; background: #15191f; }
.scanner-video-wrap video { width: 100%; height: 100%; object-fit: cover; }
.scanner-reticle { position: absolute; inset: 22% 15%; border: 2px solid #fff; border-radius: 13px; box-shadow: 0 0 0 999px #10131a30; }
.scanner-message { display: grid; min-height: 210px; place-content: center; justify-items: center; gap: 12px; padding: 24px; color: #707885; text-align: center; }
.scanner-message svg { width: 30px; color: #9ca2ab; }
.scanner-message p { max-width: 400px; margin: 0; font-size: 12px; line-height: 1.55; }
.scanner-dialog footer { border-top: 1px solid #eff0f2; }
.scanner-hint { color: #808793; font-size: 10px; }
.scanner-warning { margin: 0; padding: 0 17px 10px; color: #a66418; font-size: 10px; }
</style>
