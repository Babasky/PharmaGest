import { Controller } from '@hotwired/stimulus';
import { Chart, registerables } from 'chart.js';

Chart.register(...registerables);
// Harmonisation avec le thème de l'application (police, barres arrondies, couleurs selon clair / sombre).
Chart.defaults.font.family = "'Noto Sans', system-ui, sans-serif";
Chart.defaults.elements.bar.borderRadius = 6;
Chart.defaults.plugins.legend.labels.usePointStyle = true;

const formatFcfa = (valeur) =>
    `${Math.round(valeur).toLocaleString('fr-FR').replace(/ /g, ' ')} FCFA`;

/*
 * Graphique Chart.js générique : la configuration est fournie par le serveur.
 *
 *   <canvas {{ stimulus_controller('chart', {config: {...}, devise: true}) }}></canvas>
 */
export default class extends Controller {
    static values = { config: Object, devise: Boolean };

    connect() {
        const sombre = document.documentElement.dataset.bsTheme === 'dark';
        Chart.defaults.color = sombre ? '#9aa4b2' : '#6b7280';
        Chart.defaults.borderColor = sombre ? 'rgba(255, 255, 255, 0.08)' : 'rgba(0, 0, 0, 0.06)';

        const config = structuredClone(this.configValue);
        config.options = {
            responsive: true,
            maintainAspectRatio: false,
            animation: false,
            ...(config.options ?? {}),
        };

        if (this.deviseValue) {
            config.options.scales = { y: { beginAtZero: true, suggestedMax: 1000, ticks: { precision: 0, callback: formatFcfa } } };
            config.options.plugins = {
                tooltip: { callbacks: { label: (ctx) => `${ctx.dataset.label} : ${formatFcfa(ctx.parsed.y)}` } },
            };
        }

        this.chart = new Chart(this.element, config);
    }

    disconnect() {
        this.chart?.destroy();
    }
}
