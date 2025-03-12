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
        this.nonSortableFieldTypes = ['post_content', 'post_excerpt'];
        this.pendingOperations = 0;
        
        // Track selected post types for comparison
        this.previouslySelectedPostTypes = Array.from(this.postTypeSelect?.selectedOptions || [])
            .map(option => option.value);
        
        this.statusMessageContainer = null;
        this.init();
    }

    /**
     * Initialize the manager
     */
    init() {
        this.createStatusMessage();
        this.preventFormSubmission();
        this.initializeSelect2();
        this.bindEvents();
        this.loadInitialFields();
    }

    /**
     * Create status message container
     */
    createStatusMessage() {
        if (!this.fieldConfigContainer) return;
        
        this.statusMessageContainer = document.createElement('div');
        this.statusMessageContainer.className = 'wp-loupe-status hidden';
        this.statusMessageContainer.style.marginTop = '10px';
        this.statusMessageContainer.style.padding = '10px 15px';
        this.statusMessageContainer.style.border = '1px solid #ccd0d4';
        this.statusMessageContainer.style.background = '#f8f9f9';
        
        this.fieldConfigContainer.parentNode.insertBefore(
            this.statusMessageContainer, 
            this.fieldConfigContainer
        );
    }

    /**
     * Show status message
     * @param {string} message - The message to display
     * @param {string} type - Message type ('info', 'success', 'error')
     */
    showStatusMessage(message, type = 'info') {
        if (!this.statusMessageContainer || !message) return;
        
        // Set message colors based on type
        let colors = {
            success: { border: '#46b450', bg: '#ecf7ed' },
            error: { border: '#dc3232', bg: '#fbeaea' },
            info: { border: '#00a0d2', bg: '#e5f5fa' }
        };
        
        const { border, bg } = colors[type] || colors.info;
        
        this.statusMessageContainer.style.borderColor = border;
        this.statusMessageContainer.style.background = bg;
        this.statusMessageContainer.textContent = message;
        this.statusMessageContainer.classList.remove('hidden');
        
        // Auto-hide after 10 seconds for success messages
        if (type === 'success') {
            setTimeout(() => {
                this.statusMessageContainer.classList.add('hidden');
            }, 10000);
        }
    }

    /**
     * Hide status message
     */
    hideStatusMessage() {
        if (this.statusMessageContainer) {
            this.statusMessageContainer.classList.add('hidden');
        }
    }

    /**
     * Initialize Select2 for post type selection
     */
    initializeSelect2() {
        if (!this.postTypeSelect) return;
        
        jQuery(this.postTypeSelect).select2().on('select2:select select2:unselect', async (e) => {
            const eventType = e.type === 'select2:select' ? 'adding' : 'removing';
            const postType = e.params?.data?.id;
            
            if (!postType) return;
            
            const currentlySelectedPostTypes = Array.from(this.postTypeSelect.selectedOptions)
                .map(option => option.value);
            const previouslySelectedPostTypes = [...this.previouslySelectedPostTypes];
            
            if (eventType === 'removing') {
                await this.handlePostTypeRemoval(postType);
            } else if (eventType === 'adding' && !previouslySelectedPostTypes.includes(postType)) {
                await this.handlePostTypeAddition(postType);
            }
            
            // Update UI to reflect current state
            jQuery(this.postTypeSelect).trigger('change.select2');
        });

        // Fix for Firefox that might not properly update the UI
        jQuery(this.postTypeSelect).on('change', () => {
            const selectedValues = Array.from(this.postTypeSelect.selectedOptions).map(opt => opt.value);
            jQuery(this.postTypeSelect).val(selectedValues);
            jQuery(this.postTypeSelect).trigger('change.select2');
        });
    }
    
    /**
     * Handle post type removal
     * @param {string} postType - The post type being removed
     */
    async handlePostTypeRemoval(postType) {
        this.showStatusMessage(
            `${this.__('Deleting database structure for', 'wp-loupe')}: ${postType}...`,
            'info'
        );
        
        try {
            this.pendingOperations++;
            
            // Make sure the post type is properly deselected in the DOM
            Array.from(this.postTypeSelect.options).forEach(option => {
                if (option.value === postType) {
                    option.selected = false;
                }
            });
            
            // Call the API to delete the database
            const response = await wp.apiFetch({
                path: 'wp-loupe/v1/delete-database',
                method: 'POST',
                data: { post_type: postType }
            });
            
            this.showStatusMessage(`${response.message}`, 'success');
            
            // Update tracking array and remove UI elements
            this.previouslySelectedPostTypes = this.previouslySelectedPostTypes
                .filter(type => type !== postType);
            this.removePostTypeSection(postType);
        } catch (error) {
            console.error(`Failed to delete database for ${postType}:`, error);
            this.showStatusMessage(
                `${this.__('Error deleting database for', 'wp-loupe')} ${postType}: ${error.message}`,
                'error'
            );
        } finally {
            this.pendingOperations--;
        }
    }
    
    /**
     * Handle post type addition
     * @param {string} postType - The post type being added
     */
    async handlePostTypeAddition(postType) {
        this.showStatusMessage(
            `${this.__('Creating database structure for', 'wp-loupe')}: ${postType}...`,
            'info'
        );
        
        try {
            this.pendingOperations++;
            
            // Call the API to create the database structure only (no indexing)
            const response = await wp.apiFetch({
                path: 'wp-loupe/v1/create-database',
                method: 'POST',
                data: { post_type: postType }
            });
            
            this.showStatusMessage(
                `${response.message} ${this.__('Please configure fields below and click Reindex to complete setup.', 'wp-loupe')}`,
                'success'
            );
            
            // Add to tracking array
            if (!this.previouslySelectedPostTypes.includes(postType)) {
                this.previouslySelectedPostTypes.push(postType);
            }
            
            // Add UI elements for this post type
            const fields = await this.getPostTypeFields(postType);
            this.addFieldConfigSection(postType, fields, true);
        } catch (error) {
            console.error(`Failed to create database for ${postType}:`, error);
            this.showStatusMessage(
                `${this.__('Error creating database for', 'wp-loupe')} ${postType}: ${error.message}`,
                'error'
            );
        } finally {
            this.pendingOperations--;
        }
    }

    /**
     * Bind event listeners
     */
    bindEvents() {
        document.addEventListener('change', (e) => {
            if (e.target.classList.contains('wp-loupe-sortable-toggle')) {
                const directionSelect = e.target.closest('tr')?.querySelector('.wp-loupe-sort-direction');
                if (directionSelect) {
                    directionSelect.disabled = !e.target.checked;
                }
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
        if (!this.fieldConfigContainer) return;
        
        // Get currently selected post types directly from DOM
        const selectedPostTypes = Array.from(this.postTypeSelect?.selectedOptions || [])
            .map(option => option.value);
        
        if (this.pendingOperations > 0) {
            // If operations are pending, wait a bit and check again
            setTimeout(() => this.updateFieldsConfig(newPostTypes), 100);
            return;
        }
        
        // Clear existing fields UI
        if (newPostTypes.length === 0) { // Only clear all if not adding specific types
            this.fieldConfigContainer.innerHTML = '';
        }
        
        if (selectedPostTypes.length === 0) {
            return;
        }
        
        for (const postType of selectedPostTypes) {
            // Skip if this section already exists and we're not refreshing everything
            if (newPostTypes.length > 0 && !newPostTypes.includes(postType) &&
                this.doesPostTypeSectionExist(postType)) {
                continue;
            }
            
            try {
                const fields = await this.getPostTypeFields(postType);
                if (Object.keys(fields).length > 0) {
                    this.addFieldConfigSection(postType, fields, newPostTypes.includes(postType));
                }
            } catch (error) {
                console.error(`Error loading fields for ${postType}:`, error);
            }
        }
    }

    /**
     * Check if a post type section already exists in the UI
     * @param {string} postType - The post type to check
     * @returns {boolean} - True if the section exists
     */
    doesPostTypeSectionExist(postType) {
        if (!this.fieldConfigContainer) return false;
        
        const sections = this.fieldConfigContainer.querySelectorAll('.wp-loupe-post-type-fields');
        for (const section of sections) {
            const header = section.querySelector('h3');
            if (header && header.textContent === postType) {
                return true;
            }
        }
        return false;
    }

    /**
     * Remove a post type section from the UI
     * @param {string} postType - The post type to remove
     */
    removePostTypeSection(postType) {
        if (!this.fieldConfigContainer) return;
        
        const sections = this.fieldConfigContainer.querySelectorAll('.wp-loupe-post-type-fields');
        for (const section of sections) {
            const header = section.querySelector('h3');
            if (header && header.textContent === postType) {
                section.remove();
                break;
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
        // Remove existing section for this post type if it exists
        this.removePostTypeSection(postType);
        
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
        
        // Check if this field is sortable (scalar)
        const isScalar = this.isScalarField(fieldKey);
        
        // Create sortable input
        const sortableInput = document.createElement('input');
        sortableInput.type = 'checkbox';
        sortableInput.name = `wp_loupe_fields[${postType}][${fieldKey}][sortable]`;
        sortableInput.value = '1';
        sortableInput.className = 'wp-loupe-sortable-toggle';
        // Only check by default for new post types if it's a scalar field
        sortableInput.checked = (isNewPostType && isScalar) || isDateField || savedFieldSettings.sortable;
        sortableInput.disabled = !indexableInput.checked || !isScalar;
        
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
        
        const options = [
            { value: 'asc', text: this.__('Ascending', 'wp-loupe'),
              selected: savedFieldSettings.sort_direction === 'asc' },
            { value: 'desc', text: this.__('Descending', 'wp-loupe'), 
              selected: isDateField || savedFieldSettings.sort_direction === 'desc' 
                        || !savedFieldSettings.sort_direction }
        ];
        
        options.forEach(option => {
            const optionElement = document.createElement('option');
            optionElement.value = option.value;
            optionElement.textContent = option.text;
            optionElement.selected = option.selected;
            directionSelect.appendChild(optionElement);
        });
        
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
            
            const fieldName = sortableInput.name.split('[')[2];
            sortableInput.disabled = !isChecked || !this.isScalarField(fieldName);
            directionSelect.disabled = !isChecked || !sortableInput.checked;
        });
    }

    /**
     * Check if a field is scalar (string or number) and can be sortable
     * @param {string} fieldName - Field name
     * @returns {boolean} - True if field can be sortable
     */
    isScalarField(fieldName) {
        // Remove the closing bracket if it exists in the fieldName
        const cleanFieldName = fieldName ? fieldName.replace(/\].*$/, '') : '';
        
        // Taxonomy fields are arrays, not scalar
        if (cleanFieldName.startsWith('taxonomy_')) {
            return false;
        }
        
        // Known non-scalar fields
        if (this.nonSortableFieldTypes.includes(cleanFieldName)) {
            return false;
        }
        
        return true;
    }

    /**
     * Prevent form submission when pressing enter
     */
    preventFormSubmission() {
        const form = this.postTypeSelect?.form;
        if (!form) return;
        
        const originalSubmit = form.onsubmit;
        form.onsubmit = (e) => {
            // Allow submit button (Reindex) submissions
            if (e.submitter && e.submitter.type === 'submit') {
                this.showStatusMessage(
                    this.__('Starting full reindex of all selected post types. This may take a moment...', 'wp-loupe'),
                    'info'
                );
                return originalSubmit ? originalSubmit(e) : true;
            }
            e.preventDefault();
            return false;
        };
    }
}

// Initialize the field manager when the DOM is loaded
document.addEventListener('DOMContentLoaded', () => {
    new WpLoupeFieldManager();
});

// Add DOM utility functions
if (!Element.prototype.matches) {
    Element.prototype.matches = Element.prototype.msMatchesSelector || 
                              Element.prototype.webkitMatchesSelector;
}

if (!Element.prototype.closest) {
    Element.prototype.closest = function(s) {
        let el = this;
        do {
            if (el.matches(s)) return el;
            el = el.parentElement || el.parentNode;
        } while (el !== null && el.nodeType === 1);
        return null;
    };
}

// Add jQuery extension for text search
jQuery.expr[':'].contains = function(a, i, m) {
    return jQuery(a).text().toUpperCase()
        .indexOf(m[3].toUpperCase()) >= 0;
};