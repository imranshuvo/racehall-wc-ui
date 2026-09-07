const assert = require('node:assert/strict')
const fs = require('node:fs')
const vm = require('node:vm')

const source = fs.readFileSync(require('node:path').join(__dirname, '..', 'assets/js/single-product.js'), 'utf8')
const functionStart = source.indexOf('function forceTotalQuantityMinimum(')
assert.notEqual(functionStart, -1, 'production force-minimum function exists')

let depth = 0
let functionEnd = -1
for (let index = source.indexOf('{', functionStart); index < source.length; index++) {
    if (source[index] === '{') depth++
    if (source[index] === '}') depth--
    if (depth === 0) {
        functionEnd = index + 1
        break
    }
}
assert.notEqual(functionEnd, -1, 'production force-minimum function is complete')
const productionFunction = source.slice(functionStart, functionEnd)

function execute(initialCounts, totalMax, minimum, existingMinimum = 12) {
    const state = {
        counts: { ...initialCounts },
        applied: null,
        synced: null,
        summaryUpdated: false
    }
    const context = {
        currentQuantityRules: {
            adults: { min: 0, max: 38, step: 1 },
            children: { min: 0, max: 38, step: 1 },
            twin: { min: 0, max: 18, step: 1 },
            total: { min: existingMinimum, max: totalMax, step: 1 }
        },
        hasExplicitQuantitySelection: false,
        toPositiveNumber: (value, fallback) => Number(value) > 0 ? Number(value) : fallback,
        parseRuleNumber: (value, fallback) => Number.isFinite(Number(value)) ? Number(value) : fallback,
        getPartyCounts: () => ({ ...state.counts }),
        getTotalFromCounts: (counts) => counts.adults + counts.children + counts.twin,
        getPartyAdjustmentOrder: () => ['adults', 'children', 'twin'],
        clampByRule(value, rule) {
            return Math.max(rule.min, Math.min(rule.max, value))
        },
        applyCountsToUI(counts) { state.applied = { ...counts }; state.counts = { ...counts } },
        syncQuantityConstraintsToForm(counts) { state.synced = { ...counts } },
        updateSummaryPeople() { state.summaryUpdated = true }
    }
    vm.createContext(context)
    vm.runInContext(`${productionFunction}; result = forceTotalQuantityMinimum(${minimum}, 'adults')`, context)
    return { context, state }
}

const forced = execute({ adults: 12, children: 0, twin: 0 }, 38, 18)
assert.equal(forced.context.result, true)
assert.deepEqual(forced.state.applied, { adults: 18, children: 0, twin: 0 })
assert.deepEqual(forced.state.synced, { adults: 18, children: 0, twin: 0 })
assert.equal(forced.state.summaryUpdated, true)
assert.equal(forced.context.hasExplicitQuantitySelection, true)
assert.equal(forced.context.currentQuantityRules.total.min, 18)

const stricterExistingMinimum = execute({ adults: 12, children: 0, twin: 0 }, 38, 18, 20)
assert.equal(stricterExistingMinimum.context.result, true)
assert.deepEqual(stricterExistingMinimum.state.applied, { adults: 20, children: 0, twin: 0 })
assert.equal(stricterExistingMinimum.context.currentQuantityRules.total.min, 20)

const impossible = execute({ adults: 12, children: 0, twin: 0 }, 17, 18)
assert.equal(impossible.context.result, false)
assert.equal(impossible.state.applied, null)
assert.equal(impossible.state.synced, null)

const fifteenParticipantVenue = execute({ adults: 12, children: 0, twin: 0 }, 34, 15)
assert.equal(fifteenParticipantVenue.context.result, true)
assert.deepEqual(fifteenParticipantVenue.state.applied, { adults: 15, children: 0, twin: 0 })
assert.deepEqual(fifteenParticipantVenue.state.synced, { adults: 15, children: 0, twin: 0 })
assert.equal(fifteenParticipantVenue.context.currentQuantityRules.total.min, 15)

console.log('browser-force-minimum-test: OK')
