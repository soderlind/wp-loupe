/* global wpLoupeAdmin, wp */
jQuery(document).ready(function($) {
    const fieldConfigContainer = $('#wp-loupe-fields-config');
    const postTypeSelect = $('#wp_loupe_custom_post_types');
    const savedFields = wpLoupeAdmin.savedFields || {};
    const protectedFields = []; // Now empty, previously had post_title and post_date
    
    // Initialize fields configuration when post type selection changes
    postTypeSelect.on('change', function() {
        updateFieldsConfig();
    });

    async function updateFieldsConfig() {
        const selectedPostTypes = postTypeSelect.val();
        fieldConfigContainer.empty();

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
        const section = $('<div class="wp-loupe-post-type-fields"/>').appendTo(fieldConfigContainer);
        
        // Add post type header
        $('<h3/>').text(postType).appendTo(section);
        
        // Create field configuration table
        const table = $('<table class="wp-loupe-fields-table widefat"/>').appendTo(section);
        
        // Add table header
        const header = $('<thead/>').appendTo(table);
        $('<tr/>').appendTo(header).append(
            $('<th/>').text('Field'),
            $('<th/>').text('Indexable'),
            $('<th/>').text('Weight'),
            $('<th/>').text('Filterable'),
            $('<th/>').text('Sortable'),
            $('<th/>').text('Sort Direction')
        );

        // Add table body
        const tbody = $('<tbody/>').appendTo(table);
        
        // Add rows for each field
        Object.entries(fields).forEach(([fieldKey, fieldData]) => {
            const fieldLabel = typeof fieldData === 'string' ? fieldData : fieldData.label;
            const savedFieldSettings = savedFields[postType]?.[fieldKey] || {};
            
            const isProtected = protectedFields.includes(fieldKey);
            const isTitle = fieldKey === 'post_title';

            const row = $('<tr/>').appendTo(tbody);
            
            // Field name
            $('<td/>').append(
                $('<label/>').text(fieldLabel)
            ).appendTo(row);
            
            // Indexable checkbox
            $('<td/>').append(
                $('<input/>', {
                    type: 'checkbox',
                    name: `wp_loupe_fields[${postType}][${fieldKey}][indexable]`,
                    value: '1',
                    checked: isProtected || savedFieldSettings.indexable !== false,
                    disabled: isProtected
                })
            ).appendTo(row);
            
            // Weight input
            $('<td/>').append(
                $('<input/>', {
                    type: 'number',
                    step: '0.1',
                    min: '0',
                    name: `wp_loupe_fields[${postType}][${fieldKey}][weight]`,
                    value: savedFieldSettings.weight || (isTitle ? '2.0' : '1.0'),
                    class: 'small-text'
                })
            ).appendTo(row);
            
            // Filterable checkbox
            $('<td/>').append(
                $('<input/>', {
                    type: 'checkbox',
                    name: `wp_loupe_fields[${postType}][${fieldKey}][filterable]`,
                    value: '1',
                    checked: savedFieldSettings.filterable
                })
            ).appendTo(row);
            
            // Sortable checkbox
            const sortableCell = $('<td/>').appendTo(row);
            $('<input/>', {
                type: 'checkbox',
                name: `wp_loupe_fields[${postType}][${fieldKey}][sortable]`,
                value: '1',
                class: 'wp-loupe-sortable-toggle',
                checked: savedFieldSettings.sortable
            }).appendTo(sortableCell);
            
            // Sort direction select
            $('<td/>').append(
                $('<select/>', {
                    name: `wp_loupe_fields[${postType}][${fieldKey}][sort_direction]`,
                    class: 'wp-loupe-sort-direction',
                    disabled: !savedFieldSettings.sortable
                }).append(
                    $('<option/>', {
                        value: 'asc',
                        text: 'Ascending',
                        selected: savedFieldSettings.sort_direction === 'asc'
                    }),
                    $('<option/>', {
                        value: 'desc',
                        text: 'Descending',
                        selected: savedFieldSettings.sort_direction === 'desc' || !savedFieldSettings.sort_direction
                    })
                )
            ).appendTo(row);
        });
    }

    // Handle sortable checkbox changes
    $(document).on('change', '.wp-loupe-sortable-toggle', function() {
        const directionSelect = $(this).closest('tr').find('.wp-loupe-sort-direction');
        directionSelect.prop('disabled', !this.checked);
    });

    // Initial load of fields for pre-selected post types
    updateFieldsConfig();
});