import { Controller } from '@hotwired/stimulus'

export default class extends Controller {
  static targets = [
    'subCategory',
    'activity',
    'activityWrapper',
    'amountFields',
    'amount',
    'woodFields',
    'unit',
    'method',
    'knownWeightFields',
    'unknownDimensionsFields',
    'solidSpeciesFields',
    'sharedDimensionsFields',
    'boardFields',
    'boardFamily',
    'boardOption',
    'boardOptionWrapper',
    'manualBoardThicknessFields',
    'manualBoardThickness',
    'quantity',
    'inputWeight',
    'classification',
    'thickness',
    'length',
    'width',
    'species',
  ]

  static values = {
    activities: Object,
    densities: Object,
    scenarios: Object,
    otherLabel: String,
  }

  connect() {
    this.previousGroup = null
    this.previousBoardFamily = null
    this.subCategoryChanged()
  }

  subCategoryChanged() {
    const group = this.subCategoryTarget.value
    const groupChanged = this.previousGroup !== null && this.previousGroup !== group

    if (groupChanged) {
      this.amountTarget.value = ''
    }

    if (group === 'madera' && groupChanged) {
      this.methodTarget.value = ''
    }

    this.previousGroup = group

    const selectedActivity = this.activityTarget.value
    const activities = this.activitiesValue[group] || {}

    this.activityTarget.innerHTML = '<option value="">—</option>'

    Object.values(activities).forEach((activity) => {
      const option = document.createElement('option')
      option.value = activity.id
      option.textContent = activity.name
      option.dataset.unit = activity.unit
      option.selected = String(activity.id) === String(selectedActivity)
      this.activityTarget.appendChild(option)
    })

    this.activityWrapperTarget.hidden = !group
    this.activityChanged()
  }

  activityChanged() {
    const isWood = this.subCategoryTarget.value === 'madera'
    const hasActivity = Boolean(this.activityTarget.value)

    this.woodFieldsTarget.hidden = !isWood
    this.amountFieldsTarget.hidden = !(isWood || hasActivity)
    this.amountTarget.readOnly = isWood

    const selectedOption = this.activityTarget.selectedOptions[0]
    this.unitTarget.textContent = hasActivity && selectedOption?.dataset.unit
      ? ' (' + selectedOption.dataset.unit + ')'
      : ''

    if (isWood) {
      this.methodChanged()
    }
  }

  methodChanged() {
    const method = this.methodTarget.value
    const hasMethod = Boolean(method)
    const knownWeight = method === 'known_weight'
    const dimensions = method === 'unknown_dimensions' || method === 'solid_species'

    this.knownWeightFieldsTarget.hidden = !hasMethod || !knownWeight
    this.unknownDimensionsFieldsTarget.hidden = method !== 'unknown_dimensions'
    this.solidSpeciesFieldsTarget.hidden = method !== 'solid_species'
    this.sharedDimensionsFieldsTarget.hidden = !dimensions
    this.boardFieldsTarget.hidden = method !== 'board'

    if (method === 'board') {
      this.boardFamilyChanged()
      return
    }

    this.preview()
  }

  boardFamilyChanged() {
    const family = this.boardFamilyTarget.value
    const familyChanged = this.previousBoardFamily !== null
      && this.previousBoardFamily !== family

    if (familyChanged) {
      this.manualBoardThicknessTarget.value = ''
    }

    this.previousBoardFamily = family

    const board = this.scenariosValue.boards?.[family]
    const selectedOption = this.boardOptionTarget.value

    this.boardOptionTarget.innerHTML = '<option value="">—</option>'
    if (board) {
      board.options.forEach((option, index) => {
        const choice = document.createElement('option')
        choice.value = family + ':' + index
        choice.textContent = option.thicknessMm + ' mm'
        choice.selected = choice.value === selectedOption
        this.boardOptionTarget.appendChild(choice)
      })
      if (board.unknown) {
        const choice = document.createElement('option')
        choice.value = family + ':other'
        choice.textContent = this.otherLabelValue
        choice.selected = choice.value === selectedOption
        this.boardOptionTarget.appendChild(choice)
      }
      if (board.fixed && this.boardOptionTarget.value === '') {
        this.boardOptionTarget.selectedIndex = 1
      }
    }

    this.boardOptionWrapperTarget.hidden = !board || board.fixed
    this.boardOptionChanged()
  }

  boardOptionChanged() {
    const isOther = this.boardOptionTarget.value.endsWith(':other')
    this.manualBoardThicknessFieldsTarget.hidden = !isOther
    this.preview()
  }

  preview() {
    if (!this.methodTarget.value) {
      this.amountTarget.value = ''
      return
    }

    const quantity = Number(this.quantityTarget.value)
    let unitWeightKg = 0

    if (this.methodTarget.value === 'known_weight') {
      unitWeightKg = Number(this.inputWeightTarget.value)
    } else if (this.methodTarget.value === 'unknown_dimensions' || this.methodTarget.value === 'solid_species') {
      const density = this.methodTarget.value === 'solid_species'
        ? Number(this.scenariosValue.solidWoods?.[this.speciesTarget.value]?.densityKgM3)
        : Number(this.densitiesValue[this.classificationTarget.value])
      const thickness = Number(this.thicknessTarget.value)
      const length = Number(this.lengthTarget.value)
      const width = Number(this.widthTarget.value)

      unitWeightKg = thickness * length * width * density
    } else if (this.methodTarget.value === 'board') {
      const [family, optionKey] = this.boardOptionTarget.value.split(':')
      const board = this.scenariosValue.boards?.[family]
      const option = optionKey === 'other'
        ? board?.unknown
        : board?.options?.[Number(optionKey)]
      const thicknessMm = optionKey === 'other'
        ? Number(this.manualBoardThicknessTarget.value)
        : option?.thicknessMm
      if (!thicknessMm) {
        this.amountTarget.value = ''
        return
      }
      unitWeightKg = (thicknessMm / 1000) * option.lengthM * option.widthM * option.densityKgM3
    } else {
      this.amountTarget.value = ''
      return
    }

    const totalWeightKg = unitWeightKg * quantity

    this.amountTarget.value = Number.isFinite(totalWeightKg) && totalWeightKg > 0
      ? String(Number.parseFloat(totalWeightKg.toPrecision(15)))
      : ''
  }
}
