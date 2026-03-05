jQuery(document).ready(function ($) {

    $('#tahefobu_display_targets').select2({
        placeholder: "Select Display Targets",
        width: '100%',
        closeOnSelect: false
    });

    $('#tahefobu_edit_display_targets').select2({
        placeholder: "Select Display Targets",
        width: '100%',
        closeOnSelect: false
    });
    
// Apply Select2 styling to both selects
    $('#tahefobu_include_pages, #tahefobu_exclude_pages').select2({
        width: '100%',
        placeholder: 'Select pages',
        allowClear: true,
        closeOnSelect: false,
        dropdownParent: $('#tahefobu-header-template-popup')
    });
    
    // 1. Intercept "Add New" button
    const addNewBtn = $('a.page-title-action');
    if (addNewBtn.length && window.location.href.includes('post_type=tahefobu_header')) {
        addNewBtn.on('click', function (e) {
            e.preventDefault();
            $('#tahefobu-header-template-popup').fadeIn();
        });
    }

    // 2. Cancel popup
    $('#tahefobu-cancel-template').on('click', function () {
        $('#tahefobu-header-template-popup').fadeOut();
    });

    // 3. Create template via AJAX
    $('#tahefobu-create-template').on('click', function () {
    const title = $('#tahefobu-header-template-title').val().trim();
    const includePages = $('#tahefobu_include_pages').val() || [];
    const excludePages = $('#tahefobu_exclude_pages').val() || [];
    const isSticky = $('#tahefobu_is_sticky').is(':checked') ? 1 : 0;
    const hasAnimation = $('#tahefobu_has_animation').is(':checked') ? 1 : 0;
    const displayTargets = $('#tahefobu_display_targets').val() || [];

    if (!title) {
        alert('Please enter a template name.');
        return;
    }

    $.post(ajaxurl, {
        action: 'tahefobu_create_header_template',
        title: title,
        include_pages: includePages,
        exclude_pages: excludePages,
        is_sticky: isSticky,
        has_animation: hasAnimation,
        display_targets: displayTargets,
        _ajax_nonce: tahefobu_header_condition_nonce.nonce
    }, function (response) {
        if (response.success && response.data.edit_link) {
            window.location.href = response.data.edit_link;
        } else {
            alert(response.data.message || 'Something went wrong.');
        }
    });
});



    // Select All Include
    $(document).on('change', '#select_all_include', function () {
        if ($(this).is(':checked')) {
            $('#tahefobu_include_pages > option').prop('selected', true);
        } else {
            $('#tahefobu_include_pages > option').prop('selected', false);
        }
        $('#tahefobu_include_pages').trigger('change');
    });

    // Select All Exclude
    $(document).on('change', '#select_all_exclude', function () {
        if ($(this).is(':checked')) {
            $('#tahefobu_exclude_pages > option').prop('selected', true);
        } else {
            $('#tahefobu_exclude_pages > option').prop('selected', false);
        }
        $('#tahefobu_exclude_pages').trigger('change');
    });

    // Edit Conditions Button Click
    $(document).on('click', '.tahefobu-edit-conditions-button', function () {
        const postId = $(this).data('post-id');
        $('#tahefobu_conditions_post_id').val(postId);
        
        // Load existing conditions
        $.post(ajaxurl, {
            action: 'tahefobu_get_header_conditions',
            post_id: postId,
            _ajax_nonce: tahefobu_header_condition_nonce.nonce
        }, function (response) {
            if (response.success) {
                const data = response.data;
                
                // Set include pages
                $('#tahefobu_edit_include_pages').val(data.include).trigger('change');
                
                // Set exclude pages
                $('#tahefobu_edit_exclude_pages').val(data.exclude).trigger('change');
                
                // Set display targets
                $('#tahefobu_edit_display_targets').val(data.display_targets).trigger('change');
                
                // Set checkboxes
                $('#tahefobu_edit_is_sticky').prop('checked', data.is_sticky == 1);
                $('#tahefobu_edit_has_animation').prop('checked', data.has_animation == 1);
                
                // Show modal
                $('#tahefobu-conditions-modal').fadeIn();
            }
        });
    });

    // Cancel Edit Conditions
    $('#tahefobu-cancel-condition-edit').on('click', function () {
        $('#tahefobu-conditions-modal').fadeOut();
    });

    // Save Edit Conditions
    $('#tahefobu-save-condition-edit').on('click', function () {
        const postId = $('#tahefobu_conditions_post_id').val();
        const includePages = $('#tahefobu_edit_include_pages').val() || [];
        const excludePages = $('#tahefobu_edit_exclude_pages').val() || [];
        const isSticky = $('#tahefobu_edit_is_sticky').is(':checked') ? 1 : 0;
        const hasAnimation = $('#tahefobu_edit_has_animation').is(':checked') ? 1 : 0;
        const displayTargets = $('#tahefobu_edit_display_targets').val() || [];

        $.post(ajaxurl, {
            action: 'tahefobu_save_header_conditions',
            post_id: postId,
            include_pages: includePages,
            exclude_pages: excludePages,
            is_sticky: isSticky,
            has_animation: hasAnimation,
            display_targets: displayTargets,
            _ajax_nonce: tahefobu_header_condition_nonce.nonce
        }, function (response) {
            if (response.success) {
                $('#tahefobu-conditions-modal').fadeOut();
                location.reload(); // Refresh to show updated data
            } else {
                alert('Error saving conditions');
            }
        });
    });

    // Apply Select2 to edit modal selects
    $('#tahefobu_edit_include_pages, #tahefobu_edit_exclude_pages').select2({
        width: '100%',
        placeholder: 'Select pages',
        allowClear: true,
        closeOnSelect: false,
        dropdownParent: $('#tahefobu-conditions-modal')
    });

});
