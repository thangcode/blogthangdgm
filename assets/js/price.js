/**
 * FPTStore — Price & Region Switching
 * Handles region radio buttons and price display updates on service cards and detail pages.
 */

/* ===== Card Price Update ===== */
function updateCardPrice(radio, priceCity, priceProvince) {
    const card = radio.closest('.service-card') || radio.closest('.internet-card') ||
        radio.closest('.internet-package-card');
    if (!card) return;

    const priceElement = card.querySelector('.price-amount');
    if (!priceElement) return;

    const value = (radio.value === 'city') ? priceCity : priceProvince;
    priceElement.textContent = new Intl.NumberFormat('vi-VN').format(value);
    priceElement.classList.remove('price-bump');
    void priceElement.offsetWidth;
    priceElement.classList.add('price-bump');

    const regionSelector = radio.closest('.region-selector');
    if (regionSelector) {
        regionSelector.querySelectorAll('.region-option').forEach(label => {
            const input = label.querySelector('input[type="radio"]');
            if (input && input.checked) {
                label.classList.add('active', 'bg-primary', 'text-white');
                label.classList.remove('bg-white', 'text-dark');
            } else {
                label.classList.remove('active', 'bg-primary', 'text-white');
                label.classList.add('bg-white', 'text-dark');
            }
        });
    }
}

/* ===== Detail Page Price Update ===== */
function updateDetailPrice(radio, priceCity, priceProvince) {
    const priceElement = document.getElementById('detail-price-display')
        || document.querySelector('.price-amount')
        || document.querySelector('.text-primary.fw-bold.fs-2');

    if (priceElement) {
        const finalPrice = (radio.value === 'city') ? priceCity : priceProvince;
        priceElement.textContent = new Intl.NumberFormat('vi-VN').format(finalPrice);
        priceElement.classList.remove('price-bump');
        void priceElement.offsetWidth;
        priceElement.classList.add('price-bump');
    }

    const regionSelector = radio.closest('.region-selector') || radio.closest('.list-group');
    if (regionSelector) {
        regionSelector.querySelectorAll('label').forEach(label => {
            const input = label.querySelector('input[type="radio"]');
            if (input && input.checked) {
                label.classList.add('active');
                if (label.classList.contains('region-option')) {
                    label.classList.add('bg-primary', 'text-white');
                    label.classList.remove('bg-white', 'text-dark');
                }
            } else {
                label.classList.remove('active');
                if (label.classList.contains('region-option')) {
                    label.classList.remove('bg-primary', 'text-white');
                    label.classList.add('bg-white', 'text-dark');
                }
            }
        });
    }
}

/* ===== Init Radio Styling ===== */
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('input[type="radio"][name^="region-"]').forEach(radio => {
        if (radio.checked) {
            const label = radio.closest('label');
            if (label && !label.classList.contains('region-btn')) {
                label.classList.add('active', 'bg-primary', 'text-white');
                label.classList.remove('bg-white', 'text-dark');
            }
        }
    });
});
