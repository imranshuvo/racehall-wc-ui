document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.accordion-header').forEach(header => {
        header.addEventListener('click', () => {
            const item = header.parentElement
            if (item && item.classList.contains('always-open')) {
                item.classList.add('active')
                return
            }
            item.classList.toggle('active')
        })
    })
})

let currentQuantityRules = {
    adults: { min: 1, max: null, step: 1, quantity: 1 },
    children: { min: 0, max: null, step: 1, quantity: 0 },
    total: { min: 1, max: null, step: 1 }
}
let currentPageProductLimits = null
let currentPageProducts = []

function logBookingClientEvent(eventName, context = {}) {
    const logger = window.RH_LOGGER || null
    if (!logger || !logger.ajax_url || !logger.nonce || !eventName) return

    const payload = new URLSearchParams({
        action: 'rh_log_client_event',
        nonce: logger.nonce,
        event: String(eventName),
        context: JSON.stringify(context || {})
    })

    try {
        if (navigator.sendBeacon) {
            const blob = new Blob([payload.toString()], { type: 'application/x-www-form-urlencoded; charset=UTF-8' })
            navigator.sendBeacon(logger.ajax_url, blob)
            return
        }
    } catch (e) {
    }

    try {
        fetch(logger.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: payload
        }).catch(() => {})
    } catch (e) {
    }
}

function getBookingSubmitButton() {
    return document.querySelector('.booking-s form.cart .single_add_to_cart_button')
}

function isBookingProductAvailable() {
    return Boolean(window.RH_PRODUCT_AVAILABLE) && Boolean(window.RH_PRODUCT_ID)
}

function setBookingSubmitEnabled(isEnabled) {
    const button = getBookingSubmitButton()
    if (!button) return
    button.disabled = !isEnabled
    button.setAttribute('aria-disabled', isEnabled ? 'false' : 'true')
}

function toPositiveNumber(value, fallback = null) {
    if (value === null || value === undefined || value === '') return fallback
    let normalized = value
    if (typeof normalized === 'string') {
        normalized = normalized.trim().replace(',', '.')
    }
    const num = Number(normalized)
    if (!Number.isFinite(num)) return fallback
    return num
}

function parseRuleNumber(value, fallback = null) {
    const num = toPositiveNumber(value, fallback)
    if (num === null) return fallback
    return num
}

function parseRuleFromKeys(source, keys, fallback = null) {
    if (!source || typeof source !== 'object' || !Array.isArray(keys)) return fallback
    const sourceKeys = Object.keys(source)
    for (const key of keys) {
        let value = null
        if (Object.prototype.hasOwnProperty.call(source, key)) {
            value = source[key]
        } else {
            const matchedKey = sourceKeys.find((candidate) => String(candidate).toLowerCase() === String(key).toLowerCase())
            if (matchedKey) {
                value = source[matchedKey]
            }
        }

        if (value === null || value === undefined) continue
        const parsed = parseRuleNumber(value, null)
        if (parsed !== null) return parsed
    }
    return fallback
}

function normalizeTag(tagValue) {
    return String(tagValue || '').trim().toLowerCase()
}

function pickStep(group) {
    const candidates = [
        group.step,
        group.stepQuantity,
        group.quantityStep,
        group.stepSize,
        group.increment
    ]
    for (const candidate of candidates) {
        const value = parseRuleNumber(candidate, null)
        if (value !== null && value > 0) {
            return value
        }
    }
    return 1
}

function extractRulesFromProposal(proposal, pageProductLimits = null) {
    const rules = {
        adults: { min: 1, max: null, step: 1, quantity: 1 },
        children: { min: 0, max: null, step: 1, quantity: 0 },
        total: { min: 1, max: null, step: 1 }
    }

    const proposalSource = (proposal && typeof proposal === 'object') ? proposal : {}
    const pageSource = (pageProductLimits && typeof pageProductLimits === 'object') ? pageProductLimits : {}

    const proposalMin = parseRuleFromKeys(proposalSource, ['minQuantity', 'minQty', 'minimumQuantity', 'minimumQty'], null)
    const proposalMax = parseRuleFromKeys(proposalSource, ['maxQuantity', 'maxQty', 'maximumQuantity', 'maximumQty'], null)
    const proposalMinAmount = parseRuleFromKeys(proposalSource, ['minAmount', 'minimumAmount'], null)
    const proposalMaxAmount = parseRuleFromKeys(proposalSource, ['maxAmount', 'maximumAmount'], null)
    const pageMinAmount = parseRuleFromKeys(pageSource, ['minAmount', 'minimumAmount'], null)
    const pageMaxAmount = parseRuleFromKeys(pageSource, ['maxAmount', 'maximumAmount'], null)

    if (pageMinAmount !== null && pageMinAmount > 0) {
        rules.total.min = pageMinAmount
    } else {
        if (proposalMin !== null) rules.total.min = proposalMin
        if (proposalMinAmount !== null && proposalMinAmount > 0) rules.total.min = Math.max(rules.total.min, proposalMinAmount)
    }

    if (pageMaxAmount !== null && pageMaxAmount > 0) {
        rules.total.max = pageMaxAmount
    } else {
        if (proposalMax !== null) rules.total.max = proposalMax
        if (proposalMaxAmount !== null && proposalMaxAmount > 0) rules.total.max = rules.total.max === null ? proposalMaxAmount : Math.min(rules.total.max, proposalMaxAmount)
    }

    const proposalGroups = Array.isArray(proposalSource.dynamicGroups) ? proposalSource.dynamicGroups : []
    const groups = proposalGroups
    groups.forEach(group => {
        if (!group || typeof group !== 'object') return

        const tag = normalizeTag(group.tag)
        const min = parseRuleFromKeys(group, ['minQuantity', 'minQty', 'minimumQuantity', 'minimumQty'], null)
        const max = parseRuleFromKeys(group, ['maxQuantity', 'maxQty', 'maximumQuantity', 'maximumQty'], null)
        const step = pickStep(group)
        const quantity = parseRuleNumber(group.quantity, null)

        const target = (tag === 'adults' || tag === 'adult' || tag === 'voksne')
            ? rules.adults
            : (tag === 'kids' || tag === 'children' || tag === 'child' || tag === 'born' || tag === 'børn')
                ? rules.children
                : null

        if (!target) return
        if (min !== null) target.min = min
        if (max !== null) target.max = max
        if (step > 0) target.step = step
        if (quantity !== null) target.quantity = quantity
    })

    const adultsMin = parseRuleNumber(rules.adults.min, 1)
    const childrenMin = parseRuleNumber(rules.children.min, 0)
    const totalMin = parseRuleNumber(rules.total.min, 1)
    const adultsMax = parseRuleNumber(rules.adults.max, null)
    const childrenMax = parseRuleNumber(rules.children.max, null)

    if (adultsMax !== null && adultsMax < adultsMin) {
        rules.adults.max = adultsMin
    }
    if (childrenMax !== null && childrenMax < childrenMin) {
        rules.children.max = childrenMin
    }

    const resolvedAdultsMax = parseRuleNumber(rules.adults.max, null)
    const resolvedChildrenMax = parseRuleNumber(rules.children.max, null)
    if (resolvedAdultsMax !== null && resolvedChildrenMax !== null) {
        const maxCapacity = resolvedAdultsMax + resolvedChildrenMax
        if (maxCapacity < totalMin) {
            rules.adults.max = null
            rules.children.max = null
        }
    }

    rules.adults.min = Math.max(parseRuleNumber(rules.adults.min, 1), totalMin)
    const currentAdultQty = parseRuleNumber(rules.adults.quantity, null)
    if (currentAdultQty !== null && currentAdultQty < rules.adults.min) {
        rules.adults.quantity = rules.adults.min
    }

    return rules
}

function clampByRule(value, rule) {
    let output = Math.max(0, Number(value) || 0)
    const min = parseRuleNumber(rule.min, 0)
    const max = parseRuleNumber(rule.max, null)
    const step = parseRuleNumber(rule.step, 1)

    output = Math.max(min, output)
    if (max !== null) output = Math.min(max, output)

    if (step > 0) {
        const offset = output - min
        const steps = Math.round(offset / step)
        output = min + (steps * step)
        output = Math.max(min, output)
        if (max !== null) output = Math.min(max, output)
    }

    return Math.round(output)
}

function enforceCountsByRules(counts, changedKey) {
    const next = {
        adults: clampByRule(counts.adults, currentQuantityRules.adults),
        children: clampByRule(counts.children, currentQuantityRules.children)
    }

    const totalRule = currentQuantityRules.total || { min: 1, max: null, step: 1 }
    const totalMin = Math.max(1, parseRuleNumber(totalRule.min, 1))
    const totalMax = parseRuleNumber(totalRule.max, null)
    const totalStep = parseRuleNumber(totalRule.step, 1)

    let total = next.adults + next.children

    const preferredKey = changedKey === 'children' ? 'children' : 'adults'
    const secondaryKey = preferredKey === 'adults' ? 'children' : 'adults'

    while (total < totalMin) {
        const incRule = currentQuantityRules[preferredKey] || { step: 1 }
        const incStep = parseRuleNumber(incRule.step, 1)
        const before = next[preferredKey]
        next[preferredKey] = clampByRule(next[preferredKey] + incStep, incRule)
        if (next[preferredKey] === before) {
            const secRule = currentQuantityRules[secondaryKey] || { step: 1 }
            const secStep = parseRuleNumber(secRule.step, 1)
            const secBefore = next[secondaryKey]
            next[secondaryKey] = clampByRule(next[secondaryKey] + secStep, secRule)
            if (next[secondaryKey] === secBefore) break
        }
        total = next.adults + next.children
    }

    if (totalMax !== null) {
        while (total > totalMax) {
            const decRule = currentQuantityRules[preferredKey] || { step: 1 }
            const decStep = parseRuleNumber(decRule.step, 1)
            const before = next[preferredKey]
            next[preferredKey] = clampByRule(next[preferredKey] - decStep, decRule)
            if (next[preferredKey] === before) {
                const secRule = currentQuantityRules[secondaryKey] || { step: 1 }
                const secStep = parseRuleNumber(secRule.step, 1)
                const secBefore = next[secondaryKey]
                next[secondaryKey] = clampByRule(next[secondaryKey] - secStep, secRule)
                if (next[secondaryKey] === secBefore) break
            }
            total = next.adults + next.children
        }
    }

    total = next.adults + next.children
    if (totalStep > 1) {
        let guard = 0
        while ((total - totalMin) % totalStep !== 0 && guard < 10) {
            const decRule = currentQuantityRules[preferredKey] || { step: 1 }
            const decStep = parseRuleNumber(decRule.step, 1)
            const before = next[preferredKey]
            next[preferredKey] = clampByRule(next[preferredKey] - decStep, decRule)
            if (next[preferredKey] === before) break
            total = next.adults + next.children
            guard++
        }
    }

    return next
}

function applyCountsToUI(counts) {
    const adultsEl = document.getElementById('adult-1')
    const childrenEl = document.getElementById('child-1')
    if (adultsEl) {
        if ('value' in adultsEl) {
            adultsEl.value = String(Math.max(0, counts.adults))
        } else {
            adultsEl.textContent = String(Math.max(0, counts.adults))
        }
    }
    if (childrenEl) {
        if ('value' in childrenEl) {
            childrenEl.value = String(Math.max(0, counts.children))
        } else {
            childrenEl.textContent = String(Math.max(0, counts.children))
        }
    }
}

let quantityInputsBound = false

function applyManualCount(type) {
    const el = document.getElementById(type)
    if (!el) return

    const isAdult = type === 'adult-1'
    const key = isAdult ? 'adults' : 'children'
    const raw = ('value' in el) ? el.value : el.textContent
    const parsed = parseInt(String(raw || ''), 10)

    const counts = getPartyCounts()
    const candidate = {
        adults: counts.adults,
        children: counts.children
    }

    if (Number.isFinite(parsed)) {
        candidate[key] = parsed
    }

    const enforced = enforceCountsByRules(candidate, key)
    applyCountsToUI(enforced)
    updateSummaryPeople()
}

function bindQuantityInputEvents() {
    if (quantityInputsBound) return
    quantityInputsBound = true

    const adultsEl = document.getElementById('adult-1')
    const childrenEl = document.getElementById('child-1')

    if (adultsEl && 'value' in adultsEl) {
        adultsEl.addEventListener('change', function () { applyManualCount('adult-1') })
        adultsEl.addEventListener('blur', function () { applyManualCount('adult-1') })
    }
    if (childrenEl && 'value' in childrenEl) {
        childrenEl.addEventListener('change', function () { applyManualCount('child-1') })
        childrenEl.addEventListener('blur', function () { applyManualCount('child-1') })
    }
}

function setNumericInputRules(input, rule, value, fallbackMin = 0) {
    if (!input) return

    const min = parseRuleNumber(rule && rule.min, fallbackMin)
    const max = parseRuleNumber(rule && rule.max, null)
    const step = parseRuleNumber(rule && rule.step, 1)

    input.setAttribute('min', String(min))
    input.setAttribute('step', String(step > 0 ? step : 1))
    if (max !== null) {
        input.setAttribute('max', String(max))
    } else {
        input.removeAttribute('max')
    }

    input.value = String(value)
}

function updateCounterButtonStates(counts) {
    const adultValueEl = document.getElementById('adult-1')
    const childValueEl = document.getElementById('child-1')

    const adultControls = adultValueEl ? adultValueEl.parentElement : null
    const childControls = childValueEl ? childValueEl.parentElement : null

    const adultDecBtn = adultControls ? adultControls.querySelector('button:nth-of-type(1)') : null
    const adultIncBtn = adultControls ? adultControls.querySelector('button:nth-of-type(2)') : null
    const childDecBtn = childControls ? childControls.querySelector('button:nth-of-type(1)') : null
    const childIncBtn = childControls ? childControls.querySelector('button:nth-of-type(2)') : null

    const adultRule = currentQuantityRules.adults || { min: 1, max: null }
    const childRule = currentQuantityRules.children || { min: 0, max: null }

    const adultMin = parseRuleNumber(adultRule.min, 1)
    const adultMax = parseRuleNumber(adultRule.max, null)
    const childMin = parseRuleNumber(childRule.min, 0)
    const childMax = parseRuleNumber(childRule.max, null)

    if (adultDecBtn) adultDecBtn.disabled = counts.adults <= adultMin
    if (adultIncBtn) adultIncBtn.disabled = adultMax !== null && counts.adults >= adultMax
    if (childDecBtn) childDecBtn.disabled = counts.children <= childMin
    if (childIncBtn) childIncBtn.disabled = childMax !== null && counts.children >= childMax
}

function syncQuantityConstraintsToForm(counts) {
    const adultsInput = document.getElementById('booking_adults')
    const childrenInput = document.getElementById('booking_children')
    const quantityInput = document.getElementById('booking_quantity')
    const cartQuantityInput = document.getElementById('cart_quantity')

    const total = getTotalQuantity()

    setNumericInputRules(adultsInput, currentQuantityRules.adults, counts.adults, 1)
    setNumericInputRules(childrenInput, currentQuantityRules.children, counts.children, 0)
    setNumericInputRules(quantityInput, currentQuantityRules.total, total, 1)
    setNumericInputRules(cartQuantityInput, currentQuantityRules.total, total, 1)

    updateCounterButtonStates(counts)
}

function applyProposalQuantityRules(proposal, pageProductLimits = null) {
    currentQuantityRules = extractRulesFromProposal(proposal, pageProductLimits)
    const initial = {
        adults: parseRuleNumber(currentQuantityRules.adults.quantity, currentQuantityRules.adults.min),
        children: parseRuleNumber(currentQuantityRules.children.quantity, currentQuantityRules.children.min)
    }
    const targetMinTotal = parseRuleNumber((currentQuantityRules.total || {}).min, 1)
    const initialTotal = (Number(initial.adults) || 0) + (Number(initial.children) || 0)
    if (targetMinTotal > initialTotal) {
        initial.adults = (Number(initial.adults) || 0) + (targetMinTotal - initialTotal)
    }
    const enforced = enforceCountsByRules(initial, 'adults')
    applyCountsToUI(enforced)
    updateSummaryPeople()
    syncQuantityConstraintsToForm(enforced)
}

function updateCount(type, delta) {
    const el = document.getElementById(type)
    if (!el) return

    const isAdult = type === 'adult-1'
    const key = isAdult ? 'adults' : 'children'
    const rule = isAdult ? currentQuantityRules.adults : currentQuantityRules.children
    const step = parseRuleNumber(rule.step, 1)

    const counts = getPartyCounts()
    const currentValue = isAdult ? counts.adults : counts.children
    const candidateValue = currentValue + (delta * step)

    const candidate = {
        adults: counts.adults,
        children: counts.children
    }
    candidate[key] = candidateValue

    const enforced = enforceCountsByRules(candidate, key)
    applyCountsToUI(enforced)
    updateSummaryPeople()
}

window.updateCount = updateCount

function getPartyCounts() {
    const adultsEl = document.getElementById('adult-1')
    const childrenEl = document.getElementById('child-1')
    const adultMin = parseRuleNumber((currentQuantityRules.adults || {}).min, 1)
    const childMin = parseRuleNumber((currentQuantityRules.children || {}).min, 0)
    const adultsRaw = adultsEl ? (('value' in adultsEl) ? adultsEl.value : adultsEl.textContent) : String(adultMin)
    const childrenRaw = childrenEl ? (('value' in childrenEl) ? childrenEl.value : childrenEl.textContent) : String(childMin)
    const adults = Math.max(adultMin, parseInt(String(adultsRaw), 10) || adultMin)
    const children = Math.max(childMin, parseInt(String(childrenRaw), 10) || childMin)
    return { adults, children }
}

function initializeQuantityState() {
    bindQuantityInputEvents()
    const initial = {
        adults: parseRuleNumber((currentQuantityRules.adults || {}).quantity, parseRuleNumber((currentQuantityRules.adults || {}).min, 1)),
        children: parseRuleNumber((currentQuantityRules.children || {}).quantity, parseRuleNumber((currentQuantityRules.children || {}).min, 0))
    }
    const enforced = enforceCountsByRules(initial, 'adults')
    applyCountsToUI(enforced)
    updateSummaryPeople()
    syncQuantityConstraintsToForm(enforced)
}

function getTotalQuantity() {
    const counts = enforceCountsByRules(getPartyCounts(), 'adults')
    const total = counts.adults + counts.children
    const totalMin = parseRuleNumber((currentQuantityRules.total || {}).min, 1)
    const totalMax = parseRuleNumber((currentQuantityRules.total || {}).max, null)
    let resolved = Math.max(totalMin, total)
    if (totalMax !== null) resolved = Math.min(totalMax, resolved)
    return Math.max(1, resolved)
}

function formatMoney(value) {
    const cfg = window.RH_PRICE_CONFIG || {}
    const decimals = Number.isFinite(Number(cfg.decimals)) ? Number(cfg.decimals) : 2
    const decimalSeparator = typeof cfg.decimalSeparator === 'string' ? cfg.decimalSeparator : ','
    const thousandSeparator = typeof cfg.thousandSeparator === 'string' ? cfg.thousandSeparator : '.'
    const currencySymbol = typeof cfg.currencySymbol === 'string' ? cfg.currencySymbol : ''
    const currencyPos = typeof cfg.currencyPos === 'string' ? cfg.currencyPos : 'right'

    let amount = Number(value)
    if (!Number.isFinite(amount)) amount = 0

    const fixed = amount.toFixed(decimals)
    let [intPart, decPart] = fixed.split('.')
    intPart = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, thousandSeparator)
    const numberPart = decimals > 0 ? `${intPart}${decimalSeparator}${decPart}` : intPart

    switch (currencyPos) {
        case 'left':
            return `${currencySymbol}${numberPart}`
        case 'left_space':
            return `${currencySymbol} ${numberPart}`
        case 'right_space':
            return `${numberPart} ${currencySymbol}`
        case 'right':
        default:
            return `${numberPart}${currencySymbol}`
    }
}

function updateSummaryPrice(totalQuantity) {
    const cfg = window.RH_PRICE_CONFIG || {}
    const unitPrice = Number(cfg.unitPrice)
    const resolvedUnit = Number.isFinite(unitPrice) ? unitPrice : 0
    const totalValue = resolvedUnit * Math.max(1, Number(totalQuantity) || 1)

    const unitEl = document.getElementById('summary-unit-price')
    const totalEl = document.getElementById('summary-total-price')

    if (unitEl) {
        unitEl.textContent = formatMoney(resolvedUnit)
    }
    if (totalEl) {
        totalEl.textContent = formatMoney(totalValue)
    }
}

function updateSummaryPeople() {
    const counts = enforceCountsByRules(getPartyCounts(), 'adults')
    applyCountsToUI(counts)
    const total = getTotalQuantity()

    const peopleEl = document.getElementById('summary-people')
    const i18n = window.RH_I18N || {}
    const adultsLabel = i18n.adultsLabel || ''
    const childrenLabel = i18n.childrenLabel || ''
    const adultKartLabel = i18n.adultKartLabel || ''
    const childKartLabel = i18n.childKartLabel || ''

    if (peopleEl) {
        peopleEl.innerHTML = `${counts.adults} ${adultsLabel}<br>${counts.children} ${childrenLabel}`
    }

    const kartsEl = document.getElementById('summary-karts')
    if (kartsEl) {
        kartsEl.innerHTML = `${counts.adults} ${adultKartLabel}<br>${counts.children} ${childKartLabel}`
    }

    syncQuantityConstraintsToForm(counts)

    updateSummaryPrice(total)
}

// --- Calendar and Timeslot Logic ---

const monthNames = (window.RH_I18N && Array.isArray(window.RH_I18N.monthNames) && window.RH_I18N.monthNames.length === 12)
    ? window.RH_I18N.monthNames
    : Array.from({ length: 12 }, (_, monthIndex) =>
        new Intl.DateTimeFormat(undefined, { month: 'long' }).format(new Date(2020, monthIndex, 1))
    )

let today = new Date()
let todayStart = new Date(today.getFullYear(), today.getMonth(), today.getDate())
let selectedDate = new Date(todayStart)
let currentMonth = today.getMonth()
let currentYear = today.getFullYear()
let availabilityMap = {}
let availabilityState = 'unknown'


function isPastDate(date) {
    return date < todayStart
}

function getFirstDayOfWeek(year, month) {
    let jsDay = new Date(year, month, 1).getDay()
    return (jsDay + 6) % 7 // Monday=0
}

function formatDateShort(date) {
    const dd = String(date.getDate()).padStart(2, '0')
    const mm = String(date.getMonth() + 1).padStart(2, '0')
    const yy = String(date.getFullYear() % 100).padStart(2, '0')
    return `${dd}.${mm}.${yy}`
}

function updateSummaryDate(date) {
    const label = document.getElementById('summary-date')
    if (!label) return
    if (date && !isPastDate(date)) {
        label.textContent = formatDateShort(date)
        // 🔥 SAVE TO CART FORM
        const input = document.getElementById('booking_date')
        if (input) input.value = formatDateShort(date)
        setBookingValidationMessage('date', '')
    } else {
        label.textContent = ''
    }
}

function updateSummaryTime(timeStr) {
    const label = document.getElementById('summary-time')
    if (!label) return
    label.textContent = timeStr || ''

    // 🔥 SAVE TO CART FORM
    const input = document.getElementById('booking_time')
    if (input) input.value = timeStr || ''
    if (timeStr) {
        setBookingValidationMessage('time', '')
    }
}

function setBookingProposalState(options = {}) {
    const proposalInput = document.getElementById('booking_proposal')
    const pageIdInput = document.getElementById('booking_page_id')
    const resourceIdInput = document.getElementById('booking_resource_id')
    const productIdInput = document.getElementById('booking_product_id')
    const pageProductLimitsInput = document.getElementById('booking_page_product_limits')
    const pageProductsInput = document.getElementById('booking_page_products')

    const proposal = options.proposal && typeof options.proposal === 'object'
        ? JSON.stringify(options.proposal)
        : ''
    const pageProductLimits = options.pageProductLimits && typeof options.pageProductLimits === 'object'
        ? JSON.stringify(options.pageProductLimits)
        : ''
    const pageProducts = Array.isArray(options.pageProducts)
        ? JSON.stringify(options.pageProducts)
        : ''

    if (proposalInput) proposalInput.value = proposal
    if (pageIdInput) pageIdInput.value = options.pageId ? String(options.pageId) : ''
    if (resourceIdInput) resourceIdInput.value = options.resourceId ? String(options.resourceId) : ''
    if (productIdInput && options.productId) productIdInput.value = String(options.productId)
    if (pageProductLimitsInput) pageProductLimitsInput.value = pageProductLimits
    if (pageProductsInput) pageProductsInput.value = pageProducts
}

function resetBookingTimeSelection(options = {}) {
    const shouldKeepMessage = options.keepMessage === true
    const container = document.getElementById('booking-time-slots-section') || document.querySelector('.time-slots')
    if (container) {
        container.querySelectorAll('.time-slot.selected').forEach(slot => slot.classList.remove('selected'))
    }

    updateSummaryTime('')
    setBookingProposalState()

    if (!shouldKeepMessage) {
        setBookingValidationMessage('time', '')
    }
}

function getBookingValidationMessageElement(type) {
    if (type === 'date') return document.getElementById('booking-date-error')
    if (type === 'time') return document.getElementById('booking-time-error')
    return null
}

function setBookingValidationMessage(type, message) {
    const element = getBookingValidationMessageElement(type)
    if (!element) return

    const text = String(message || '').trim()
    element.textContent = text

    if (text) {
        element.hidden = false
    } else {
        element.hidden = true
    }
}

function expandAccordionForElement(element) {
    const accordionItem = element ? element.closest('.accordion-item') : null
    if (!accordionItem) return
    accordionItem.classList.add('active')
}

function moveFocusToBookingSection(section, preferredTarget = null) {
    const focusTarget = preferredTarget || section
    if (!focusTarget) return

    expandAccordionForElement(section || focusTarget)

    if (section) {
        section.classList.add('focus-target')
        window.setTimeout(() => {
            section.classList.remove('focus-target')
        }, 1800)
    }

    if (typeof focusTarget.focus === 'function') {
        try {
            focusTarget.focus({ preventScroll: true })
        } catch (error) {
            focusTarget.focus()
        }
    }

    const scrollTarget = section || focusTarget
    if (scrollTarget && typeof scrollTarget.scrollIntoView === 'function') {
        scrollTarget.scrollIntoView({ behavior: 'smooth', block: 'center' })
    }
}

function focusCalendarSection() {
    const calendar = document.getElementById('booking-calendar-section')
    moveFocusToBookingSection(calendar)
}

function focusTimeSlotsSection() {
    const container = document.getElementById('booking-time-slots-section') || document.querySelector('.time-slots')
    const selectedSlot = container ? container.querySelector('.time-slot.selected:not(:disabled)') : null
    const firstAvailableSlot = container ? container.querySelector('.time-slot:not(:disabled)') : null
    moveFocusToBookingSection(container, selectedSlot || firstAvailableSlot || container)
}

function hasSelectedBookingDate() {
    const input = document.getElementById('booking_date')
    return !!(input && String(input.value || '').trim())
}

function hasSelectedBookingTime() {
    const input = document.getElementById('booking_time')
    return !!(input && String(input.value || '').trim())
}

function hasSelectedBookingProposal() {
    const input = document.getElementById('booking_proposal')
    return !!(input && String(input.value || '').trim())
}

function hasRequiredBookingContext() {
    const pageIdInput = document.getElementById('booking_page_id')
    const resourceIdInput = document.getElementById('booking_resource_id')
    return !!(
        pageIdInput && String(pageIdInput.value || '').trim() &&
        resourceIdInput && String(resourceIdInput.value || '').trim()
    )
}

function validateBookingSelection(options = {}) {
    const shouldFocus = options.shouldFocus !== false

    setBookingValidationMessage('date', '')
    setBookingValidationMessage('time', '')

    if (!hasSelectedBookingDate()) {
        setBookingValidationMessage('date', 'Vælg venligst en dato for at fortsætte.')
        if (shouldFocus) focusCalendarSection()
        return false
    }

    if (!hasSelectedBookingTime()) {
        setBookingValidationMessage('time', 'Vælg venligst et tidspunkt for at fortsætte.')
        if (shouldFocus) focusTimeSlotsSection()
        return false
    }

    if (!hasSelectedBookingProposal()) {
        setBookingValidationMessage('time', 'Vælg venligst tidspunktet igen for at fortsætte.')
        if (shouldFocus) focusTimeSlotsSection()
        return false
    }

    if (!hasRequiredBookingContext()) {
        setBookingValidationMessage('time', 'Bookingdata mangler. Vælg tidspunktet igen for at fortsætte.')
        if (shouldFocus) focusTimeSlotsSection()
        return false
    }

    return true
}

function canGoPrev(month, year) {
    const minYear = todayStart.getFullYear()
    const minMonth = todayStart.getMonth()
    return (year > minYear) || (year === minYear && month > minMonth)
}

function updateMonthNav() {
    const prevBtn = document.getElementById('prevMonthBtn')
    if (!prevBtn) return
    prevBtn.disabled = !canGoPrev(currentMonth, currentYear)
}

function getBookingLocation() {
    const field = document.getElementById('booking_location')
    return field && field.value ? field.value : (window.RH_BOOKING_LOCATION || '')
}

async function fetchAvailabilityForMonth(month, year) {
    if (!isBookingProductAvailable()) {
        availabilityState = 'error'
        availabilityMap = {}
        const daysContainer = document.getElementById('calendarDays')
        if (daysContainer) {
            daysContainer.innerHTML = ''
        }
        return {}
    }

    // Show spinner in calendar UI
    const daysContainer = document.getElementById('calendarDays')
    if (daysContainer) {
        daysContainer.innerHTML = `<span class="calendar-loading"><div class="spinner"></div></span>`
    }
    const yyyy = year
    const mm = String(month + 1).padStart(2, '0')
    const firstDay = `${yyyy}-${mm}-01`
    const lastDay = `${yyyy}-${mm}-${new Date(year, month + 1, 0).getDate()}`
    try {
        const res = await fetch(window.my_ajax_object.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: new URLSearchParams({
                action: 'rh_get_availability',
                productId: window.RH_PRODUCT_ID,
                dateFrom: firstDay,
                dateTill: lastDay,
                bookingLocation: getBookingLocation(),
                nonce: window.my_ajax_object.nonce || ''
            })
        })
        const text = await res.text()
        let data
        try {
            data = JSON.parse(text)
        } catch (e) {
            availabilityState = 'error'
            showCalendarError('Fejl i kalenderdata. Prøv at genindlæse siden.')
            return {}
        }
        availabilityMap = {}
        if (Array.isArray(data.activities) && data.activities.length > 0) {
            availabilityState = 'ok'
            data.activities.forEach(a => {
                availabilityMap[a.date.split('T')[0]] = a.status
            })
        } else {
            availabilityState = 'empty'
        }
        return availabilityMap
    } catch (err) {
        availabilityState = 'error'
        showCalendarError('Netværksfejl ved hentning af kalender.')
        return {}
    }
}

function showCalendarError(msg) {
    const daysContainer = document.getElementById('calendarDays')
    if (daysContainer) {
        daysContainer.innerHTML = `<span class="calendar-error">${msg}</span>`
    }
}
// Add spinner style if not present
(function () {
    if (!document.getElementById('rh-calendar-spinner-style')) {
        const style = document.createElement('style')
        style.id = 'rh-calendar-spinner-style'
        style.textContent = `
            .calendar-loading {
                display: flex;
                align-items: center;
                justify-content: center;
                color: #C8102E;
                font-size: 15px;
                font-weight: 600;
                min-height: 40px;
                padding: 12px 0;
            }
            .spinner {
            width: 50px;
            height: 50px;
            border: 5px solid #e5e7eb;        /* Light gray */
            border-top: 5px solid #C8102E;    /* Blue */
            border-radius: 50%;
            animation: spin 0.8s linear infinite;
            }

            @keyframes spin {
            to {
                transform: rotate(360deg);
            }
            }
        `
        document.head.appendChild(style)
    }
})()

function renderCalendar(month, year) {
    const daysContainer = document.getElementById('calendarDays')
    if (!daysContainer) return
    daysContainer.innerHTML = ''
    const monthYearLabel = document.getElementById('monthYear')
    if (monthYearLabel) {
        monthYearLabel.textContent = `${monthNames[month]} - ${year}`
    }
    const firstDay = getFirstDayOfWeek(year, month)
    const daysInMonth = new Date(year, month + 1, 0).getDate()
    const daysInPrevMonth = new Date(year, month, 0).getDate()
    // Previous month days
    for (let i = firstDay - 1; i >= 0; i--) {
        const dayNum = daysInPrevMonth - i
        const span = document.createElement('span')
        span.className = 'muted disabled'
        span.textContent = dayNum < 10 ? '0' + dayNum : dayNum
        daysContainer.appendChild(span)
    }
    // Current month days
    for (let d = 1; d <= daysInMonth; d++) {
        const span = document.createElement('span')
        span.textContent = d < 10 ? '0' + d : d
        const dateCur = new Date(year, month, d)
        const dateKey = `${dateCur.getFullYear()}-${String(dateCur.getMonth() + 1).padStart(2, '0')}-${String(dateCur.getDate()).padStart(2, '0')}`
        const status = availabilityMap[dateKey]
        const forceUnavailable = availabilityState !== 'ok'
        const isBooked = forceUnavailable || (typeof status !== 'undefined' && status !== 0)
        if (isBooked || isPastDate(dateCur)) {
            span.className = 'muted disabled'
            span.style.cursor = 'not-allowed'
            span.style.color = isPastDate(dateCur) ? '#999999' : '#555555'
            span.title = forceUnavailable
                ? 'Unavailable'
                : (isBooked ? (status === 1 ? 'Fully booked' : 'Unavailable') : 'Unavailable')
        } else {
            // Available date
            span.style.color = '#FFF'
            span.style.cursor = 'pointer'
            span.addEventListener('click', function () {
                selectedDate = new Date(year, month, d)
                renderCalendar(currentMonth, currentYear)
                updateSummaryDate(selectedDate)
                logBookingClientEvent('date_selected', { date: dateKey, productId: window.RH_PRODUCT_ID || 0 })
                fetchAndRenderTimeslots(dateKey)
            })
        }
        if (!isPastDate(dateCur) && !isBooked &&
            d === selectedDate.getDate() &&
            month === selectedDate.getMonth() &&
            year === selectedDate.getFullYear()
        ) {
            span.classList.add('selected')
            span.style.backgroundColor = '#C8102E'
        }
        daysContainer.appendChild(span)
    }
    // Next month days
    let totalCells = firstDay + daysInMonth
    let nextDays = (7 - (totalCells % 7)) % 7
    for (let i = 1; i <= nextDays; i++) {
        const span = document.createElement('span')
        span.className = 'muted disabled'
        span.textContent = i < 10 ? '0' + i : i
        daysContainer.appendChild(span)
    }
}

async function fetchAndRenderTimeslots(dateStr) {
    if (!isBookingProductAvailable()) {
        const container = document.querySelector('.time-slots')
        if (container) {
            container.innerHTML = ''
        }
        setBookingSubmitEnabled(false)
        return
    }
    resetBookingTimeSelection()
    setBookingSubmitEnabled(false)
    const container = document.querySelector('.time-slots')
    if (container) {
        container.innerHTML = '<div class="loading"><div class="spinner"></div></div>'
    }
    try {
        const productId = window.RH_PRODUCT_ID
        const res = await fetch(window.my_ajax_object.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            body: new URLSearchParams({
                action: 'rh_get_timeslots',
                productId: window.RH_PRODUCT_ID,
                date: dateStr,
                quantity: String(getTotalQuantity()),
                bookingLocation: getBookingLocation(),
                nonce: window.my_ajax_object.nonce || ''
            })
        })
        const text = await res.text()
        let data
        try {
            data = JSON.parse(text)
        } catch (e) {
            if (container) container.innerHTML = '<span class="calendar-error" style="color:#fff">Fejl i tidsdata. Prøv igen.</span>'
            return
        }
        if (!container) return
        container.innerHTML = ''
        if (data.success === false || data.data === false) {
            logBookingClientEvent('timeslots_render_failed', { date: dateStr, productId, quantity: getTotalQuantity() })
            container.innerHTML = `<span class="calendar-error">${data.message || 'Ingen tider tilgængelige.'}</span>`
            setBookingSubmitEnabled(true)
            return
        }
        logBookingClientEvent('timeslots_loaded', {
            date: dateStr,
            productId,
            quantity: getTotalQuantity(),
            proposalCount: data.proposals && Array.isArray(data.proposals) ? data.proposals.length : 0
        })
        const responsePageId = data && data.pageId ? String(data.pageId) : ''
        currentPageProductLimits = (data && typeof data.pageProductLimits === 'object') ? data.pageProductLimits : null
        currentPageProducts = (data && Array.isArray(data.pageProducts)) ? data.pageProducts : []
        if (data.proposals && data.proposals.length) {
            data.proposals.forEach(proposal => {
                const blocks = Array.isArray(proposal.blocks) ? proposal.blocks : []
                const firstBlock = blocks.length ? blocks[0] : null
                const firstSlot = firstBlock && firstBlock.block ? firstBlock.block : {}
                const resourceId = firstSlot.resourceId || (firstBlock && firstBlock.productLineIds && firstBlock.productLineIds[0]) || ''
                const start = firstSlot.start ? firstSlot.start.substring(11, 16) : ''
                const stop = firstSlot.stop ? firstSlot.stop.substring(11, 16) : ''
                const blockName = firstSlot && firstSlot.name ? String(firstSlot.name).trim() : ''

                const btn = document.createElement('button')
                btn.className = 'time-slot'
                btn.textContent = blockName ? `${blockName} - ${start}` : `${start} - ${stop}`
                btn.setAttribute('data-time', `${start} - ${stop}`)

                // Disable button if slot == 0
                if (firstSlot.slot === 0) {
                    btn.disabled = true
                    btn.classList.add('disabled')
                } else {
                    btn.addEventListener('click', async function () {
                        container.querySelectorAll('.time-slot').forEach(s => s.classList.remove('selected'))
                        this.classList.add('selected')
                        updateSummaryTime(this.getAttribute('data-time'))
                        const proposalPageId = responsePageId || (proposal && proposal.pageId ? String(proposal.pageId) : '')
                        setBookingProposalState({
                            proposal,
                            pageId: proposalPageId,
                            resourceId,
                            productId,
                            pageProductLimits: currentPageProductLimits,
                            pageProducts: currentPageProducts
                        })
                        applyProposalQuantityRules(proposal, currentPageProductLimits)
                        logBookingClientEvent('timeslot_selected', {
                            date: dateStr,
                            productId,
                            resourceId,
                            time: this.getAttribute('data-time') || ''
                        })

                        // Show spinner while saving proposal
                        // container.innerHTML = `<span class="calendar-loading"><div class="spinner"></div></span>`

                        // Await the saveProposalToSession call
                        const saveSucceeded = await saveProposalToSession(proposal, proposalPageId, resourceId, productId)
                        if (saveSucceeded) {
                            logBookingClientEvent('proposal_saved', {
                                productId,
                                resourceId,
                                date: dateStr,
                                quantity: getTotalQuantity()
                            })
                            setBookingSubmitEnabled(true)
                        } else {
                            logBookingClientEvent('proposal_save_failed', {
                                productId,
                                resourceId,
                                date: dateStr,
                                quantity: getTotalQuantity()
                            })
                            console.warn('Continuing with posted proposal fallback after session save failed.')
                            setBookingSubmitEnabled(true)
                        }

                        // Re-render the time slots for the same date to remove spinner and restore UI
                        // fetchAndRenderTimeslots(
                        //     selectedDate
                        //         ? `${selectedDate.getFullYear()}-${String(selectedDate.getMonth() + 1).padStart(2, '0')}-${String(selectedDate.getDate()).padStart(2, '0')}`
                        //         : ''
                        // )
                    })
                }

                container.appendChild(btn)
            })
        } else {
            container.innerHTML = '<span style="color:#fff">Ingen ledige tider denne dag.</span>'
        }
        setBookingSubmitEnabled(true)
    } catch (err) {
        if (container) container.innerHTML = '<span class="calendar-error" style="color:#fff">Netværksfejl ved hentning af tider.</span>'
        setBookingSubmitEnabled(true)
    }
}

// Inject CSS for .time-slot buttons if not already present
(function () {
    if (!document.getElementById('rh-timeslot-style')) {
        const style = document.createElement('style')
        style.id = 'rh-timeslot-style'
        style.textContent = `
            .time-slot {
                padding: 3px 10px !important;
                background: transparent;
                border: 1px solid #C8102E !important;
                color: #ffffff;
                font-family: 'Oxanium', sans-serif !important;
                font-weight: 400 !important;
                font-size: 10px !important;
                cursor: pointer;
                border-radius: 0;
                transition: all 0.3s ease;
                position: relative;
            }
            .time-slot::after {
                content: '';
                position: absolute;
                right: -8px;
                bottom: -8px;
                width: 13px;
                height: 13px;
                background: #221F20;
                border-left: 1px solid #C8102E ;
                transform: rotate(45deg);
            }
            .time-slot:hover,
            .time-slot:focus {
                background: #C8102E;
                color: #fff;
                border-color: #C8102E;
            }
            .time-slot.selected {
                background: #C8102E;
                color: #fff;
                border-color: #C8102E;
            }
            .time-slot.disabled,
            .time-slot:disabled {
                background: #444 !important;
                color: #bbb !important;
                border-color: #888 !important;
                cursor: not-allowed !important;
                opacity: 0.6;
            }

            /* --- Custom CSS below --- */
            .calendar-error {
                color: #ff4444;
                font-weight: bold;
                padding: 8px 0;
                display: block;
                text-align: center;
            }
            .summary-section {
                margin-bottom: 12px;
                padding: 8px 0;
                border-bottom: 1px solid #333;
            }
            .summary-label {
                font-size: 13px;
                color: #fff;
            }
            .summary-section h4 {
                font-size: 14px;
                color: #C8102E;
                margin-bottom: 2px;
            }
            .total-price {
                font-size: 18px;
                color: #C8102E;
                font-weight: bold;
            }
            .single_add_to_cart_button.button.alt {
                background: #C8102E;
                color: #fff;
                border: none;
                padding: 12px 32px;
                font-size: 16px;
                border-radius: 0;
                transition: background 0.2s;
            }
            .single_add_to_cart_button.button.alt:hover {
                background: #a00b22;
            }
                .booking-s hd {
                    color: #fff;
                    font-size: 16px;
                    font-weight: 400;
                }
            /* --- End custom CSS --- */
        `
        document.head.appendChild(style)
    }
})()
async function initCalendar() {
    const prevBtn = document.getElementById('prevMonthBtn')
    const nextBtn = document.getElementById('nextMonthBtn')
    if (!isBookingProductAvailable()) {
        setBookingSubmitEnabled(false)
        const daysContainer = document.getElementById('calendarDays')
        if (daysContainer) {
            daysContainer.innerHTML = ''
        }
        const container = document.querySelector('.time-slots')
        if (container) {
            container.innerHTML = ''
        }
        return
    }
    await fetchAvailabilityForMonth(currentMonth, currentYear)
    renderCalendar(currentMonth, currentYear)
    updateMonthNav()
    updateSummaryDate(selectedDate)
    if (selectedDate) {
        const dateKey = `${selectedDate.getFullYear()}-${String(selectedDate.getMonth() + 1).padStart(2, '0')}-${String(selectedDate.getDate()).padStart(2, '0')}`
        if (availabilityState === 'ok') {
            fetchAndRenderTimeslots(dateKey)
        } else {
            const container = document.querySelector('.time-slots')
            if (container) {
                container.innerHTML = '<span style="color:#fff">Ingen ledige tider denne dag.</span>'
            }
        }
    }
    if (prevBtn) {
        prevBtn.addEventListener('click', async function () {
            if (!canGoPrev(currentMonth, currentYear)) return
            currentMonth--
            if (currentMonth < 0) {
                currentMonth = 11
                currentYear--
            }
            await fetchAvailabilityForMonth(currentMonth, currentYear)
            renderCalendar(currentMonth, currentYear)
            updateMonthNav()
            updateSummaryDate(selectedDate)
        })
    }
    if (nextBtn) {
        nextBtn.addEventListener('click', async function () {
            currentMonth++
            if (currentMonth > 11) {
                currentMonth = 0
                currentYear++
            }
            await fetchAvailabilityForMonth(currentMonth, currentYear)
            renderCalendar(currentMonth, currentYear)
            updateMonthNav()
            updateSummaryDate(selectedDate)
        })
    }
}

// Initialize calendar after DOM ready
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initCalendar)
} else {
    initCalendar()
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initializeQuantityState)
} else {
    initializeQuantityState()
}


async function saveProposalToSession(block, pageId, resourceId, productId) {
    console.log('Saving proposal to session:', block)
    if (!window.my_ajax_object) return false
    const pageProductLimits = (currentPageProductLimits && typeof currentPageProductLimits === 'object')
        ? JSON.stringify(currentPageProductLimits)
        : ''
    const pageProducts = Array.isArray(currentPageProducts)
        ? JSON.stringify(currentPageProducts)
        : ''
    try {
        const res = await fetch(window.my_ajax_object.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'rh_save_proposal',
                proposal: JSON.stringify(block),
                pageId: pageId || (block && block.pageId ? String(block.pageId) : ''),
                resourceId: resourceId || '',
                productId: productId || '',
                quantity: String(getTotalQuantity()),
                pageProductLimits,
                pageProducts,
                bookingLocation: getBookingLocation(),
                nonce: window.my_ajax_object.nonce || ''
            })
        })
        const responseText = await res.text()
        const result = JSON.parse(responseText)
        console.log('Save proposal response text:', responseText)
        console.log('Save proposal response status:', result)
        if (!result.success) {
            console.warn('Could not save proposal to session:', result)
            return false
        }
        else {
            const addonItems = document.getElementById('addonSummaryItems')
            if (addonItems) {
                addonItems.innerHTML = ''
                const payload = result && typeof result.data === 'object' ? result.data : {}
                const supplements = Array.isArray(payload.supplements) ? payload.supplements : []
                if (!supplements.length) {
                    addonItems.innerHTML = '<span class="summary-label">—</span>'
                    return true
                }
                supplements.forEach(supplement => {
                    if (!supplement || !supplement.product) return
                    const priceList = Array.isArray(supplement.product.prices)
                        ? supplement.product.prices
                        : (Array.isArray(supplement.product.price) ? supplement.product.price : [])
                    const firstPrice = priceList.length ? priceList[0] : null
                    const amount = firstPrice && Number.isFinite(Number(firstPrice.amount)) ? Number(firstPrice.amount) : 0

                    const div = document.createElement('div')
                    div.className = 'addon'
                    div.innerHTML = `
                        <div style="display:flex; justify-content:space-between; align-items:center; ">
                            <h4>${supplement.product.name}</h4>
                            <span class="price">${formatMoney(amount)}</span>
                        </div>
                    `
                    addonItems.appendChild(div)
                })
            }
            return true
        }
    } catch (e) {
        logBookingClientEvent('proposal_save_exception', {
            productId: productId || '',
            resourceId: resourceId || '',
            message: e && e.message ? e.message : 'unknown'
        })
        console.warn('Could not save proposal to session:', e)
        return false
    }
}

function initBookingAddToCartSubmitGuard() {
    const form = document.querySelector('.booking-s form.cart')
    if (!form) return

    setBookingSubmitEnabled(true)
    logBookingClientEvent('product_page_ready', { productId: window.RH_PRODUCT_ID || 0 })

    form.addEventListener('submit', function (event) {
        if (!validateBookingSelection({ shouldFocus: true })) {
            logBookingClientEvent('add_to_cart_blocked', {
                productId: window.RH_PRODUCT_ID || 0,
                quantity: getTotalQuantity()
            })
            event.preventDefault()
            return false
        }

        logBookingClientEvent('add_to_cart_submitted', {
            productId: window.RH_PRODUCT_ID || 0,
            quantity: getTotalQuantity()
        })

        return true
    })
}

if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', initBookingAddToCartSubmitGuard)
} else {
    initBookingAddToCartSubmitGuard()
}