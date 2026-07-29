// assets/controllers/project_controller.js
import { Controller } from "@hotwired/stimulus";

export default class extends Controller {
  static targets = [
    "type",
    "label",
    "list",
    "addButton",
    // nuevos
    "filmingType",
    "filmingGenre",
    "filmingGenreRow",
    "eventModality",
  ];

  static values = {
    prototype: String,
    index: Number,

    // FASES (según tipo de proyecto)
    labelPreEvent: String,
    labelPreFilming: String,
    labelPostEvent: String,
    labelPostFilming: String,
    labelActivityEvent: String,
    labelActivityFilming: String,

    // Mensajes
    logPrototypeMissing: String,
    logCannotInsert: String,
  };

  connect() {
    if (!this.hasIndexValue) {
      this.indexValue = this.listTarget.querySelectorAll("[data-collection-id]").length;
    }
    this.updateLabels();
    this.toggleAddButton();
    this.toggleConditionalRows();
    this.setupFilmingGenreOptions(); // inicializa opciones según el valor actual
  }

  typeTargetConnected() {
    this.updateLabels();
    this.toggleConditionalRows();
    this.setupFilmingGenreOptions();
  }

  // === Mostrar/Ocultar por tipo de proyecto
  toggleConditionalRows() {
    const projectType = this.typeTarget?.value || "";
    const rows = this.element.querySelectorAll("[data-show-when]");
    rows.forEach((row) => {
      const rule = (row.dataset.showWhen || "").trim();
      const visible = this.matchesRule(rule, projectType);
      row.classList.toggle("d-none", !visible);
      const inputs = row.querySelectorAll("input, select, textarea, button");
      inputs.forEach((el) => {
        el.disabled = !visible && el.type !== "hidden";
        if (el.dataset.requiredWhen) {
          el.required = visible && this.matchesRule(el.dataset.requiredWhen, projectType);
        }
      });
    });
  }

  matchesRule(rule, currentType) {
    if (!rule) return true;
    const parts = rule.split(",").map((s) => s.trim());
    for (const part of parts) {
      const [k, v] = part.split(":").map((s) => s.trim());
      if (this.getFieldValue(k) === v) return true;
    }
    return false;
  }

  getFieldValue(fieldName) {
    const control = this.element.querySelector(`[data-project-target="${fieldName}"]`);
    if (control) {
      return control.value || "";
    }

    const fallback = this.element.querySelector(`[name$="[${fieldName}]"]`);
    return fallback?.value || "";
  }
  // === /Mostrar/Ocultar

  updateLabels() {
    const projectType = this.typeTarget.value; // 'evento' | 'rodaje'
    const labels = {
      preproduccion: projectType === "evento" ? this.labelPreEventValue : this.labelPreFilmingValue,
      postproduccion: projectType === "evento" ? this.labelPostEventValue : this.labelPostFilmingValue,
      actividad: projectType === "rodaje"
        ? this.labelActivityFilmingValue
        : this.labelActivityEventValue,
    };

    this.labelTargets.forEach((input) => {
      const phase = input.dataset.phase;
      if (labels[phase]) input.value = labels[phase];
    });
  }

  change(event) {
    if (
      event.target === this.typeTarget ||
      event.target === this.filmingTypeTarget ||
      (this.hasEventModalityTarget && event.target === this.eventModalityTarget)
    ) {
      if (event.target === this.typeTarget) {
        this.syncTypeSpecificFields();
      } else if (event.target === this.filmingTypeTarget) {
        this.syncFilmingTypeSpecificFields();
      } else if (this.hasEventModalityTarget && event.target === this.eventModalityTarget) {
        this.syncEventModalitySpecificFields();
      }

      this.updateLabels();
      this.toggleConditionalRows();
      this.setupFilmingGenreOptions();
      this.emitChanged();
    }
  }

  // === Filming type -> genre options
  onFilmingTypeChange() {
    this.setupFilmingGenreOptions();
  }

  setupFilmingGenreOptions() {
    if (!this.hasFilmingTypeTarget || !this.hasFilmingGenreTarget) return;

    const type = this.filmingTypeTarget.value;
    const genericTypes = new Set(["feature", "short", "tv_series"]);
    const tvProgramTypes = new Set(["tv_program"]);
    const showAllowed = genericTypes.has(type) || tvProgramTypes.has(type);
    const allowed = genericTypes.has(type)
      ? new Set(["ficcion", "documental", "animacion", "experimental"])
      : tvProgramTypes.has(type)
        ? new Set(["informativo", "entretenimiento", "cultural", "educativo", "religioso"])
        : new Set();

    if (this.hasFilmingGenreRowTarget) {
      this.filmingGenreRowTarget.classList.toggle("d-none", !showAllowed);
      this.filmingGenreRowTarget
        .querySelectorAll("select, input, textarea, button")
        .forEach((el) => (el.disabled = !showAllowed));
    }

    const select = this.filmingGenreTarget;
    const current = select.value;

    Array.from(select.options).forEach((opt) => {
      if (opt.value === "") {
        opt.hidden = false;
        opt.disabled = false;
        return;
      }

      const isAllowed = allowed.has(opt.value);
      opt.hidden = !isAllowed;
      opt.disabled = !isAllowed;
    });

    if (current && !allowed.has(current)) {
      select.value = "";
    }
  }

  syncTypeSpecificFields() {
    if (!this.hasTypeTarget) return;

    if (this.typeTarget.value === "rodaje") {
      this.clearFieldGroup([
        "eventTypePrimary",
        "eventModality",
        "eventAttendeesCount",
        "eventOnlineConnections",
      ]);
    } else {
      this.clearFieldGroup([
        "filmingType",
        "filmingGenre",
        "episodios",
        "duracionEpisodio",
        "eventTypePrimary",
        "eventModality",
        "eventAttendeesCount",
        "eventOnlineConnections",
      ]);
      this.clearCheckboxGroup("distributionMedia");
    }
  }

  syncFilmingTypeSpecificFields() {
    if (!this.hasFilmingTypeTarget) return;

    const value = this.filmingTypeTarget.value;
    if (value !== "tv_series" && value !== "tv_program") {
      this.clearFieldGroup(["episodios", "duracionEpisodio"]);
    }
  }

  syncEventModalitySpecificFields() {
    if (!this.hasEventModalityTarget) return;

    const value = this.eventModalityTarget.value;
    if (value === "presencial") {
      this.clearFieldGroup(["eventOnlineConnections"]);
    } else if (value === "virtual") {
      this.clearFieldGroup(["eventAttendeesCount"]);
    } else if (value !== "hibrido") {
      this.clearFieldGroup(["eventAttendeesCount", "eventOnlineConnections"]);
    }
  }

  clearFieldGroup(fieldNames) {
    fieldNames.forEach((fieldName) => {
      const control = this.element.querySelector(`[data-project-target="${fieldName}"]`)
        || this.element.querySelector(`[name$="[${fieldName}]"]`);

      if (!control) {
        return;
      }

      if (control.type === "checkbox") {
        control.checked = false;
        return;
      }

      if (control.tagName === "SELECT" && control.multiple) {
        Array.from(control.options).forEach((option) => {
          option.selected = false;
        });
        return;
      }

      control.value = "";
    });
  }

  clearCheckboxGroup(fieldName) {
    const selectors = [
      `input[type="checkbox"][name$="[${fieldName}][]"]`,
      `input[type="checkbox"][name$="[${fieldName}]"]`,
    ];

    selectors.forEach((selector) => {
      this.element.querySelectorAll(selector).forEach((checkbox) => {
        checkbox.checked = false;
      });
    });
  }


  // Pequeño helper para traducciones: usa data-attrs del form si los tienes, o deja el key como label
  t(key) {
    try {
      // Si en algún momento inyectas un mapa de traducciones en data-*, úsalo aquí.
      return this.element?.dataset?.[key] || window?.i18n?.t?.(key) || this.fallbackLabel(key);
    } catch (_) {
      return this.fallbackLabel(key);
    }
  }
  fallbackLabel(key) {
    // Último recurso: devuelve un texto amigable si el sistema de i18n no está accesible en JS.
    const map = {
      "backend.projects.form.filming_genre.options.ficcion": "Ficción",
      "backend.projects.form.filming_genre.options.documental": "Documental",
      "backend.projects.form.filming_genre.options.animacion": "Animación",
      "backend.projects.form.filming_genre.options.experimental": "Experimental",
      "backend.projects.form.filming_genre.options.informativo": "Informativo",
      "backend.projects.form.filming_genre.options.entretenimiento": "Entretenimiento",
      "backend.projects.form.filming_genre.options.cultural": "Cultural",
      "backend.projects.form.filming_genre.options.educativo": "Educativo",
      "backend.projects.form.filming_genre.options.religioso": "Religioso",
    };
    return map[key] || key;
  }

  // === Botón añadir fase postproducción (igual que antes)
  addPostproduccion(event) {
    event.preventDefault();
    const alreadyExists = this.element.querySelector('[data-phase="postproduccion"]');
    if (alreadyExists) return;

    const prototypeContainer = document.querySelector("#phase-prototype");
    const prototypeHtml = prototypeContainer?.dataset.projectPrototype;

    if (!prototypeHtml) {
      console.error(this.logPrototypeMissingValue || "Prototipo no encontrado.");
      return;
    }

    const html = prototypeHtml.replace(/__phase__/g, this.indexValue);
    const temp = document.createElement("div");
    temp.innerHTML = html.trim();

    const newItem = temp.firstElementChild;
    if (!newItem) {
      console.error(this.logCannotInsertValue || "No se pudo insertar la fase.");
      return;
    }

    newItem.setAttribute("data-collection-id", this.indexValue);

    const hiddenInput = newItem.querySelector('input[type="hidden"][name$="[phase]"]');
    if (hiddenInput) hiddenInput.value = "postproduccion";
    const labelInput = newItem.querySelector('[data-project-target="label"]');
    if (labelInput) labelInput.dataset.phase = "postproduccion";

    this.indexValue++;
    this.listTarget.appendChild(newItem);

    this.updateLabels();
    this.toggleAddButton();
    this.emitChanged();
  }

  removeItem(event) {
    const button = event.currentTarget;
    const item = button.closest("[data-collection-id]");
    if (item) {
      const wasPost = item.querySelector('[data-phase="postproduccion"]') !== null;
      item.remove();
      if (wasPost) this.toggleAddButton();
      this.emitChanged();
    }
  }

  toggleAddButton() {
    const alreadyExists = this.element.querySelector('[data-phase="postproduccion"]');
    if (this.hasAddButtonTarget) {
      this.addButtonTarget.classList.toggle("d-none", !!alreadyExists);
    }
  }

  emitChanged() {
    this.element.dispatchEvent(
      new CustomEvent("project:changed", {
        bubbles: true,
      })
    );
  }
}
