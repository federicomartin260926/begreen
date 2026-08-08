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
    'dimensionsFields',
    'quantity',
    'inputWeight',
    'classification',
    'thickness',
    'length',
    'width',
  ]

  static values = {
    activities: Object,
    densities: Object,
  }

  connect() {
    this.previousGroup = null
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

    this.knownWeightFieldsTarget.hidden = !hasMethod || !knownWeight
    this.dimensionsFieldsTarget.hidden = !hasMethod || knownWeight

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
    } else {
      const density = Number(this.densitiesValue[this.classificationTarget.value])
      const thickness = Number(this.thicknessTarget.value)
      const length = Number(this.lengthTarget.value)
      const width = Number(this.widthTarget.value)

      unitWeightKg = thickness * length * width * density
    }

    const totalWeightKg = unitWeightKg * quantity

    this.amountTarget.value = Number.isFinite(totalWeightKg) && totalWeightKg > 0
      ? String(totalWeightKg)
      : ''
  }
}
