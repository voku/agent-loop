(() => {
    'use strict';

    const severityButtons = Array.from(document.querySelectorAll('[data-severity-filter]'));
    const findings = Array.from(document.querySelectorAll('[data-finding]'));
    const search = document.querySelector('[data-review-search]');
    const searchable = Array.from(document.querySelectorAll('[data-searchable]'));
    const resultCount = document.querySelector('[data-result-count]');

    let severity = 'all';
    let query = '';

    const refresh = () => {
        let visible = 0;

        for (const finding of findings) {
            const severityMatch = severity === 'all' || finding.dataset.severity === severity;
            const searchMatch = query === '' || finding.textContent.toLowerCase().includes(query);
            finding.classList.toggle('hidden', !(severityMatch && searchMatch));
            if (severityMatch && searchMatch) {
                visible++;
            }
        }

        for (const item of searchable) {
            if (item.matches('[data-finding]')) {
                continue;
            }
            item.classList.toggle('hidden', query !== '' && !item.textContent.toLowerCase().includes(query));
        }

        if (resultCount !== null) {
            resultCount.textContent = `${visible} finding${visible === 1 ? '' : 's'} visible`;
        }
    };

    for (const button of severityButtons) {
        button.addEventListener('click', () => {
            severity = button.dataset.severityFilter || 'all';
            for (const candidate of severityButtons) {
                candidate.setAttribute('aria-pressed', candidate === button ? 'true' : 'false');
            }
            refresh();
        });
    }

    if (search !== null) {
        search.addEventListener('input', () => {
            query = search.value.trim().toLowerCase();
            refresh();
        });
    }

    for (const button of document.querySelectorAll('[data-details-action]')) {
        button.addEventListener('click', () => {
            const shouldOpen = button.dataset.detailsAction === 'open';
            for (const detail of document.querySelectorAll('details')) {
                detail.open = shouldOpen;
            }
        });
    }

    refresh();
})();
