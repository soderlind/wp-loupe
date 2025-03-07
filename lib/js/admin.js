/* global wpLoupeAdmin, wp */
document.addEventListener('DOMContentLoaded', () => {
    const fieldConfigContainer = document.getElementById('wp-loupe-fields-config');
    const postTypeSelect = document.getElementById('wp_loupe_custom_post_types');
    const savedFields = wpLoupeAdmin.savedFields || {};
    const protectedFields = ['post_date', 'post_modified']; // Add date fields to protected list

    // Initialize fields configuration when post type selection changes
    postTypeSelect.addEventListener('change', () => {
        updateFieldsConfig();
    });

    async function updateFieldsConfig() {
        const selectedPostTypes = Array.from(postTypeSelect.selectedOptions).map(option => option.value);
        fieldConfigContainer.innerHTML = '';
        
        if (!selectedPostTypes || selectedPostTypes.length === 0) {
            return;
        }

        for (const postType of selectedPostTypes) {
            try {
                const fields = await getPostTypeFields(postType);
                if (Object.keys(fields).length > 0) {
                    addFieldConfigSection(postType, fields);
                }
            } catch (error) {
                console.error(`Error loading fields for ${postType}:`, error);
            }
        }
    }

    async function getPostTypeFields(postType) {
        const response = await wp.apiFetch({
            path: `wp-loupe/v1/post-type-fields/${postType}`,
            method: 'GET'
        });
        return response;
    }

    function addFieldConfigSection(postType, fields) {
        const section = document.createElement('div');
        section.className = 'wp-loupe-post-type-fields';
        
        // Add post type header
        const header = document.createElement('h3');
        header.textContent = postType;
        section.appendChild(header);
        
        // Create field configuration table
        const table = document.createElement('table');
        table.className = 'wp-loupe-fields-table widefat';
        
        // Add table header
        const thead = document.createElement('thead');
        const headerRow = document.createElement('tr');
        ['Field', 'Indexable', 'Weight', 'Filterable', 'Sortable', 'Sort Direction'].forEach(text => {
            const th = document.createElement('th');
            th.textContent = text;
            headerRow.appendChild(th);
        });
        thead.appendChild(headerRow);
        table.appendChild(thead);
        
        // Add table body
        const tbody = document.createElement('tbody');
        
        // Add rows for each field
        Object.entries(fields).forEach(([fieldKey, fieldData]) => {
            const fieldLabel = typeof fieldData === 'string' ? fieldData : fieldData.label;
            const savedFieldSettings = savedFields[postType]?.[fieldKey] || {};
            
            const isProtected = protectedFields.includes(fieldKey);
            const isTitle = fieldKey === 'post_title';
            const isDateField = fieldKey === 'post_date' || fieldKey === 'post_modified';
            const row = document.createElement('tr');
            
            // Field name
            const labelCell = document.createElement('td');
            const label = document.createElement('label');
            label.textContent = fieldLabel;
            labelCell.appendChild(label);
            row.appendChild(labelCell);
            
            // Indexable checkbox - always checked and disabled for date fields
            const indexableCell = document.createElement('td');
            const indexableInput = document.createElement('input');
            indexableInput.type = 'checkbox';
            indexableInput.name = `wp_loupe_fields[${postType}][${fieldKey}][indexable]`;
            indexableInput.value = '1';
            indexableInput.checked = isProtected || isDateField || savedFieldSettings.indexable !== false;
            indexableInput.disabled = isProtected || isDateField;
            indexableCell.appendChild(indexableInput);
            row.appendChild(indexableCell);
            
            // Weight input - enabled for date fields
            const weightCell = document.createElement('td');
            const weightInput = document.createElement('input');
            weightInput.type = 'number';
            weightInput.step = '0.1';
            weightInput.min = '0';
            weightInput.name = `wp_loupe_fields[${postType}][${fieldKey}][weight]`;
            weightInput.value = savedFieldSettings.weight || (isTitle ? '2.0' : '1.0');
            weightInput.className = 'small-text';
            weightCell.appendChild(weightInput);
            row.appendChild(weightCell);
            
            // Filterable checkbox - enabled for date fields
            const filterableCell = document.createElement('td');
            const filterableInput = document.createElement('input');
            filterableInput.type = 'checkbox';
            filterableInput.name = `wp_loupe_fields[${postType}][${fieldKey}][filterable]`;
            filterableInput.value = '1';
            filterableInput.checked = isDateField ? true : savedFieldSettings.filterable;
            filterableCell.appendChild(filterableInput);
            row.appendChild(filterableCell);
            
            // Sortable checkbox - enabled for date fields
            const sortableCell = document.createElement('td');
            const sortableInput = document.createElement('input');
            sortableInput.type = 'checkbox';
            sortableInput.name = `wp_loupe_fields[${postType}][${fieldKey}][sortable]`;
            sortableInput.value = '1';
            sortableInput.className = 'wp-loupe-sortable-toggle';
            sortableInput.checked = isDateField ? true : savedFieldSettings.sortable;
            sortableCell.appendChild(sortableInput);
            row.appendChild(sortableCell);
            
            // Sort direction select - enabled for date fields
            const directionCell = document.createElement('td');
            const directionSelect = document.createElement('select');
            directionSelect.name = `wp_loupe_fields[${postType}][${fieldKey}][sort_direction]`;
            directionSelect.className = 'wp-loupe-sort-direction';
            directionSelect.disabled = !sortableInput.checked;
            
            const ascOption = document.createElement('option');
            ascOption.value = 'asc';
            ascOption.textContent = 'Ascending';
            ascOption.selected = savedFieldSettings.sort_direction === 'asc';
            
            const descOption = document.createElement('option');
            descOption.value = 'desc';
            descOption.textContent = 'Descending';
            descOption.selected = isDateField ? true : (savedFieldSettings.sort_direction === 'desc' || !savedFieldSettings.sort_direction);
            
            directionSelect.appendChild(ascOption);
            directionSelect.appendChild(descOption);
            directionCell.appendChild(directionSelect);
            row.appendChild(directionCell);
            
            tbody.appendChild(row);
        });
        
        table.appendChild(tbody);
        section.appendChild(table);
        fieldConfigContainer.appendChild(section);
    }

    // Handle sortable checkbox changes
    document.addEventListener('change', (e) => {
        if (e.target.classList.contains('wp-loupe-sortable-toggle')) {
            const directionSelect = e.target.closest('tr').querySelector('.wp-loupe-sort-direction');
            directionSelect.disabled = !e.target.checked;
        }
    });

    // Initial load of fields for pre-selected post types
    updateFieldsConfig();
});