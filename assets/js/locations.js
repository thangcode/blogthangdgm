/**
 * Vietnam Provinces & Districts Data
 * Idempotent script with Dynamic API Integration (CASSO AddressKit)
 * Fallback to hardcoded 2024-2025 mergers data if API is unavailable.
 */

(function (window) {
    if (window.VN_LOCATIONS_LOADED) return;

    // --- Configuration & Fallback Data ---
    const PROXY_URL = (window.BASE_URL || "") + "api/address-proxy.php";
    const CACHE_KEY_PROVINCES = "vn_locations_provinces_v2"; // Versioned to clear old cache
    const CACHE_KEY_COMMUNES_PREFIX = "vn_locations_communes_v2_";
    const CACHE_EXPIRY = 24 * 60 * 60 * 1000; // 24 hours

    window.VN_PROVINCES_HARDCODED = [
        "Hà Nội", "TP. Hồ Chí Minh", "Đà Nẵng", "Hải Phòng", "Cần Thơ",
        "An Giang", "Bà Rịa - Vũng Tàu", "Bắc Giang", "Bắc Kạn", "Bạc Liêu",
        "Bắc Ninh", "Bến Tre", "Bình Định", "Bình Dương", "Bình Phước",
        "Bình Thuận", "Cà Mau", "Cao Bằng", "Đắk Lắk", "Đắk Nông",
        "Điện Biên", "Đồng Nai", "Đồng Tháp", "Gia Lai", "Hà Giang",
        "Hà Nam", "Hà Tĩnh", "Hải Dương", "Hậu Giang", "Hòa Bình",
        "Hưng Yên", "Khánh Hòa", "Kiên Giang", "Kon Tum", "Lai Châu",
        "Lâm Đồng", "Lạng Sơn", "Lào Cai", "Long An", "Nam Định",
        "Nghệ An", "Ninh Bình", "Ninh Thuận", "Phú Thọ", "Phú Yên",
        "Quảng Bình", "Quảng Nam", "Quảng Ngãi", "Quảng Ninh", "Quảng Trị",
        "Sóc Trăng", "Sơn La", "Tây Ninh", "Thái Bình", "Thái Nguyên",
        "Thanh Hóa", "Thừa Thiên Huế", "Tiền Giang", "Trà Vinh", "Tuyên Quang",
        "Vĩnh Long", "Vĩnh Phúc", "Yên Bái"
    ];

    window.VN_DISTRICTS_FALLBACK = {
        'Hà Nội': ['Ba Đình', 'Hoàn Kiếm', 'Hai Bà Trưng', 'Đống Đa', 'Cầu Giấy', 'Thanh Xuân', 'Hoàng Mai', 'Long Biên', 'Hà Đông', 'Bắc Từ Liêm', 'Nam Từ Liêm', 'Tây Hồ', 'Sơn Tây', 'Ba Vì', 'Chương Mỹ', 'Phúc Thọ', 'Đan Phượng', 'Đông Anh', 'Gia Lâm', 'Hoài Đức', 'Mê Linh', 'Mỹ Đức', 'Phú Xuyên', 'Quốc Oai', 'Sóc Sơn', 'Thạch Thất', 'Thanh Oai', 'Thanh Trì', 'Thường Tín', 'Ứng Hòa'],
        'TP. Hồ Chí Minh': ['Thành phố Thủ Đức', 'Quận 1', 'Quận 3', 'Quận 4', 'Quận 5', 'Quận 6', 'Quận 7', 'Quận 8', 'Quận 10', 'Quận 11', 'Quận 12', 'Bình Thạnh', 'Gò Vấp', 'Phú Nhuận', 'Tân Bình', 'Tân Phú', 'Bình Tân', 'Bình Chánh', 'Cần Giờ', 'Củ Chi', 'Hóc Môn', 'Nhà Bè'],
        'Đà Nẵng': ['Hải Châu', 'Thanh Khê', 'Sơn Trà', 'Ngũ Hành Sơn', 'Liên Chiểu', 'Cẩm Lệ', 'Hòa Vang', 'Hoàng Sa'],
        'Hải Phòng': ['Hồng Bàng', 'Ngô Quyền', 'Lê Chân', 'Hải An', 'Kiến An', 'Đồ Sơn', 'Dương Kinh', 'An Dương', 'An Lão', 'Bạch Long Vĩ', 'Cát Hải', 'Kiến Thụy', 'Thủy Nguyên', 'Tiên Lãng', 'Vĩnh Bảo'],
        'Cần Thơ': ['Ninh Kiều', 'Bình Thuỷ', 'Cái Răng', 'Ô Môn', 'Thốt Nốt', 'Thới Lai', 'Cờ Đỏ', 'Phong Điền', 'Vĩnh Thạnh']
    };

    // --- Helpers ---
    async function fetchData(url) {
        try {
            const response = await fetch(url);
            if (!response.ok) return null;
            return await response.json();
        } catch (error) {
            console.error("CASSO API Error:", error);
            return null;
        }
    }

    function getFromCache(key) {
        try {
            const cached = localStorage.getItem(key);
            if (!cached) return null;
            const data = JSON.parse(cached);
            if (Date.now() > data.expiry) {
                localStorage.removeItem(key);
                return null;
            }
            return data.val;
        } catch (e) { return null; }
    }

    function setToCache(key, val) {
        try {
            localStorage.setItem(key, JSON.stringify({
                val: val,
                expiry: Date.now() + CACHE_EXPIRY
            }));
        } catch (e) { }
    }

    function extractArray(data, keyHint) {
        if (!data) return null;
        if (Array.isArray(data)) return data;
        if (data[keyHint] && Array.isArray(data[keyHint])) return data[keyHint];
        if (data.data && Array.isArray(data.data)) return data.data;
        // Search for any array property
        for (let key in data) {
            if (Array.isArray(data[key])) return data[key];
        }
        return null;
    }

    // --- Main Functions ---

    /**
     * Populate a select element with Vietnam provinces from API
     */
    window.initProvinceSelect = async function (selectId, placeholder = "Chọn tỉnh/thành phố *") {
        const select = document.getElementById(selectId);
        if (!select) return;

        select.innerHTML = `<option value="" selected disabled>${placeholder}</option>`;

        let provinces = getFromCache(CACHE_KEY_PROVINCES);
        if (!provinces) {
            const rawData = await fetchData(`${PROXY_URL}?action=provinces`);
            provinces = extractArray(rawData, 'provinces');
            if (provinces) setToCache(CACHE_KEY_PROVINCES, provinces);
        }

        if (provinces && Array.isArray(provinces)) {
            provinces.forEach(p => {
                const option = document.createElement('option');
                option.value = p.name || p.name_vi;
                option.dataset.code = p.code;
                option.textContent = p.name || p.name_vi;
                select.appendChild(option);
            });
        } else {
            window.VN_PROVINCES_HARDCODED.forEach(province => {
                const option = document.createElement('option');
                option.value = province;
                option.textContent = province;
                select.appendChild(option);
            });
        }
    };

    /**
     * Update districts based on selected province code from API
     */
    window.updateDistrictSelect = async function (provinceInput, districtSelectId) {
        const districtSelect = document.getElementById(districtSelectId);
        if (!districtSelect) return;

        districtSelect.innerHTML = '<option value="">Đang tải...</option>';

        let provinceValue = "";
        let provinceCode = null;

        if (typeof provinceInput === 'string') {
            provinceValue = provinceInput;
            const selects = Array.from(document.querySelectorAll('select'));
            const sourceSelect = selects.find(s => s.value === provinceValue && s.id !== districtSelectId);
            provinceCode = sourceSelect?.selectedOptions[0]?.dataset?.code;
        } else {
            provinceValue = provinceInput.value;
            provinceCode = provinceInput.selectedOptions[0]?.dataset?.code;
        }

        let districts = null;

        if (provinceCode) {
            const cacheKey = CACHE_KEY_COMMUNES_PREFIX + provinceCode;
            districts = getFromCache(cacheKey);
            if (!districts) {
                const rawData = await fetchData(`${PROXY_URL}?action=communes&provinceId=${provinceCode}`);
                const communeArray = extractArray(rawData, 'communes');
                if (communeArray) {
                    districts = communeArray.map(i => i.name || i.name_vi).filter(n => !!n).sort();
                    setToCache(cacheKey, districts);
                }
            }
        }

        if (!districts || districts.length === 0) {
            districts = window.VN_DISTRICTS_FALLBACK[provinceValue] || ['Khác'];
        }

        districtSelect.innerHTML = '<option value="">Chọn quận/huyện *</option>';
        districts.forEach(d => {
            const opt = document.createElement('option');
            opt.value = d;
            opt.textContent = d;
            districtSelect.appendChild(opt);
        });
    };

    window.VN_LOCATIONS_LOADED = true;
})(window);
