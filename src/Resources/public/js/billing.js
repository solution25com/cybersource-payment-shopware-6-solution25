document.addEventListener('DOMContentLoaded', function () {
    const checkbox = document.getElementById('differentBillingAddress');
    const billingSection = document.getElementById('billingAddressSection');
    const countrySelect = document.getElementById('billingCountry');
    const stateSection = document.getElementById('billingStateSection');
    const stateSelect = document.getElementById('billingState');
    const countryError = document.getElementById('country-error');
    const stateError = document.getElementById('state-error');

    checkbox.addEventListener('change', function () {
        billingSection.style.display = checkbox.checked ? 'block' : 'none';
    });

    fetch('/store-api/country', {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'sw-access-key': window.salesChannelAccessKey,
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            filter: [
                {
                    type: 'equals',
                    field: 'active',
                    value: true
                }
            ]
        })
    })
        .then(response => {
            if (!response.ok) {
                return response.json().then(err => {
                    throw new Error(err.errors?.[0]?.detail || `HTTP error! Status: ${response.status}`);
                });
            }
            return response.json();
        })
        .then(data => {
            if (data.elements && Array.isArray(data.elements)) {
                data.elements.forEach(country => {
                    const option = document.createElement('option');
                    option.value = country.iso;
                    option.textContent = country.name;
                    option.dataset.countryId = country.id;
                    countrySelect.appendChild(option);
                });
            } else {
                throw new Error('Invalid country data format');
            }
        })
        .catch(error => {
            console.error('Error fetching countries:', error);
            countryError.textContent = error.message.includes('Access key')
                ? 'Invalid API configuration. Please contact support.'
                : 'Failed to load countries. Please try again later.';
            countryError.style.display = 'block';
        });

    // Handle country selection to fetch states
    countrySelect.addEventListener('change', function () {
        const selectedOption = this.options[this.selectedIndex];
        const countryId = selectedOption.dataset.countryId;
        stateSelect.innerHTML = '<option value="">Select State</option>';
        stateSection.style.display = 'none';
        if(stateError) {
            stateError.style.display = 'none';
        }
        if (countryId) {
            fetch('/country/country-state-data', {
                method: 'POST',
                headers: {
                    'Accept': 'application/json',
                    'sw-access-key': window.salesChannelAccessKey,
                    'Content-Type': 'application/json'
                },
                body: JSON.stringify({
                    countryId: countryId,
                    filter: [
                        {
                            type: 'equals',
                            field: 'active',
                            value: true
                        }
                    ]
                })
            })
                .then(response => {
                    if (!response.ok) {
                        return response.json().then(err => {
                            throw new Error(err.errors?.[0]?.detail || `HTTP error! Status: ${response.status}`);
                        });
                    }
                    return response.json();
                })
                .then(data => {
                    if (data.states && data.states.length > 0) {
                        data.states.forEach(state => {
                            const option = document.createElement('option');
                            option.value = state.shortCode;
                            option.textContent = state.name;
                            stateSelect.appendChild(option);
                        });
                        stateSection.style.display = 'block';
                    }
                })
                .catch(error => {
                    console.error('Error fetching states:', error);
                    stateError.textContent = error.message.includes('Access key')
                        ? 'Invalid API configuration. Please contact support.'
                        : 'Failed to load states. Please try again later.';
                    stateError.style.display = 'block';
                });
        }
    });
});