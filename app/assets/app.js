// assets/app.js

// 1) Estilos base
import './styles/app.scss';
import 'bootstrap-icons/font/bootstrap-icons.css';

// 2) jQuery primero (necesario para datatables y bootstrap-select)
import $ from 'jquery';
import 'jquery-ui/ui/widgets/draggable';
window.$ = $;
window.jQuery = $;

// 3) Bootstrap (bundle incluye Popper). Exponer global para otras libs.
import * as Bootstrap from 'bootstrap/dist/js/bootstrap.bundle.min.js';
window.bootstrap = Bootstrap;   // para popovers/tooltips y bootstrap-select
window.Bootstrap = Bootstrap;   // algunas libs también miran esta clave

// 4) Utilidades propias
import './js/form-validation.js';

// 5) DataTables (BS5 + responsive)
import 'datatables.net-bs5';
import 'datatables.net-bs5/css/dataTables.bootstrap5.min.css';
import 'datatables.net-responsive-bs5';
import 'datatables.net-responsive-bs5/css/responsive.bootstrap5.min.css';

// 6) Stimulus + controladores
import { Application } from '@hotwired/stimulus';
import ProjectController from './controllers/project_controller';
import ProjectListController from './controllers/project_list_controller';
import CrewController from './controllers/crew_controller';
import DatatableController from './controllers/datatable_controller';
import PlanMeasuresController from './controllers/plan_measures_controller.js';
import PlanReviewController from './controllers/plan_review_controller.js';
import SidebarController from './controllers/sidebar_controller';
import EmissionController from './controllers/emission_controller';
import EnergyFormController from './controllers/energy_form_controller';
import TransportFormController from './controllers/transport_form_controller';
import OrsAutocompleteController from './controllers/ors_autocomplete_controller';
import PlanChartsController from './controllers/plan_charts_controller';
import BackendController from './controllers/backend_controller.js';
import MeasureFormController from './controllers/measure_form_controller.js';
import MeasureBlockFilterController from './controllers/measure_block_filter_controller.js';

const application = Application.start();
application.register('project', ProjectController);
application.register('project-list', ProjectListController);
application.register('crew', CrewController);
application.register('datatable', DatatableController);
application.register('plan-measures', PlanMeasuresController);
application.register('plan-review', PlanReviewController);
application.register('sidebar', SidebarController);
application.register('emission', EmissionController);
application.register('energy-form', EnergyFormController);
application.register('transport-form', TransportFormController);
application.register('ors-autocomplete', OrsAutocompleteController);
application.register('plan-charts', PlanChartsController);
application.register('backend', BackendController);
application.register('measure-form', MeasureFormController);
application.register('measure-block-filter', MeasureBlockFilterController);

// 7) bootstrap-select (CSS estático + JS dinámico para asegurar window.bootstrap listo)
import 'bootstrap-select/dist/css/bootstrap-select.min.css';

(async () => {
  // Cargar JS de bootstrap-select sólo cuando Bootstrap ya está global
  await import('bootstrap-select');

  // Forzar detección de versión (BS5)
  if ($.fn.selectpicker && $.fn.selectpicker.Constructor) {
    $.fn.selectpicker.Constructor.BootstrapVersion = '5';
  }

  // Inicialización de selects con búsqueda
  $(function () {
    $('.selectpicker').selectpicker();
  });
})();

// 8) Inicialización global de Popovers (usa window.bootstrap de arriba)
$(function () {
  document
    .querySelectorAll('[data-bs-toggle="popover"]')
    .forEach(el => new window.bootstrap.Popover(el));
});
