import '@inertiajs/core';

declare module '@inertiajs/core' {
    interface InertiaConfig {
        sharedPageProps: {
            auth: {
                user: null | {
                    id: number;
                    name: string;
                    email: string;
                    role: string;
                };
            };
            errors: Record<string, string>;
        };
    }
}
