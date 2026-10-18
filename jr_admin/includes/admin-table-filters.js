(() => {
    const tables = document.querySelectorAll('table.admin-filterable');

    tables.forEach((table, tableIndex) => {
        const headerRow = table.tHead?.rows[0];
        if (!headerRow) return;

        const toolbar = document.createElement('div');
        toolbar.className = 'table-filter-bar';
        const search = document.createElement('input');
        search.className = 'table-filter-search';
        search.type = 'search';
        search.placeholder = 'Search this table';
        search.setAttribute('aria-label', 'Search table');
        toolbar.append(search);
        table.before(toolbar);

        const filters = new Map();
        const columnMenus = [];
        const headers = Array.from(headerRow.cells);

        const closeMenus = () => {
            columnMenus.forEach(({ menu, button }) => {
                menu.hidden = true;
                button.setAttribute('aria-expanded', 'false');
            });
        };

        headers.forEach((header, columnIndex) => {
            const values = [...new Set(Array.from(table.tBodies).flatMap((tbody) =>
                Array.from(tbody.rows, (row) => row.cells[columnIndex]?.textContent.trim().replace(/\s+/g, ' ') ?? '')
            ))].sort((left, right) => left.localeCompare(right));
            if (values.length < 2) return;

            const button = document.createElement('button');
            button.type = 'button';
            button.setAttribute('aria-label', `Filter ${header.textContent.trim()}`);
            button.setAttribute('aria-expanded', 'false');
            button.innerHTML = '<i class="fas fa-filter" aria-hidden="true"></i>';
            const filterContainer = document.createElement('span');
            filterContainer.className = 'column-filter';
            filterContainer.append(button);
            header.append(filterContainer);

            const menu = document.createElement('div');
            menu.className = 'column-filter-menu';
            menu.hidden = true;
            menu.id = `table-${tableIndex}-column-${columnIndex}-filter`;
            button.setAttribute('aria-controls', menu.id);

            const optionInputs = [];
            const allLabel = document.createElement('label');
            const allInput = document.createElement('input');
            allInput.type = 'checkbox';
            allInput.checked = true;
            allLabel.append(allInput, document.createTextNode('All values'));
            menu.append(allLabel);

            values.forEach((value) => {
                const label = document.createElement('label');
                const input = document.createElement('input');
                input.type = 'checkbox';
                input.value = value;
                input.checked = true;
                label.append(input, document.createTextNode(value || '(blank)'));
                menu.append(label);
                optionInputs.push(input);
            });
            document.body.append(menu);
            columnMenus.push({ menu, button });

            const updateColumn = () => {
                const selected = new Set(optionInputs.filter((input) => input.checked).map((input) => input.value));
                allInput.checked = selected.size === optionInputs.length;
                filters.set(columnIndex, selected);
                applyFilters();
            };

            allInput.addEventListener('change', () => {
                optionInputs.forEach((input) => { input.checked = allInput.checked; });
                updateColumn();
            });
            optionInputs.forEach((input) => input.addEventListener('change', updateColumn));
            button.addEventListener('click', () => {
                const shouldOpen = menu.hidden;
                closeMenus();
                if (!shouldOpen) return;
                const rect = button.getBoundingClientRect();
                menu.style.top = `${Math.min(rect.bottom + 6, window.innerHeight - 270)}px`;
                menu.style.left = `${Math.max(8, Math.min(rect.right - 220, window.innerWidth - 228))}px`;
                menu.hidden = false;
                button.setAttribute('aria-expanded', 'true');
            });
        });

        function applyFilters() {
            const query = search.value.trim().toLocaleLowerCase();
            Array.from(table.tBodies).forEach((tbody) => {
                Array.from(tbody.rows).forEach((row) => {
                    if (row.classList.contains('empty-unassigned-row')) {
                        row.hidden = false;
                        return;
                    }
                    const rowText = row.textContent.toLocaleLowerCase();
                    const matchesSearch = !query || rowText.includes(query);
                    const matchesColumns = [...filters].every(([columnIndex, selected]) => {
                        const value = row.cells[columnIndex]?.textContent.trim().replace(/\s+/g, ' ') ?? '';
                        return selected.has(value);
                    });
                    row.hidden = !(matchesSearch && matchesColumns);
                });
            });
        }

        search.addEventListener('input', applyFilters);
        document.addEventListener('click', (event) => {
            if (!event.target.closest('.column-filter-menu') && !event.target.closest('th button[aria-controls]')) closeMenus();
        });
        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') closeMenus();
        });

        const tbody = table.tBodies[0];
        if (tbody) new MutationObserver(applyFilters).observe(tbody, { childList: true, subtree: true });
    });
})();
