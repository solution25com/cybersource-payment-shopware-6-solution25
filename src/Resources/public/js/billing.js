document.addEventListener('DOMContentLoaded', function () {
    const countrySelect = document.getElementById('billingCountry');
    const stateSection = document.getElementById('billingStateSection');
    const stateSelect = document.getElementById('billingState');
    const countryError = document.getElementById('country-error');
    const stateError = document.getElementById('state-error');
    const billingCountryDefault = document.getElementById('billingCountryDefault');
    const billingCountryStateDefault = document.getElementById('billingCountryStateDefault');
    const jsDataDiv = document.querySelector('[data-jsData]');
    let salesChannelAccessKey = '';
    if (jsDataDiv && jsDataDiv.dataset.jsdata) {
        try {
            const jsData = JSON.parse(jsDataDiv.dataset.jsdata);
            salesChannelAccessKey = jsData.salesChannelAccessKey || '';
        } catch (e) {
            console.error('Failed to parse data-jsData:', e);
        }
    } else {
        console.warn('No data-jsData attribute found');
    }

    fetch('/store-api/country', {
        method: 'POST',
        headers: {
            'Accept': 'application/json',
            'sw-access-key': salesChannelAccessKey,
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
                let selectedOption = null;
                data.elements.forEach(country => {
                    const option = document.createElement('option');
                    option.value = country.iso;
                    option.textContent = country.name;
                    option.dataset.countryId = country.id;
                    if (billingCountryDefault && country.id === billingCountryDefault.value) {
                        option.selected = true;
                        selectedOption = option;
                    }
                    countrySelect.appendChild(option);
                });
                if (selectedOption) {
                    countrySelect.value = selectedOption.value;
                    countrySelectChanged(countrySelect);
                }

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
    countrySelect.addEventListener('change', countrySelectChanged);

    function countrySelectChanged(e) {
        const selectedOption = countrySelect.options[countrySelect.selectedIndex];
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
                            if (billingCountryStateDefault && state.id === billingCountryStateDefault.value) {
                                option.selected = true;
                            }
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
    }
});