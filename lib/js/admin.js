/* global wpLoupeAdmin, wp */

/**
 * WP Loupe Admin Field Configuration Manager
 * Handles the configuration UI for field indexing, filtering, and sorting
 */
class WpLoupeFieldManager {
    constructor() {
        this.__ = wp.i18n.__;
        this.fieldConfigContainer = document.getElementById('wp-loupe-fields-config');
        this.postTypeSelect = document.getElementById('wp_loupe_custom_post_types');
        this.savedFields = wpLoupeAdmin.savedFields || {};
        this.protectedFields = ['post_date', 'post_modified'];
        this.previouslySelectedPostTypes = Array.from(this.postTypeSelect.selectedOptions)
            .map(option => option.value);

        this.init();

        this.nonSortableFieldTypes = [
            'post_content',
            'post_excerpt'
        ];
    }

    /**
     * Initialize the manager
     */
    init() {
        this.preventFormSubmission();
        this.initializeSelect2();
        this.bindEvents();
        this.loadInitialFields();
    }

    /**
     * Prevent form submission when pressing enter
     */
    preventFormSubmission() {
        const form = this.postTypeSelect.form;
        if (form) {
            const originalSubmit = form.onsubmit;
            form.onsubmit = (e) => {
                // Allow submit button submissions
                if (e.submitter && e.submitter.type === 'submit') {
                    return originalSubmit ? originalSubmit(e) : true;
                }
                e.preventDefault();
                return false;
            };
        }
    }

    /**
     * Initialize Select2 for post type selection
     */
    initializeSelect2() {
        jQuery(this.postTypeSelect).select2().on('select2:select select2:unselect', () => {
            const currentlySelectedPostTypes = Array.from(this.postTypeSelect.selectedOptions)
                .map(option => option.value);
            
            // Identify newly added post types
            const newlyAddedPostTypes = currentlySelectedPostTypes.filter(
                postType => !this.previouslySelectedPostTypes.includes(postType)
            );
            
            this.updateFieldsConfig(newlyAddedPostTypes);
            
            // Update the tracking array for next time
            this.previouslySelectedPostTypes = [...currentlySelectedPostTypes];
        });
    }

    /**
     * Bind event listeners
     */
    bindEvents() {
        document.addEventListener('change', (e) => {
            if (e.target.classList.contains('wp-loupe-sortable-toggle')) {
                const directionSelect = e.target.closest('tr').querySelector('.wp-loupe-sort-direction');
                directionSelect.disabled = !e.target.checked;
            }
        });
    }

    /**
     * Load initial fields for pre-selected post types
     */
    loadInitialFields() {
        this.updateFieldsConfig();
    }

    /**
     * Update the fields configuration UI
     * @param {Array} newPostTypes - Array of newly added post types
     */
    async updateFieldsConfig(newPostTypes = []) {
        const selectedPostTypes = Array.from(this.postTypeSelect.selectedOptions).map(option => option.value);
        this.fieldConfigContainer.innerHTML = '';
        
        if (!selectedPostTypes || selectedPostTypes.length === 0) {
            return;
        }
        
        for (const postType of selectedPostTypes) {
            try {
                const fields = await this.getPostTypeFields(postType);
                if (Object.keys(fields).length > 0) {
                    const isNewPostType = newPostTypes.includes(postType);
                    this.addFieldConfigSection(postType, fields, isNewPostType);
                }
            } catch (error) {
                console.error(`Error loading fields for ${postType}:`, error);
            }
        }
    }

    /**
     * Fetch fields for a post type from the API
     * @param {string} postType - Post type slug
     * @returns {Object} - Field data
     */
    async getPostTypeFields(postType) {
        const response = await wp.apiFetch({
            path: `wp-loupe/v1/post-type-fields/${postType}`,
            method: 'GET'
        });
        return response;
    }

    /**
     * Add field configuration UI section for a post type
     * @param {string} postType - The post type
     * @param {Object} fields - Fields data
     * @param {boolean} isNewPostType - Whether this is a newly added post type
     */
    addFieldConfigSection(postType, fields, isNewPostType) {
        const section = this.createSectionElement(postType);
        const table = this.createFieldTable(postType, fields, isNewPostType);
        
        section.appendChild(table);
        this.fieldConfigContainer.appendChild(section);
    }

    /**
     * Create the section container element
     * @param {string} postType - Post type slug
     * @returns {HTMLElement} - Section element
     */
    createSectionElement(postType) {
        const section = document.createElement('div');
        section.className = 'wp-loupe-post-type-fields';
        
        // Add post type header
        const header = document.createElement('h3');
        header.textContent = postType;
        section.appendChild(header);
        
        return section;
    }

    /**
     * Create field configuration table
     * @param {string} postType - The post type
     * @param {Object} fields - Fields data
     * @param {boolean} isNewPostType - Whether this is a newly added post type
     * @returns {HTMLElement} - Table element
     */
    createFieldTable(postType, fields, isNewPostType) {
        const table = document.createElement('table');
        table.className = 'wp-loupe-fields-table widefat';
        
        const thead = this.createTableHeader();
        const tbody = this.createTableBody(postType, fields, isNewPostType);
        
        table.appendChild(thead);
        table.appendChild(tbody);
        
        return table;
    }

    /**
     * Create table header
     * @returns {HTMLElement} - Table header element
     */
    createTableHeader() {
        const thead = document.createElement('thead');
        const headerRow = document.createElement('tr');
        
        const headerLabels = [
            this.__('Field', 'wp-loupe'),
            this.__('Indexable', 'wp-loupe'),
            this.__('Weight', 'wp-loupe'),
            this.__('Filterable', 'wp-loupe'),
            this.__('Sortable', 'wp-loupe'),
            this.__('Sort Direction', 'wp-loupe')
        ];
        
        headerLabels.forEach(text => {
            const th = document.createElement('th');
            th.textContent = text;
            headerRow.appendChild(th);
        });
        
        thead.appendChild(headerRow);
        return thead;
    }

    /**
     * Create table body with field rows
     * @param {string} postType - The post type
     * @param {Object} fields - Fields data
     * @param {boolean} isNewPostType - Whether this is a newly added post type
     * @returns {HTMLElement} - Table body element
     */
    createTableBody(postType, fields, isNewPostType) {
        const tbody = document.createElement('tbody');
        
        Object.entries(fields).forEach(([fieldKey, fieldData]) => {
            const row = this.createFieldRow(postType, fieldKey, fieldData, isNewPostType);
            tbody.appendChild(row);
        });
        
        return tbody;
    }

    /**
     * Create a table row for a field
     * @param {string} postType - The post type
     * @param {string} fieldKey - Field key
     * @param {Object|string} fieldData - Field data
     * @param {boolean} isNewPostType - Whether this is a newly added post type
     * @returns {HTMLElement} - Table row
     */
    createFieldRow(postType, fieldKey, fieldData, isNewPostType) {
        const fieldLabel = typeof fieldData === 'string' ? fieldData : fieldData.label;
        const savedFieldSettings = this.savedFields[postType]?.[fieldKey] || {};
        
        const isProtected = this.protectedFields.includes(fieldKey);
        const isTitle = fieldKey === 'post_title';
        const isDateField = fieldKey === 'post_date' || fieldKey === 'post_modified';
        const row = document.createElement('tr');
        
        // Add field name cell
        row.appendChild(this.createLabelCell(fieldLabel));
        
        // Create inputs
        const { indexableInput, weightInput, filterableInput, sortableInput, directionSelect } = 
            this.createFieldInputs(postType, fieldKey, isNewPostType, isProtected, isTitle, isDateField, savedFieldSettings);
        
        // Add cells to row
        row.appendChild(this.createInputCell(indexableInput));
        row.appendChild(this.createInputCell(weightInput));
        row.appendChild(this.createInputCell(filterableInput));
        row.appendChild(this.createInputCell(sortableInput));
        row.appendChild(this.createInputCell(directionSelect));
        
        // Add event listener for indexable checkbox
        this.bindFieldRowEvents(indexableInput, weightInput, filterableInput, sortableInput, directionSelect);
        
        return row;
    }

    /**
     * Create a cell with a label
     * @param {string} labelText - Label text
     * @returns {HTMLElement} - Table cell with label
     */
    createLabelCell(labelText) {
        const cell = document.createElement('td');
        const label = document.createElement('label');
        label.textContent = labelText;
        cell.appendChild(label);
        return cell;
    }

    /**
     * Create a cell with an input element
     * @param {HTMLElement} input - Input element
     * @returns {HTMLElement} - Table cell with input
     */
    createInputCell(input) {
        const cell = document.createElement('td');
        cell.appendChild(input);
        return cell;
    }

    /**
     * Create all input controls for a field row
     * @param {string} postType - The post type
     * @param {string} fieldKey - Field key
     * @param {boolean} isNewPostType - Whether this is a newly added post type
     * @param {boolean} isProtected - Whether the field is protected
     * @param {boolean} isTitle - Whether the field is a title
     * @param {boolean} isDateField - Whether the field is a date field
     * @param {Object} savedFieldSettings - Saved field settings
     * @returns {Object} - Object containing all input elements
     */
    createFieldInputs(postType, fieldKey, isNewPostType, isProtected, isTitle, isDateField, savedFieldSettings) {
        // Create indexable input
        const indexableInput = document.createElement('input');
        indexableInput.type = 'checkbox';
        indexableInput.name = `wp_loupe_fields[${postType}][${fieldKey}][indexable]`;
        indexableInput.value = '1';
        indexableInput.checked = isNewPostType || isProtected || isDateField || savedFieldSettings.indexable === true;
        indexableInput.disabled = isProtected || isDateField;
        
        // Create weight input
        const weightInput = document.createElement('input');
        weightInput.type = 'number';
        weightInput.step = '0.1';
        weightInput.min = '0';
        weightInput.name = `wp_loupe_fields[${postType}][${fieldKey}][weight]`;
        weightInput.value = savedFieldSettings.weight || (isTitle ? '2.0' : '1.0');
        weightInput.className = 'small-text';
        weightInput.disabled = !indexableInput.checked;
        
        // Create filterable input
        const filterableInput = document.createElement('input');
        filterableInput.type = 'checkbox';
        filterableInput.name = `wp_loupe_fields[${postType}][${fieldKey}][filterable]`;
        filterableInput.value = '1';
        filterableInput.checked = isNewPostType || isDateField || savedFieldSettings.filterable;
        filterableInput.disabled = !indexableInput.checked;
        
        // Create sortable input
        const sortableInput = document.createElement('input');
        sortableInput.type = 'checkbox';
        sortableInput.name = `wp_loupe_fields[${postType}][${fieldKey}][sortable]`;
        sortableInput.value = '1';
        sortableInput.className = 'wp-loupe-sortable-toggle';
        sortableInput.checked = isNewPostType || isDateField || savedFieldSettings.sortable;
        sortableInput.disabled = !indexableInput.checked || !this.isScalarField(fieldKey);
        
        // Create sort direction select
        const directionSelect = this.createSortDirectionSelect(
            postType, fieldKey, isDateField, savedFieldSettings, sortableInput.checked, indexableInput.checked
        );
        
        return {
            indexableInput,
            weightInput,
            filterableInput,
            sortableInput,
            directionSelect
        };
    }

    /**
     * Create sort direction select element
     * @param {string} postType - The post type
     * @param {string} fieldKey - Field key
     * @param {boolean} isDateField - Whether this is a date field
     * @param {Object} savedFieldSettings - Saved field settings
     * @param {boolean} sortableChecked - Whether sortable is checked
     * @param {boolean} indexableChecked - Whether indexable is checked
     * @returns {HTMLElement} - Select element
     */
    createSortDirectionSelect(postType, fieldKey, isDateField, savedFieldSettings, sortableChecked, indexableChecked) {
        const directionSelect = document.createElement('select');
        directionSelect.name = `wp_loupe_fields[${postType}][${fieldKey}][sort_direction]`;
        directionSelect.className = 'wp-loupe-sort-direction';
        directionSelect.disabled = !sortableChecked || !indexableChecked;
        
        const ascOption = document.createElement('option');
        ascOption.value = 'asc';
        ascOption.textContent = this.__('Ascending', 'wp-loupe');
        ascOption.selected = savedFieldSettings.sort_direction === 'asc';
        
        const descOption = document.createElement('option');
        descOption.value = 'desc';
        descOption.textContent = this.__('Descending', 'wp-loupe');
        descOption.selected = isDateField ? true : (savedFieldSettings.sort_direction === 'desc' || !savedFieldSettings.sort_direction);
        
        directionSelect.appendChild(ascOption);
        directionSelect.appendChild(descOption);
        
        return directionSelect;
    }

    /**
     * Bind events to field row inputs
     * @param {HTMLElement} indexableInput - Indexable checkbox
     * @param {HTMLElement} weightInput - Weight input
     * @param {HTMLElement} filterableInput - Filterable checkbox
     * @param {HTMLElement} sortableInput - Sortable checkbox
     * @param {HTMLElement} directionSelect - Direction select
     */
    bindFieldRowEvents(indexableInput, weightInput, filterableInput, sortableInput, directionSelect) {
        indexableInput.addEventListener('change', () => {
            const isChecked = indexableInput.checked;
            weightInput.disabled = !isChecked;
            filterableInput.disabled = !isChecked;
            sortableInput.disabled = !isChecked || !this.isScalarField(sortableInput.name.split('[')[2]);
            directionSelect.disabled = !isChecked || !sortableInput.checked;
        });
    }

    /**
     * Check if a field is scalar (string or number) and can be sortable
     * @param {string} fieldName - Field name
     * @returns {boolean} - True if field can be sortable
     */
    isScalarField(fieldName) {
        // Taxonomy fields are arrays, not scalar
        if (fieldName.startsWith('taxonomy_')) {
            return false;
        }
        
        // Known non-scalar fields
        if (this.nonSortableFieldTypes.includes(fieldName)) {
            return false;
        }
        
        // Add more checks as needed
        
        return true;
    }
}

// Initialize the field manager when the DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    new WpLoupeFieldManager();
});