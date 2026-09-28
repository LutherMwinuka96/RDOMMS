/** Comma thousands separator: 200,000,000 (always en-US, not browser locale). */
(function (global) {
    if (typeof global.formatRdommsNumber === 'function') {
        return;
    }

    function formatRdommsNumber(value, decimals = 0) {
        const num = Number(value);
        if (!Number.isFinite(num)) {
            return decimals > 0 ? `0.${'0'.repeat(decimals)}` : '0';
        }
        return num.toLocaleString('en-US', {
            minimumFractionDigits: decimals,
            maximumFractionDigits: decimals,
        });
    }

    function formatRdommsCurrency(value, currency = 'TSH', decimals = 0) {
        return `${currency} ${formatRdommsNumber(value, decimals)}`;
    }

    /** HTML: coin icon + TSH 2,300 (comma thousands, no M/K abbreviation). */
    function formatRdommsCurrencyHtml(value, decimals = 0, currency = 'TSH') {
        const amount = `${currency} ${formatRdommsNumber(value, decimals)}`;
        return `<span class="rdomms-currency" title="Tanzanian Shilling"><i class="fas fa-coins" aria-hidden="true"></i><span>${amount}</span></span>`;
    }

    function setRdommsCurrencyElement(el, value, decimals = 0) {
        if (!el) return;
        el.innerHTML = formatRdommsCurrencyHtml(value, decimals);
    }

    global.formatRdommsNumber = formatRdommsNumber;
    global.formatRdommsCurrency = formatRdommsCurrency;
    global.formatRdommsCurrencyHtml = formatRdommsCurrencyHtml;
    global.setRdommsCurrencyElement = setRdommsCurrencyElement;
})(window);
