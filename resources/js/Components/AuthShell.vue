<script setup lang="ts">
withDefaults(defineProps<{ title: string; description: string; eyebrow?: string }>(), { eyebrow: 'GOWNSEA STAFF ACCESS' });
</script>

<template>
    <main class="login-page">
        <div class="auth-frame">
            <div class="auth-halo" aria-hidden="true"></div>
            <section class="auth-card" aria-labelledby="auth-title">
                <div class="auth-accent" aria-hidden="true"></div>
                <div class="auth-card-body">
                    <header class="auth-header">
                        <div class="auth-heading-copy">
                            <p class="auth-eyebrow">{{ eyebrow }}</p>
                            <h1 id="auth-title">{{ title }}</h1>
                            <p class="auth-description">{{ description }}</p>
                        </div>
                        <img class="auth-logo" :src="'/images/brand/gownsea-logo.png'" alt="GOWNSEA Graduation Wear Specialist">
                    </header>
                    <slot />
                </div>
            </section>
            <p class="auth-copyright">GOWNSEA Graduation Wear · Kenya</p>
        </div>
    </main>
</template>
