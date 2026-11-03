// BEGREEN #78 C2: sugerencia de fecha final en formularios de emisiones.
// Se ejecuta antes de los listeners del formulario para mantener la vista previa.
export function installEmissionDateDefault() {
  document.addEventListener('change', (event) => {
    const start = event.target;
    if (!(start instanceof HTMLInputElement) || start.type !== 'date' || start.name !== 'startDate') return;

    const form = start.form;
    if (!form || !form.hasAttribute('data-emission-date-default')) return;

    const end = form.querySelector('input[type="date"][name="endDate"]');
    if (!end || end.disabled || end.value !== '' || start.value === '') return;

    end.value = start.value;
  }, true);
}
