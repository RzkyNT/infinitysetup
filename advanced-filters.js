/**
 * Advanced Filters Component
 * Multiple column filters with searchable dropdowns
 */

class AdvancedFilters {
    constructor(containerId, options = {}) {
        this.container = document.getElementById(containerId);
        this.table = options.table || '';
        this.columns = options.columns || [];
        this.filters = [];
        this.tomSelectInstances = [];
        
        this.init();
    }
    
    init() {
        this.render();
    }
    
    render() {
        this.container.innerHTML = `
            <div class="advanced-filters">
                <div style="display:flex; justify-content:space-between; align-items:center; margin-bottom:15px;">
                    <h4 style="margin:0;"><i class="fas fa-filter"></i> Advanced Filters</h4>
                    <div style="display:flex; gap:10px;">
                        <button type="button" class="btn btn-sm" onclick="advancedFilters.addFilter()">
                            <i class="fas fa-plus"></i> Add Filter
                        </button>
                        <button type="button" class="btn btn-sm" onclick="advancedFilters.clearAll()">
                            <i class="fas fa-times"></i> Clear All
                        </button>
                    </div>
                </div>
                <div id="af-filters-container"></div>
                <div style="margin-top:15px; display:flex; gap:10px; justify-content:flex-end;">
                    <button type="button" class="btn btn-primary" onclick="advancedFilters.applyFilters()">
                        <i class="fas fa-search"></i> Apply Filters
                    </button>
                    <button type="button" class="btn" onclick="advancedFilters.exportFilters()">
                        <i class="fas fa-download"></i> Export Filtered Data
                    </button>
                </div>
            </div>
        `;
        
        // Add initial filter
        if (this.filters.length === 0) {
            this.addFilter();
        }
    }
    
    addFilter() {
        const container = document.getElementById('af-filters-container');
        const index = this.filters.length;
        
        const filterHtml = `
            <div class="af-filter" data-index="${index}" style="display:flex; gap:10px; margin-bottom:12px; align-items:center; padding:12px; background:var(--bg-hover); border-radius:6px; flex-wrap:wrap;">
                ${index > 0 ? `
                    <select class="af-logic form-select" style="width:80px; background:var(--bg-input);">
                        <option value="AND">AND</option>
                        <option value="OR">OR</option>
                    </select>
                ` : '<div style="width:80px;"></div>'}
                
                <select class="af-column form-select" style="flex:1; min-width:150px; background:var(--bg-input);">
                    <option value="">-- Select Column --</option>
                    ${this.columns.map(col => `<option value="${col}">${col}</option>`).join('')}
                </select>
                
                <select class="af-operator form-select" style="width:140px; background:var(--bg-input);">
                    <option value="=">=</option>
                    <option value="!=">!=</option>
                    <option value=">">></option>
                    <option value="<"><</option>
                    <option value=">=">>=</option>
                    <option value="<="><=</option>
                    <option value="LIKE">Contains (LIKE)</option>
                    <option value="NOT LIKE">Not Contains</option>
                    <option value="STARTS">Starts With</option>
                    <option value="ENDS">Ends With</option>
                    <option value="IN">In List</option>
                    <option value="NOT IN">Not In List</option>
                    <option value="IS NULL">Is Empty</option>
                    <option value="IS NOT NULL">Is Not Empty</option>
                    <option value="BETWEEN">Between</option>
                </select>
                
                <div class="af-value-container" style="flex:1; min-width:200px; display:flex; gap:8px;">
                    <input type="text" class="af-value form-control" placeholder="Value" style="flex:1; background:var(--bg-input);">
                </div>
                
                <button type="button" class="btn btn-danger btn-sm" onclick="advancedFilters.removeFilter(${index})" title="Remove Filter">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        `;
        
        container.insertAdjacentHTML('beforeend', filterHtml);
        
        const filterDiv = container.lastElementChild;
        
        // Initialize TomSelect for column dropdown
        const columnSelect = filterDiv.querySelector('.af-column');
        if (columnSelect && typeof TomSelect !== 'undefined') {
            const ts = new TomSelect(columnSelect, {
                plugins: ['clear_button'],
                placeholder: 'Select Column',
                maxOptions: 100,
                sortField: { field: "text", direction: "asc" }
            });
            this.tomSelectInstances.push(ts);
        }
        
        // Handle operator change
        const operatorSelect = filterDiv.querySelector('.af-operator');
        operatorSelect.addEventListener('change', (e) => {
            this.updateValueInput(filterDiv, e.target.value);
        });
        
        this.filters.push({ index });
    }
    
    updateValueInput(filterDiv, operator) {
        const valueContainer = filterDiv.querySelector('.af-value-container');
        
        if (operator === 'IS NULL' || operator === 'IS NOT NULL') {
            // No value needed
            valueContainer.innerHTML = '<span style="color:var(--text-secondary); font-style:italic;">No value required</span>';
        } else if (operator === 'BETWEEN') {
            // Two inputs for range
            valueContainer.innerHTML = `
                <input type="text" class="af-value form-control" placeholder="From" style="flex:1; background:var(--bg-input);">
                <span style="color:var(--text-secondary);">to</span>
                <input type="text" class="af-value-2 form-control" placeholder="To" style="flex:1; background:var(--bg-input);">
            `;
        } else if (operator === 'IN' || operator === 'NOT IN') {
            // Textarea for list
            valueContainer.innerHTML = `
                <textarea class="af-value form-control" placeholder="Enter values separated by comma&#10;Example: value1, value2, value3" rows="2" style="flex:1; background:var(--bg-input); resize:vertical;"></textarea>
            `;
        } else {
            // Single input
            valueContainer.innerHTML = `
                <input type="text" class="af-value form-control" placeholder="Value" style="flex:1; background:var(--bg-input);">
            `;
        }
    }
    
    removeFilter(index) {
        const filter = document.querySelector(`.af-filter[data-index="${index}"]`);
        if (filter) {
            filter.remove();
            this.filters = this.filters.filter(f => f.index !== index);
            
            // If no filters left, add one
            if (this.filters.length === 0) {
                this.addFilter();
            }
        }
    }
    
    clearAll() {
        document.getElementById('af-filters-container').innerHTML = '';
        this.filters = [];
        this.tomSelectInstances.forEach(ts => ts.destroy());
        this.tomSelectInstances = [];
        this.addFilter();
    }
    
    getFilters() {
        const filters = [];
        
        document.querySelectorAll('.af-filter').forEach((filterDiv, idx) => {
            const logic = filterDiv.querySelector('.af-logic')?.value || 'AND';
            const column = filterDiv.querySelector('.af-column')?.value;
            const operator = filterDiv.querySelector('.af-operator')?.value;
            const value = filterDiv.querySelector('.af-value')?.value;
            const value2 = filterDiv.querySelector('.af-value-2')?.value;
            
            if (column && operator) {
                const filter = {
                    logic: idx > 0 ? logic : null,
                    column,
                    operator,
                    value
                };
                
                if (operator === 'BETWEEN' && value2) {
                    filter.value2 = value2;
                }
                
                filters.push(filter);
            }
        });
        
        return filters;
    }
    
    buildWhereClause() {
        const filters = this.getFilters();
        if (filters.length === 0) return '';
        
        const conditions = filters.map((filter, idx) => {
            let condition = idx > 0 ? `${filter.logic} ` : '';
            condition += `\`${filter.column}\``;
            
            switch (filter.operator) {
                case 'IS NULL':
                case 'IS NOT NULL':
                    condition += ` ${filter.operator}`;
                    break;
                    
                case 'LIKE':
                    condition += ` LIKE '%${this.escapeValue(filter.value)}%'`;
                    break;
                    
                case 'NOT LIKE':
                    condition += ` NOT LIKE '%${this.escapeValue(filter.value)}%'`;
                    break;
                    
                case 'STARTS':
                    condition += ` LIKE '${this.escapeValue(filter.value)}%'`;
                    break;
                    
                case 'ENDS':
                    condition += ` LIKE '%${this.escapeValue(filter.value)}'`;
                    break;
                    
                case 'IN':
                case 'NOT IN':
                    const values = filter.value.split(',').map(v => `'${this.escapeValue(v.trim())}'`).join(', ');
                    condition += ` ${filter.operator} (${values})`;
                    break;
                    
                case 'BETWEEN':
                    condition += ` BETWEEN '${this.escapeValue(filter.value)}' AND '${this.escapeValue(filter.value2)}'`;
                    break;
                    
                default:
                    const isNumeric = !isNaN(filter.value) && filter.value !== '';
                    condition += ` ${filter.operator} ${isNumeric ? filter.value : `'${this.escapeValue(filter.value)}'`}`;
            }
            
            return condition;
        });
        
        return conditions.join(' ');
    }
    
    escapeValue(value) {
        return String(value).replace(/'/g, "''");
    }
    
    applyFilters() {
        const whereClause = this.buildWhereClause();
        
        if (!whereClause) {
            Swal.fire('No Filters', 'Please add at least one filter condition', 'info');
            return;
        }
        
        // Build full query
        const query = `SELECT * FROM \`${this.table}\` WHERE ${whereClause};`;
        
        // Show preview
        Swal.fire({
            title: 'Apply Filters?',
            html: `<div style="text-align:left;">
                <p style="margin-bottom:10px;">Generated WHERE clause:</p>
                <pre style="background:#080808; color:#a5d6ff; padding:12px; border-radius:6px; overflow-x:auto; font-size:13px;">${whereClause}</pre>
            </div>`,
            icon: 'question',
            showCancelButton: true,
            confirmButtonText: 'Apply',
            cancelButtonText: 'Cancel'
        }).then((result) => {
            if (result.isConfirmed) {
                // Submit query
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="sql_query">
                    <input type="hidden" name="table" value="${this.table}">
                    <textarea name="query" style="display:none;">${query}</textarea>
                `;
                document.body.appendChild(form);
                form.submit();
            }
        });
    }
    
    exportFilters() {
        const whereClause = this.buildWhereClause();
        
        if (!whereClause) {
            Swal.fire('No Filters', 'Please add at least one filter condition', 'info');
            return;
        }
        
        Swal.fire({
            title: 'Export Filtered Data',
            html: `
                <div style="text-align:left; margin-bottom:15px;">
                    <p style="margin-bottom:10px;">Select export format:</p>
                    <select id="export-format" class="form-select">
                        <option value="csv">CSV</option>
                        <option value="json">JSON</option>
                        <option value="sql">SQL</option>
                    </select>
                </div>
            `,
            showCancelButton: true,
            confirmButtonText: 'Export',
            preConfirm: () => {
                return document.getElementById('export-format').value;
            }
        }).then((result) => {
            if (result.isConfirmed) {
                const format = result.value;
                const query = `SELECT * FROM \`${this.table}\` WHERE ${whereClause}`;
                
                // Create form to export
                const form = document.createElement('form');
                form.method = 'POST';
                form.innerHTML = `
                    <input type="hidden" name="action" value="export_filtered">
                    <input type="hidden" name="table" value="${this.table}">
                    <input type="hidden" name="format" value="${format}">
                    <input type="hidden" name="where_clause" value="${this.escapeValue(whereClause)}">
                `;
                document.body.appendChild(form);
                form.submit();
            }
        });
    }
    
    saveFilters() {
        const filters = this.getFilters();
        const json = JSON.stringify(filters, null, 2);
        
        // Download as JSON
        const blob = new Blob([json], { type: 'application/json' });
        const url = URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `${this.table}_filters_${Date.now()}.json`;
        a.click();
        URL.revokeObjectURL(url);
    }
    
    loadFilters(filtersData) {
        this.clearAll();
        
        filtersData.forEach((filter, idx) => {
            if (idx > 0) this.addFilter();
            
            const filterDiv = document.querySelectorAll('.af-filter')[idx];
            if (filterDiv) {
                if (filter.logic) {
                    const logicSelect = filterDiv.querySelector('.af-logic');
                    if (logicSelect) logicSelect.value = filter.logic;
                }
                
                filterDiv.querySelector('.af-column').value = filter.column;
                filterDiv.querySelector('.af-operator').value = filter.operator;
                
                this.updateValueInput(filterDiv, filter.operator);
                
                const valueInput = filterDiv.querySelector('.af-value');
                if (valueInput) valueInput.value = filter.value;
                
                if (filter.value2) {
                    const value2Input = filterDiv.querySelector('.af-value-2');
                    if (value2Input) value2Input.value = filter.value2;
                }
            }
        });
    }
}

// Global instance
let advancedFilters = null;
