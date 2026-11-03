import { fileURLToPath, URL } from 'node:url';
import { defineConfig, loadEnv } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import react from '@vitejs/plugin-react';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig(({ mode }) => {
    // Le porte sono definite solo in .env. Dentro Docker arrivano da
    // compose.yaml come variabili d'ambiente; fuori da Docker vengono lette
    // direttamente dal file .env.
    const env = loadEnv(mode, process.cwd(), ['APP_PORT', 'VITE_PORT']);
    const appPort = Number(process.env.APP_PORT || env.APP_PORT || 8000);
    const vitePort = Number(process.env.VITE_PORT || env.VITE_PORT || 5173);

    return {
        plugins: [
            laravel({
                input: ['resources/css/app.css', 'resources/js/app.tsx'],
                refresh: true,
                fonts: [
                    bunny('Instrument Sans', {
                        weights: [400, 500, 600, 700],
                    }),
                ],
            }),
            react(),
            tailwindcss(),
        ],
        resolve: {
            alias: {
                '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
            },
        },
        server: {
            host: '0.0.0.0',
            // Vite usa lo stesso numero di porta dentro e fuori dal container.
            port: vitePort,
            strictPort: true,
            // Laravel legge public/hot: deve contenere l'URL raggiungibile dal
            // browser, cioè la porta pubblicata sull'host.
            origin: `http://localhost:${vitePort}`,
            cors: {
                origin: [
                    `http://localhost:${appPort}`,
                    `http://127.0.0.1:${appPort}`,
                    `http://localhost:${vitePort}`,
                ],
            },
            hmr: {
                host: 'localhost',
                clientPort: vitePort,
            },
            watch: {
                ignored: ['**/storage/framework/views/**'],
            },
        },
    };
});
