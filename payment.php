<section class="section payment-section" id="payment" <?php echo $cart === [] ? 'hidden' : ''; ?>>
    <div class="section-heading"><h2>Payment</h2></div>
    <p class="customer-name"><strong>Customer:</strong> <?php echo htmlspecialchars($customerName, ENT_QUOTES, 'UTF-8'); ?></p>
    <?php if ($paymentMessage !== ''): ?>
        <p class="payment-message"><?php echo $paymentMessage; ?></p>
    <?php endif; ?>
    <?php if ($cart === []): ?>
        <p class="empty-cart">Your cart is empty. Add bread to see the quantity and total here.</p>
    <?php else: ?>
        <table class="cart-table">
            <thead><tr><th>Bread</th><th>Quantity</th><th>Subtotal</th></tr></thead>
            <tbody>
                <?php foreach ($cart as $product => $quantity): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($product); ?></td>
                        <td><span class="quantity-controls"><button class="quantity-btn" type="button" data-product="<?php echo htmlspecialchars($product); ?>" data-change="-1" aria-label="Remove one <?php echo htmlspecialchars($product); ?>">-</button><strong><?php echo $quantity; ?></strong><button class="quantity-btn" type="button" data-product="<?php echo htmlspecialchars($product); ?>" data-change="1" aria-label="Add one <?php echo htmlspecialchars($product); ?>">+</button></span></td>
                        <td>₱<?php echo number_format($products[$product] * $quantity, 2); ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <p class="cart-total">Subtotal: ₱<?php echo number_format($cartTotal, 2); ?><br>Delivery fee: ₱<?php echo number_format($deliveryFee, 2); ?><br><strong>Total: ₱<?php echo number_format($cartTotal + $deliveryFee, 2); ?></strong></p>
    <?php endif; ?>


    <form class="payment-form" method="POST" enctype="multipart/form-data">
        <input type="hidden" name="cart_csrf_token" value="<?php echo htmlspecialchars($cartCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
        <div class="payment-field payment-methods-row">
            <label>Payment method</label>
            <div class="payment-options" id="payment_options">
                <button type="button" class="payment-option" data-value="Cash on Delivery">Cash on Delivery</button>
                <button type="button" class="payment-option" data-value="GCash">GCash</button>
                <button type="button" class="payment-option" data-value="Maya">Maya</button>
            </div>
            <input type="hidden" id="payment_method" name="payment_method" value="" required>
        </div>

        <div class="gcash-details" id="gcash-details">
            <div class="payment-instructions">
                <strong id="payment_title">Pay through GCash</strong>
                <p class="gcash-number-label" id="payment_number">09515773435</p>
                <p class="gcash-note" id="payment_note">Send your payment to this number, then place your order.</p>
            </div>
        </div>
        <div class="payment-field" id="payment_proof_wrapper" hidden>
            <label for="payment_proof">Upload payment proof</label>
            <input id="payment_proof" name="payment_proof" type="file" accept="image/jpeg,image/png,image/webp">
            <p class="gcash-note">Upload a JPG, PNG, or WebP image up to 2 MB. Required for GCash and Maya.</p>
        </div>

        <div class="payment-field" id="customer_address_wrapper" style="display:none;">
            <h3 class="address-title">Delivery address</h3>
            <div class="address-fields">
                <div class="payment-field"><label for="delivery_recipient">Recipient name</label><input id="delivery_recipient" name="delivery_recipient" type="text" maxlength="100" autocomplete="name"></div>
                <div class="payment-field"><label for="delivery_contact_number">Contact number</label><input id="delivery_contact_number" name="delivery_contact_number" type="tel" inputmode="numeric" pattern="[0-9]{11}" minlength="11" maxlength="11" placeholder="11-digit mobile number" autocomplete="tel"></div>
                <div class="payment-field"><label for="customer_region">Region</label><select id="customer_region" name="customer_region" disabled><option value="">Loading regions...</option></select></div>
                <div class="payment-field" id="province_wrapper" style="display:none;"><label for="customer_province">Province</label><select id="customer_province" name="customer_province" disabled><option value="">Select province</option></select></div>
                <div class="payment-field" id="city_wrapper" style="display:none;"><label for="customer_city">City/Municipality</label><select id="customer_city" name="customer_city" disabled><option value="">Select city or municipality</option></select></div>
                <div class="payment-field" id="barangay_wrapper" style="display:none;"><label for="customer_barangay">Barangay</label><select id="customer_barangay" name="customer_barangay" disabled><option value="">Select barangay</option></select></div>
                <div class="payment-field address-location-summary" id="location_record" hidden><label>Complete location</label><output id="location_record_value"></output></div>
                <div class="payment-field address-street"><label for="customer_street_address">Street Name, Building, House No.</label><input id="customer_street_address" name="customer_street_address" type="text" maxlength="300" placeholder="Enter your street, building, or house number" autocomplete="street-address" disabled></div>
                <div class="payment-field address-street"><label for="customer_location_description">Location details / landmark (optional)</label><textarea id="customer_location_description" name="customer_location_description" rows="2" maxlength="300" placeholder="For example: near the school, beside the store, or gate color"></textarea></div>
            </div>
            <p id="address_error" class="address-error" role="status" aria-live="polite" hidden></p>
        </div>

        <button class="pay-btn place-order-btn" type="submit" name="place_order" <?php echo $cart === [] ? 'disabled' : ''; ?>>Place Order</button>
    </form>
</section>
<script>
    const paymentMethod = document.getElementById('payment_method');
    const paymentOptions = document.querySelectorAll('.payment-option');
    const gcashDetails = document.getElementById('gcash-details');
    const paymentTitle = document.getElementById('payment_title');
    const paymentNumber = document.getElementById('payment_number');
    const paymentNote = document.getElementById('payment_note');
    const paymentProofWrapper = document.getElementById('payment_proof_wrapper');
    const paymentProofInput = document.getElementById('payment_proof');
    const paymentNumbers = {
        GCash: '09515773435',
        Maya: '09515773435'
    };
    const addressWrapper = document.getElementById('customer_address_wrapper');
    const addressFields = [
        document.getElementById('delivery_recipient'),
        document.getElementById('delivery_contact_number'),
        document.getElementById('customer_region'),
        document.getElementById('customer_city'),
        document.getElementById('customer_barangay'),
        document.getElementById('customer_street_address'),
    ];
    const provinceWrapper = document.getElementById('province_wrapper');
    const cityWrapper = document.getElementById('city_wrapper');
    const barangayWrapper = document.getElementById('barangay_wrapper');
    const regionSelect = document.getElementById('customer_region');
    const provinceSelect = document.getElementById('customer_province');
    const citySelect = document.getElementById('customer_city');
    const barangaySelect = document.getElementById('customer_barangay');
    const locationRecord = document.getElementById('location_record');
    const locationRecordValue = document.getElementById('location_record_value');
    const streetAddressInput = document.getElementById('customer_street_address');
    const addressError = document.getElementById('address_error');
    const paymentForm = document.querySelector('.payment-form');
    const addressApiBase = 'https://psgc.gitlab.io/api';
    let addressRequestId = 0;

    function resetAddressSelect(select, placeholder) {
        select.replaceChildren(new Option(placeholder, ''));
        select.value = '';
        select.disabled = true;
    }

    async function fetchAddressList(path) {
        const response = await fetch(`${addressApiBase}${path}`);
        if (!response.ok) {
            throw new Error(`Location list request failed (${response.status}).`);
        }

        const locations = await response.json();
        if (!Array.isArray(locations)) {
            throw new Error('The location service returned an invalid list.');
        }

        return locations;
    }

    function populateAddressSelect(locations, select, placeholder) {
        select.replaceChildren(new Option(placeholder, ''));
        locations.sort((left, right) => left.name.localeCompare(right.name));
        locations.forEach((location) => {
            const option = new Option(location.name, location.name);
            option.dataset.code = location.code;
            select.add(option);
        });
        select.disabled = locations.length === 0;
    }

    async function fetchAddressOptions(path, select, placeholder) {
        const locations = await fetchAddressList(path);
        populateAddressSelect(locations, select, placeholder);
        return locations;
    }

    function showAddressError(error) {
        addressError.textContent = `Could not load address options. Check your internet connection and try again. (${error.message})`;
        addressError.hidden = false;
    }

    function clearAddressError() {
        addressError.textContent = '';
        addressError.hidden = true;
    }

    function selectedLocationCode(select) {
        return select.selectedOptions[0]?.dataset.code || '';
    }

    function selectedRegionCodes() {
        const codes = regionSelect.selectedOptions[0]?.dataset.codes;
        return codes ? JSON.parse(codes) : [];
    }

    function clearLocationRecord() {
        locationRecord.hidden = true;
        locationRecordValue.textContent = '';
    }

    function clearStreetAddress() {
        streetAddressInput.value = '';
        streetAddressInput.disabled = paymentMethod.value === '';
    }

    async function loadRegions() {
        resetAddressSelect(regionSelect, 'Select region');
        try {
            const officialRegions = await fetchAddressList('/regions/');
            const regionGroups = [
                { name: 'Metro Manila', pattern: /national capital region|\bncr\b/i },
                { name: 'Mindanao', pattern: /mindanao|zamboanga|davao|soccsksargen|caraga|bangsamoro|barmm/i },
                { name: 'North Luzon', pattern: /cordillera|\bcar\b|ilocos|cagayan valley|central luzon/i },
                { name: 'South Luzon', pattern: /calabarzon|mimaropa|bicol/i },
                { name: 'Visayas', pattern: /visayas|negros island/i },
            ];
            const groupedRegions = regionGroups.map((group) => ({
                name: group.name,
                codes: officialRegions.filter((region) => group.pattern.test(region.name)).map((region) => region.code),
            }));
            const unmatchedRegions = officialRegions.filter((region) =>
                !groupedRegions.some((group) => group.codes.includes(region.code))
            );
            if (officialRegions.length === 0 || unmatchedRegions.length > 0 || groupedRegions.some((group) => group.codes.length === 0)) {
                throw new Error('The location service returned regions that could not be grouped.');
            }

            regionSelect.replaceChildren(new Option('Select region', ''));
            groupedRegions.forEach((group) => {
                const option = new Option(group.name, group.name);
                option.dataset.codes = JSON.stringify(group.codes);
                regionSelect.add(option);
            });
            regionSelect.disabled = false;
            clearAddressError();
        } catch (error) {
            showAddressError(error);
        }
    }

    regionSelect.addEventListener('change', async () => {
        const requestId = ++addressRequestId;
        resetAddressSelect(provinceSelect, 'Select province');
        resetAddressSelect(citySelect, 'Select city or municipality');
        resetAddressSelect(barangaySelect, 'Select barangay');
        provinceWrapper.style.display = 'none';
        cityWrapper.style.display = 'none';
        barangayWrapper.style.display = 'none';
        clearLocationRecord();
        clearStreetAddress();
        provinceSelect.required = false;
        clearAddressError();

        const regionCodes = selectedRegionCodes();
        if (regionCodes.length === 0) {
            return;
        }

        try {
            const provinceLists = await Promise.all(regionCodes.map((regionCode) =>
                fetchAddressList(`/regions/${regionCode}/provinces/`)
            ));
            const provinces = provinceLists.flat();
            if (requestId !== addressRequestId) return;

            if (provinces.length > 0) {
                populateAddressSelect(provinces, provinceSelect, 'Select province');
                provinceWrapper.style.display = 'block';
                provinceSelect.required = paymentMethod.value !== '';
                provinceSelect.focus();
            } else {
                const cityLists = await Promise.all(regionCodes.map((regionCode) =>
                    fetchAddressList(`/regions/${regionCode}/cities-municipalities/`)
                ));
                const cities = cityLists.flat();
                if (requestId !== addressRequestId) return;
                if (cities.length === 0) throw new Error('No cities or municipalities were returned for this region.');
                populateAddressSelect(cities, citySelect, 'Select city or municipality');
                cityWrapper.style.display = 'block';
                citySelect.focus();
                clearAddressError();
            }
        } catch (error) {
            if (requestId === addressRequestId) showAddressError(error);
        }
    });

    provinceSelect.addEventListener('change', async () => {
        const requestId = ++addressRequestId;
        resetAddressSelect(citySelect, 'Select city or municipality');
        resetAddressSelect(barangaySelect, 'Select barangay');
        cityWrapper.style.display = 'none';
        barangayWrapper.style.display = 'none';
        clearLocationRecord();
        clearStreetAddress();
        clearAddressError();
        const provinceCode = selectedLocationCode(provinceSelect);
        if (!provinceCode) return;

        try {
            const cities = await fetchAddressOptions(`/provinces/${provinceCode}/cities-municipalities/`, citySelect, 'Select city or municipality');
            if (requestId !== addressRequestId) return;
            if (cities.length === 0) throw new Error('No cities or municipalities were returned for this province.');
            cityWrapper.style.display = 'block';
            citySelect.focus();
            clearAddressError();
        } catch (error) {
            if (requestId === addressRequestId) showAddressError(error);
        }
    });

    citySelect.addEventListener('change', async () => {
        const requestId = ++addressRequestId;
        resetAddressSelect(barangaySelect, 'Select barangay');
        barangayWrapper.style.display = 'none';
        clearLocationRecord();
        clearStreetAddress();
        clearAddressError();
        const cityCode = selectedLocationCode(citySelect);
        if (!cityCode) return;

        try {
            const barangays = await fetchAddressOptions(`/cities-municipalities/${cityCode}/barangays/`, barangaySelect, 'Select barangay');
            if (requestId !== addressRequestId) return;
            if (barangays.length === 0) throw new Error('No barangays were returned for this city or municipality.');
            barangayWrapper.style.display = 'block';
            barangaySelect.focus();
            clearAddressError();
        } catch (error) {
            if (requestId === addressRequestId) showAddressError(error);
        }
    });

    barangaySelect.addEventListener('change', () => {
        clearLocationRecord();
        clearStreetAddress();
        if (!regionSelect.value || !citySelect.value || !barangaySelect.value) return;
        if (provinceWrapper.style.display !== 'none' && !provinceSelect.value) return;

        const locationParts = [
            regionSelect.value,
            provinceSelect.value,
            citySelect.value,
            barangaySelect.value,
        ].filter(Boolean);
        locationRecordValue.textContent = locationParts.join(', ');
        locationRecord.hidden = false;
        streetAddressInput.focus();
    });

    document.getElementById('delivery_contact_number').addEventListener('input', (event) => {
        event.currentTarget.value = event.currentTarget.value.replace(/\D/g, '').slice(0, 11);
    });

    paymentForm.addEventListener('submit', (event) => {
        if (paymentMethod.value === '') return;
        if (!addressError.hidden || regionSelect.disabled || citySelect.disabled || barangaySelect.disabled) {
            event.preventDefault();
            addressError.textContent = 'Complete the region, city/municipality, and barangay selections before placing your order.';
            addressError.hidden = false;
        }
    });

    function setSelectedOption(value) {
        paymentOptions.forEach((button) => {
            const isActive = button.dataset.value === value;
            button.classList.toggle('active', isActive);
        });
        paymentMethod.value = value;
        updatePaymentDetails();
    }

    paymentOptions.forEach((button) => {
        button.addEventListener('click', () => setSelectedOption(button.dataset.value));
    });

    function updatePaymentDetails() {
        const selected = paymentMethod.value;
        const isDigital = selected === 'GCash' || selected === 'Maya';
        const isCashOnDelivery = selected === 'Cash on Delivery';

        gcashDetails.classList.toggle('visible', isDigital || isCashOnDelivery);
        paymentNumber.hidden = !isDigital;
        paymentProofWrapper.hidden = !isDigital;
        paymentProofInput.required = isDigital;
        if (!isDigital) {
            paymentProofInput.value = '';
        }

        if (selected === 'Maya') {
            paymentTitle.textContent = 'Pay through Maya';
            paymentNumber.textContent = paymentNumbers.Maya;
            paymentNote.textContent = 'Send your payment to this Maya number, then place your order.';
        } else if (selected === 'GCash') {
            paymentTitle.textContent = 'Pay through GCash';
            paymentNumber.textContent = paymentNumbers.GCash;
            paymentNote.textContent = 'Send your payment to this GCash number, then place your order.';
        } else if (selected === 'Cash on Delivery') {
            paymentTitle.textContent = 'Cash on Delivery';
            paymentNumber.textContent = '';
            paymentNote.textContent = 'Your delivery contact number will be used so the rider can reach you.';
        } else {
            gcashDetails.classList.remove('visible');
            paymentTitle.textContent = 'Pay through GCash';
            paymentNumber.textContent = paymentNumbers.GCash;
            paymentNote.textContent = 'Send your payment to this number, then place your order.';
        }

        const showAddress = selected !== '';
        addressWrapper.style.display = showAddress ? 'block' : 'none';
        addressFields.forEach((field) => {
            field.required = showAddress;
        });
        streetAddressInput.disabled = !showAddress;
        provinceSelect.required = showAddress && provinceWrapper.style.display !== 'none';
    }

    loadRegions();
    updatePaymentDetails();
</script>
