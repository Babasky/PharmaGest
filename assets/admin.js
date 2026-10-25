/*
 * Point d'entrée de l'espace plateforme (EasyAdmin). EasyAdmin apporte sa mise en page et Bootstrap ; on ajoute
 * seulement les icônes et les graphiques des pages propres à SoftPharm, sans Turbo ni le thème de l'officine.
 */
import { Application } from '@hotwired/stimulus';
import ChartController from './controllers/chart_controller.js';
import 'bootstrap-icons/font/bootstrap-icons.min.css';
import './styles/admin.css';
import './mot_de_passe.js';

const application = Application.start();
application.register('chart', ChartController);
