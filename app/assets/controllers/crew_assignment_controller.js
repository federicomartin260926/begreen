import { Controller } from '@hotwired/stimulus';

export default class extends Controller {
  static targets = ['assignment'];
  static values = { catalog: Object };

  connect() {
    this.assignmentTargets.forEach((assignment) => this.refresh(assignment));
  }

  departmentChanged(event) {
    const assignment = event.currentTarget.closest('[data-crew-assignment-target="assignment"]');
    if (assignment) {
      this.refresh(assignment);
    }
  }

  refresh(assignment) {
    const department = assignment.querySelector('[data-crew-assignment-role="department"]');
    const position = assignment.querySelector('[data-crew-assignment-role="position"]');
    if (!department || !position) {
      return;
    }

    const selectedPosition = position.value;
    const positions = this.catalogValue[`department_${department.value}`] || [];
    const remainsValid = positions.some(({ id }) => String(id) === selectedPosition);

    position.replaceChildren(new Option('—', ''));
    positions.forEach(({ id, label }) => position.add(new Option(label, String(id))));
    position.value = remainsValid ? selectedPosition : '';
  }
}
