/**
 * Visual Query Builder
 * Allows users to build SQL queries using a GUI
 */

class QueryBuilder {
    constructor(containerId, options = {}) {
        this.container = document.getElementById(containerId);
        this.table = options.table || '';
        this.columns = options.columns || [];
        this.conditions = [];
        this.orderBy = [];
        this.limit = null;
        this.offset = 0;
        
        this.init();
    }
    
    init() {
        this.render();
        this.attachEvents();
    }
    
    render() {
        this.container.innerHTML = `
            <div class="query-builder">
                <div class="qb-section">
                    <h4><i class="fas fa-table"></i> SELECT Columns</h4>
                    <div id="qb-columns" class="qb-columns">
                        <label style="display:flex; align-items:center; gap:8px; margin-bottom:8px;">
                            <input type="checkbox" id="qb-select-all" checked>
                            <span>Select All (*)</span>
                        </label>
                        <div id="qb-column-list" style="display:none; max-height:200px; overflow-y:auto; padding-left:24px;">
                            ${this.columns.map(col => `
                                <label style="display:flex; align-items:center; gap:8px; margin-bottom:6px;">
                                    <input type="checkbox" class="qb-column-check" value="${col}" checked>
                                    <span>${col}</span>
                                </label>
                            `).join('')}
                        </div>
                    </div>
                </div>
                
                <div class="qb-section">
                    <h4><i class="fas fa-filter"></i> WHERE Conditions</h4>
                    <div id="qb-conditions"></div>
                    <button type="button" class="btn btn-sm" onclick="queryBuilder.addCondition()">
                        <i class="fas fa-plus"></i> Add Condition
                    </button>
                </div>
                
                <div class="qb-section">
                    <h4><i class="fas fa-sort"></i> ORDER BY</h4>
                    <div id="qb-orderby"></div>
                    <button type="button" class="btn btn-sm" onclick="queryBuilder.addOrderBy()">
                        <i class="fas fa-plus"></i> Add Order
                    </button>
                </div>
                
                <div class="qb-section">
                    <h4><i class="fas fa-list"></i> LIMIT & OFFSET</h4>
                    <div style="display:flex; gap:15px; align-items:center;">
                        <label style="display:flex; align-items:center; gap:8px;">
                            <span>LIMIT:</span>
                            <input type="number" id="qb-limit" class="form-control" style="width:100px;" placeholder="No limit" min="1">
                        </label>
                        <label style="display:flex; align-items:center; gap:8px;">
                            <span>OFFSET:</span>
                            <input type="number" id="qb-offset" class="form-control" style="width:100px;" value="0" min="0">
                        </label>
                    </div>
                </div>
                
                <div class="qb-section">
                    <h4><i class="fas fa-code"></i> Generated Query</h4>
                    <textarea id="qb-generated-query" class="form-control" rows="6" readonly style="font-family:monospace; background:#080808; color:#a5d6ff; border:1px solid #333;"></textarea>
                    <div style="margin-top:10px; display:flex; gap:10px;">
                        <button type="button" class="btn btn-primary" onclick="queryBuilder.executeQuery()">
                            <i class="fas fa-play"></i> Execute Query
                        </button>
                        <button type="button" class="btn" onclick="queryBuilder.copyQuery()">
                            <i class="fas fa-copy"></i> Copy Query
                        </button>
                        <button type="button" class="btn" onclick="queryBuilder.reset()">
                            <i class="fas fa-redo"></i> Reset
                        </button>
                    </div>
                </div>
            </div>
        `;
        
        this.updateQuery();
    }
    
    attachEvents() {
        // Select all checkbox
        const selectAll = document.getElementById('qb-select-all');
        const columnList = document.getElementById('qb-column-list');
        
        if (selectAll) {
            selectAll.addEventListener('change', (e) => {
                if (e.target.checked) {
                    columnList.style.display = 'none';
                    document.querySelectorAll('.qb-column-check').forEach(cb => cb.checked = true);
                } else {
                    columnList.style.display = 'block';
                }
                this.updateQuery();
            });
        }
        
        // Column checkboxes
        document.querySelectorAll('.qb-column-check').forEach(cb => {
            cb.addEventListener('change', () => this.updateQuery());
        });
        
        // Limit and offset
        document.getElementById('qb-limit')?.addEventListener('input', () => this.updateQuery());
        document.getElementById('qb-offset')?.addEventListener('input', () => this.updateQuery());
    }
    
    addCondition() {
        const conditionsDiv = document.getElementById('qb-conditions');
        const index = this.conditions.length;
        
        const conditionHtml = `
            <div class="qb-condition" data-index="${index}" style="display:flex; gap:10px; margin-bottom:10px; align-items:center; flex-wrap:wrap;">
                ${index > 0 ? `
                    <select class="form-select qb-logic" style="width:80px;">
                        <option value="AND">AND</option>
                        <option value="OR">OR</option>
                    </select>
                ` : ''}
                <select class="form-select qb-column" style="width:150px;">
                    <option value="">-- Column --</option>
                    ${this.columns.map(col => `<option value="${col}">${col}</option>`).join('')}
                </select>
                <select class="form-select qb-operator" style="width:120px;">
                    <option value="=">=</option>
                    <option value="!=">!=</option>
                    <option value=">">></option>
                    <option value="<"><</option>
                    <option value=">=">>=</option>
                    <option value="<="><=</option>
                    <option value="LIKE">LIKE</option>
                    <option value="NOT LIKE">NOT LIKE</option>
                    <option value="IN">IN</option>
                    <option value="NOT IN">NOT IN</option>
                    <option value="IS NULL">IS NULL</option>
                    <option value="IS NOT NULL">IS NOT NULL</option>
                </select>
                <input type="text" class="form-control qb-value" placeholder="Value" style="flex:1; min-width:150px;">
                <button type="button" class="btn btn-danger btn-sm" onclick="queryBuilder.removeCondition(${index})">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;
        
        conditionsDiv.insertAdjacentHTML('beforeend', conditionHtml);
        
        // Attach change events
        const condition = conditionsDiv.lastElementChild;
        condition.querySelectorAll('select, input').forEach(el => {
            el.addEventListener('change', () => this.updateQuery());
            el.addEventListener('input', () => this.updateQuery());
        });
        
        // Handle operator change (hide value input for IS NULL/IS NOT NULL)
        condition.querySelector('.qb-operator').addEventListener('change', (e) => {
            const valueInput = condition.querySelector('.qb-value');
            if (e.target.value === 'IS NULL' || e.target.value === 'IS NOT NULL') {
                valueInput.style.display = 'none';
                valueInput.value = '';
            } else {
                valueInput.style.display = 'block';
            }
            this.updateQuery();
        });
        
        this.conditions.push({});
        this.updateQuery();
    }
    
    removeCondition(index) {
        const condition = document.querySelector(`.qb-condition[data-index="${index}"]`);
        if (condition) {
            condition.remove();
            this.conditions.splice(index, 1);
            this.updateQuery();
        }
    }
    
    addOrderBy() {
        const orderByDiv = document.getElementById('qb-orderby');
        const index = this.orderBy.length;
        
        const orderHtml = `
            <div class="qb-order" data-index="${index}" style="display:flex; gap:10px; margin-bottom:10px; align-items:center;">
                <select class="form-select qb-order-column" style="width:200px;">
                    <option value="">-- Column --</option>
                    ${this.columns.map(col => `<option value="${col}">${col}</option>`).join('')}
                </select>
                <select class="form-select qb-order-dir" style="width:100px;">
                    <option value="ASC">ASC</option>
                    <option value="DESC">DESC</option>
                </select>
                <button type="button" class="btn btn-danger btn-sm" onclick="queryBuilder.removeOrderBy(${index})">
                    <i class="fas fa-times"></i>
                </button>
            </div>
        `;
        
        orderByDiv.insertAdjacentHTML('beforeend', orderHtml);
        
        // Attach change events
        const order = orderByDiv.lastElementChild;
        order.querySelectorAll('select').forEach(el => {
            el.addEventListener('change', () => this.updateQuery());
        });
        
        this.orderBy.push({});
        this.updateQuery();
    }
    
    removeOrderBy(index) {
        const order = document.querySelector(`.qb-order[data-index="${index}"]`);
        if (order) {
            order.remove();
            this.orderBy.splice(index, 1);
            this.updateQuery();
        }
    }
    
    updateQuery() {
        const query = this.buildQuery();
        document.getElementById('qb-generated-query').value = query;
    }
    
    buildQuery() {
        // SELECT clause
        let query = 'SELECT ';
        
        const selectAll = document.getElementById('qb-select-all')?.checked;
        if (selectAll) {
            query += '*';
        } else {
            const selectedColumns = Array.from(document.querySelectorAll('.qb-column-check:checked'))
                .map(cb => `\`${cb.value}\``);
            query += selectedColumns.length > 0 ? selectedColumns.join(', ') : '*';
        }
        
        query += `\nFROM \`${this.table}\``;
        
        // WHERE clause
        const conditions = [];
        document.querySelectorAll('.qb-condition').forEach((condDiv, idx) => {
            const logic = condDiv.querySelector('.qb-logic')?.value || '';
            const column = condDiv.querySelector('.qb-column')?.value;
            const operator = condDiv.querySelector('.qb-operator')?.value;
            const value = condDiv.querySelector('.qb-value')?.value;
            
            if (column && operator) {
                let condStr = idx > 0 ? `${logic} ` : '';
                condStr += `\`${column}\` ${operator}`;
                
                if (operator !== 'IS NULL' && operator !== 'IS NOT NULL') {
                    if (operator === 'IN' || operator === 'NOT IN') {
                        condStr += ` (${value})`;
                    } else if (operator === 'LIKE' || operator === 'NOT LIKE') {
                        condStr += ` '${value.replace(/'/g, "''")}'`;
                    } else {
                        // Try to detect if value is numeric
                        condStr += isNaN(value) ? ` '${value.replace(/'/g, "''")}'` : ` ${value}`;
                    }
                }
                
                conditions.push(condStr);
            }
        });
        
        if (conditions.length > 0) {
            query += '\nWHERE ' + conditions.join('\n  ');
        }
        
        // ORDER BY clause
        const orders = [];
        document.querySelectorAll('.qb-order').forEach(orderDiv => {
            const column = orderDiv.querySelector('.qb-order-column')?.value;
            const dir = orderDiv.querySelector('.qb-order-dir')?.value;
            
            if (column) {
                orders.push(`\`${column}\` ${dir}`);
            }
        });
        
        if (orders.length > 0) {
            query += '\nORDER BY ' + orders.join(', ');
        }
        
        // LIMIT clause
        const limit = document.getElementById('qb-limit')?.value;
        const offset = document.getElementById('qb-offset')?.value;
        
        if (limit) {
            query += `\nLIMIT ${limit}`;
            if (offset && offset > 0) {
                query += ` OFFSET ${offset}`;
            }
        }
        
        return query + ';';
    }
    
    executeQuery() {
        const query = document.getElementById('qb-generated-query').value;
        
        // Submit to SQL form
        const sqlForm = document.getElementById('sqlForm');
        if (sqlForm) {
            const queryInput = sqlForm.querySelector('textarea[name="query"]');
            if (queryInput) {
                queryInput.value = query;
                sqlForm.submit();
            }
        } else {
            // Alternative: create and submit form
            const form = document.createElement('form');
            form.method = 'POST';
            form.innerHTML = `
                <input type="hidden" name="action" value="sql_query">
                <textarea name="query" style="display:none;">${query}</textarea>
            `;
            document.body.appendChild(form);
            form.submit();
        }
    }
    
    copyQuery() {
        const query = document.getElementById('qb-generated-query').value;
        navigator.clipboard.writeText(query).then(() => {
            Swal.fire({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 1500,
                icon: 'success',
                title: 'Query copied!'
            });
        });
    }
    
    reset() {
        this.conditions = [];
        this.orderBy = [];
        this.render();
    }
}

// Global instance
let queryBuilder = null;
