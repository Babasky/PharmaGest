import { Controller } from '@hotwired/stimulus';
import { Chart, registerables } from 'chart.js';

Chart.register(...registerables);

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
