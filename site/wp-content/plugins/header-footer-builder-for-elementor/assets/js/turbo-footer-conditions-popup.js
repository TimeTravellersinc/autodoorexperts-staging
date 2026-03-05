jQuery(document).ready(function ($) {

    // Inject the edit condition modal HTML once
    const conditionModal = `
        <div id="tahefobu-footer-condition-modal" class="tahefobu-header-popup-overlay" style="display:none;">
            <div class="tahefobu-header-popup-modal">
               <div class="modal-header-style">
                    <h2 class="tahefobu-create-header-popup-headline">Edit Footer Condition</h2>
                    <img src="${tahefobu_footer_logo.url}" alt="Turbo Addons Logo" style="max-height:30px;">
             </div>

                <input type="hidden" id="tahefobu_footer_condition_post_id" />

                <div class="modal-include-exclude-style">
                    <label>Include Pages:</label><br>
                </div> 
                <select id="tahefobu_footer_include_pages" multiple style="width:100%; min-height:100px; margin-bottom: 15px;"></select>
               
                <div class="modal-include-exclude-style">
                <label>Set Display Condition:</label><br>
                </div>
                <select id="tahefobu_footer_display_targets" multiple style="width:100%; min-height:50px; margin-bottom: 15px;">
                    <option value="entire_site">Entire Site</option>
                    <option value="all_pages">All Pages</option>
                    <option value="all_posts">All Posts</option>
                </select>
        
                <div class="modal-include-exclude-style">
                    <label>Exclude Pages:</label><br>
                </div> 
                <select id="tahefobu_footer_exclude_pages" multiple style="width:100%; min-height:100px;"></select>

                <div class="tahefobu-header-popup-actions" style="margin-top: 15px;">
                    <button class="button tahefobu-header-creat-edit-button" id="tahefobu-save-footer-conditions">Update</button>
                    <button class="button tahefobu-header-cancel-button" id="tahefobu-cancel-footer-conditions">Cancel</button>
                </div>
            </div>
        </div>
    `;
    $('body').append(conditionModal);



$('#tahefobu_footer_include_pages').html('<option>Loading...</option>');
$('#tahefobu_footer_exclude_pages').html('<option>Loading...</option>');
    // Open modal when "Edit Conditions" button is clicked
    $(document).on('click', '.tahefobu-footer-edit-conditions-button', function () {
        const postId = $(this).data('post-id');
        $('#tahefobu_footer_condition_post_id').val(postId);
        $('#tahefobu-footer-condition-modal').fadeIn();

        // Load saved values
        $.post(ajaxurl, {
            action: 'tahefobu_get_footer_conditions_popup',
            post_id: postId,
            _ajax_nonce: tahefobu_footer_conditions.nonce
        }, function (res) {
            if (res.success) {
                const allPages = tahefobu_footer_conditions.pages;
                const includeSelected = res.data.include;
                const excludeSelected = res.data.exclude;
                const selectedTargets = res.data.targets;

                // Clear existing
                $('#tahefobu_footer_include_pages').empty();
                $('#tahefobu_footer_exclude_pages').empty();

                allPages.forEach(page => {
                    const incSelected = includeSelected.includes(page.id.toString()) ? 'selected' : '';
                    const excSelected = excludeSelected.includes(page.id.toString()) ? 'selected' : '';
                    $('#tahefobu_footer_include_pages').append(`<option value="${page.id}" ${incSelected}>${page.title}</option>`);
                    $('#tahefobu_footer_exclude_pages').append(`<option value="${page.id}" ${excSelected}>${page.title}</option>`);
                });

                // $('#tahefobu_footer_display_targets').val(selectedTargets).trigger('change');
                // 1. Define target options
                const allTargets = [
                    { id: 'entire_site', text: 'Entire Site' },
                    { id: 'all_pages', text: 'All Pages' },
                    { id: 'all_posts', text: 'All Posts' }
                ];

                // 2. Clear existing and re-add options
                $('#tahefobu_footer_display_targets').empty();
                allTargets.forEach(opt => {
                    const selected = selectedTargets.includes(opt.id) ? 'selected' : '';
                    $('#tahefobu_footer_display_targets').append(`<option value="${opt.id}" ${selected}>${opt.text}</option>`);
                });

                // 3. Re-init Select2 and set value
                $('#tahefobu_footer_display_targets').select2({
                    width: '100%',
                    placeholder: 'Select options',
                    allowClear: true
                }).val(selectedTargets).trigger('change');

                // select2 css//
                $('#tahefobu_footer_include_pages, #tahefobu_footer_exclude_pages, #tahefobu_footer_display_targets').select2({
                    width: '100%',
                    placeholder: 'Select options',
                    allowClear: true
                });

                // Initialize or re-init Select2
                $('#tahefobu_footer_include_pages, #tahefobu_footer_exclude_pages').select2({
                    width: '100%',
                    placeholder: 'Select pages',
                    allowClear: true
                });
            } else {
                alert('Failed to load conditions.');
            }
        });
    });

    // Save updated conditions
    $(document).on('click', '#tahefobu-save-footer-conditions', function () {
        const postId = $('#tahefobu_footer_condition_post_id').val();
        const include = $('#tahefobu_footer_include_pages').val() || [];
        const exclude = $('#tahefobu_footer_exclude_pages').val() || [];
        const displayTargets = $('#tahefobu_footer_display_targets').val() || [];

        $.post(ajaxurl, {
            action: 'tahefobu_save_footer_conditions',
            post_id: postId,
            include_pages: include,
            exclude_pages: exclude,
            display_targets: displayTargets,
            _ajax_nonce: tahefobu_footer_conditions.nonce
        }, function (res) {
            if (res.success) {
                alert('Footer conditions updated successfully.');
                $('#tahefobu-footer-condition-modal').fadeOut();
            } else {
                alert('Failed to save conditions.');
            }
        });
    });

    // Cancel and close modal
    $(document).on('click', '#tahefobu-cancel-footer-conditions', function () {
        $('#tahefobu-footer-condition-modal').fadeOut();
    });
});
