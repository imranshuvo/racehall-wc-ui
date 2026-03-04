document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('.accordion-header').forEach(header => {
        header.addEventListener('click', () => {
            header.parentElement.classList.toggle('active')
        })
    })
})
function updateCount(type, delta) {
    const el = document.getElementById(type)
    if (!el) return
    let value = parseInt(el.textContent, 10)
    value = Math.max(0, value + delta)
    el.textContent = value
    updateSummaryPeople()
}

function getPartyCounts() {
    const adultsEl = document.getElementById('adult-1')
    const childrenEl = document.getElementById('child-1')
    const adults = Math.max(0, parseInt(adultsEl ? adultsEl.textContent : '1', 10) || 0)
    const children = Math.max(0, parseInt(childrenEl ? childrenEl.textContent : '0', 10) || 0)
    return { adults, children }
}

function getTotalQuantity() {
    const counts = getPartyCounts()
    return Math.max(1, counts.adults + counts.children)
}

function updateSummaryPeople() {
    const counts = getPartyCounts()
    const total = getTotalQuantity()

    const peopleEl = document.getElementById('summary-people')
    if (peopleEl) {
        peopleEl.innerHTML = `${counts.adults} voksne<br>${counts.children} børn`
    }

    const kartsEl = document.getElementById('summary-karts')
    if (kartsEl) {
        kartsEl.innerHTML = `${counts.adults} voksen karts<br>${counts.children} børne kart`
    }

    const adultsInput = document.getElementById('booking_adults')
    const childrenInput = document.getElementById('booking_children')
    const quantityInput = document.getElementById('booking_quantity')
    const cartQuantityInput = document.getElementById('cart_quantity')
    if (adultsInput) adultsInput.value = String(counts.adults)
    if (childrenInput) childrenInput.value = String(counts.children)
    if (quantityInput) quantityInput.value = String(total)
    if (cartQuantityInput) cartQuantityInput.value = String(total)
}

// --- Calendar and Timeslot Logic ---

const monthNames = [
    "Januar", "Februar", "Mars", "April", "Mai", "Juni",
    "Juli", "August", "September", "Oktober", "November", "Desember"
]

let today = new Date()
let todayStart = new Date(today.getFullYear(), today.getMonth(), today.getDate())
let selectedDate = new Date(todayStart)
let currentMonth = today.getMonth()
let currentYear = today.getFullYear()
let availabilityMap = {}


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

function findSummaryLabelByTitle(titleText) {
    const sections = document.querySelectorAll('.summary-section')
    for (const sec of sections) {
        const h4 = sec.querySelector('h4')
        if (h4 && h4.textContent.trim().toLowerCase().includes(titleText.toLowerCase())) {
            return sec.querySelector('.summary-label')
        }
    }
    return null
}

function updateSummaryDate(date) {
    const label = findSummaryLabelByTitle('Dato')
    if (!label) return
    if (date && !isPastDate(date)) {
        label.textContent = formatDateShort(date)
        // 🔥 SAVE TO CART FORM
        const input = document.getElementById('booking_date')
        if (input) input.value = formatDateShort(date)
    } else {
        label.textContent = ''
    }
}

function updateSummaryTime(timeStr) {
    const label = findSummaryLabelByTitle('Tidspunkt')
    if (!label) return
    label.textContent = timeStr || ''

    // 🔥 SAVE TO CART FORM
    const input = document.getElementById('booking_time')
    if (input) input.value = timeStr || ''
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
    // Show spinner in calendar UI
    const daysContainer = document.getElementById('calendarDays')
    if (daysContainer) {
        daysContainer.innerHTML = `<span class="calendar-loading"><div class="spinner"></div></span>`
    }
    if (!window.RH_PRODUCT_ID) return {}
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
            showCalendarError('Fejl i kalenderdata. Prøv at genindlæse siden.')
            return {}
        }
        availabilityMap = {}
        if (data.activities) {
            data.activities.forEach(a => {
                availabilityMap[a.date.split('T')[0]] = a.status
            })
        }
        return availabilityMap
    } catch (err) {
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
        const isBooked = typeof status !== 'undefined' && status !== 0
        if (isBooked || isPastDate(dateCur)) {
            span.className = 'muted disabled'
            span.style.cursor = 'not-allowed'
            span.style.color = isPastDate(dateCur) ? '#999999' : '#555555'
            span.title = isBooked ? (status === 1 ? 'Fully booked' : 'Unavailable') : 'Unavailable'
        } else {
            // Available date
            span.style.color = '#FFF'
            span.style.cursor = 'pointer'
            span.addEventListener('click', function () {
                selectedDate = new Date(year, month, d)
                renderCalendar(currentMonth, currentYear)
                updateSummaryDate(selectedDate)
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
    if (!window.RH_PRODUCT_ID) return
    const container = document.querySelector('.time-slots')
    if (container) {
        container.innerHTML = '<span class="loading"><div class="spinner"></div></span>'
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
            container.innerHTML = `<span class="calendar-error">${data.message || 'Ingen tider tilgængelige.'}</span>`
            return
        }
        if (data.proposals && data.proposals.length) {
            data.proposals.forEach(proposal => {
                proposal.blocks.forEach(block => {
                    const slot = block.block
                    const resourceId = slot.resourceId || (block.productLineIds && block.productLineIds[0]) || ''
                    const start = slot.start ? slot.start.substring(11, 16) : ''
                    const stop = slot.stop ? slot.stop.substring(11, 16) : ''
                    const btn = document.createElement('button')
                    btn.className = 'time-slot'
                    btn.textContent = `${start} - ${stop}`
                    btn.setAttribute('data-time', `${start} - ${stop}`)

                    // Disable button if slot == 0
                    if (slot.slot === 0) {
                        btn.disabled = true
                        btn.classList.add('disabled')
                    } else {
                        btn.addEventListener('click', async function () {
                            container.querySelectorAll('.time-slot').forEach(s => s.classList.remove('selected'))
                            this.classList.add('selected')
                            updateSummaryTime(this.getAttribute('data-time'))



                            // Show spinner while saving proposal
                            // container.innerHTML = `<span class="calendar-loading"><div class="spinner"></div></span>`

                            // Await the saveProposalToSession call
                            await saveProposalToSession(proposal, resourceId, productId)

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
            })
        } else {
            container.innerHTML = '<span style="color:#fff">Ingen ledige tider denne dag.</span>'
        }
    } catch (err) {
        if (container) container.innerHTML = '<span class="calendar-error" style="color:#fff">Netværksfejl ved hentning af tider.</span>'
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
    await fetchAvailabilityForMonth(currentMonth, currentYear)
    renderCalendar(currentMonth, currentYear)
    updateMonthNav()
    updateSummaryDate(selectedDate)
    if (selectedDate) {
        const dateKey = `${selectedDate.getFullYear()}-${String(selectedDate.getMonth() + 1).padStart(2, '0')}-${String(selectedDate.getDate()).padStart(2, '0')}`
        fetchAndRenderTimeslots(dateKey)
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
    document.addEventListener('DOMContentLoaded', updateSummaryPeople)
} else {
    updateSummaryPeople()
}


async function saveProposalToSession(block, resourceId, productId) {
    console.log('Saving proposal to session:', block)
    if (!window.my_ajax_object) return
    try {
        const res = await fetch(window.my_ajax_object.ajax_url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                action: 'rh_save_proposal',
                proposal: JSON.stringify(block),
                resourceId: resourceId || '',
                productId: productId || '',
                quantity: String(getTotalQuantity()),
                bookingLocation: getBookingLocation(),
                nonce: window.my_ajax_object.nonce || ''
            })
        })
        const responseText = await res.text()
        const result = JSON.parse(responseText)
        console.log('Save proposal response text:', responseText)
        console.log('Save proposal response status:', result)
        if (!result.success) {
            alert(result.errorMessage || 'Fejl ved lagring af booking. Prøv igen.')
        }
        else {
            // here result.supplements is on add ons section id this addonSection
            console.log('Supplements to add:', result.supplements)
            const addonSection = document.getElementById('addonSection')
            if (addonSection) {
                addonSection.innerHTML = '' // Clear existing content
                result.supplements.forEach(supplement => {
                    const div = document.createElement('div')
                    div.className = 'addon'
                    div.innerHTML = `
                        <div style="display:flex; justify-content:space-between; align-items:center; ">
                            <h4>${supplement.product.name}</h4>
                            <span class="price">${supplement.product.price[0].amount}</span>
                        </div>
                    `
                    addonSection.appendChild(div)
                })
            }
        }
    } catch (e) {
        // Non-critical — booking will still work if session already has data
        console.warn('Could not save proposal to session:', e)
    }
}