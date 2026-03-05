jQuery(document).ready(function ($) {
    // ✅ INIT Select2 for Create Modal
    $('#tahefobu_display_targets').select2({
       placeholder: "Select Display Targets",
       width: '100%',
       closeOnSelect: false
   });

   // ✅ INIT Select2 for Edit Modal
   $('#tahefobu_edit_display_targets').select2({
       placeholder: "Select Display Targets",
       width: '100%',
       closeOnSelect: false
   });
   // AJAX success block
   $('.tahefobu-edit-conditions-button').on('click', function () {
       const postId = $(this).data('post-id');
       $('#tahefobu_conditions_post_id').val(postId); // set hidden input value

       // 🔄 AJAX to fetch saved conditions
       $.post(ajaxurl, {
           action: 'tahefobu_get_header_conditions',
           post_id: postId
       }, function (response) {
           if (response.success) {
               $('#tahefobu_edit_include_pages').val(response.data.include).trigger('change');
               $('#tahefobu_edit_exclude_pages').val(response.data.exclude).trigger('change');

               $('#tahefobu_edit_is_sticky').prop('checked', response.data.is_sticky == 1);
               $('#tahefobu_edit_has_animation').prop('checked', response.data.has_animation == 1);
               $('#tahefobu_edit_display_targets').val(response.data.display_targets).trigger('change');

               $('#tahefobu-conditions-modal').fadeIn();
               // ✅ Init Select2
               $('#tahefobu_edit_include_pages, #tahefobu_edit_exclude_pages').select2({
                   width: '100%',
                   placeholder: 'Select pages',
                   allowClear: true
               });
           } else {
               alert('Failed to load conditions.');
           }
       });
   });


   $('#tahefobu-cancel-condition-edit').on('click', function () {
       $('#tahefobu-conditions-modal').fadeOut();
   });

   $('#tahefobu-save-condition-edit').on('click', function () {
   const postId = $('#tahefobu_conditions_post_id').val();
   const include = $('#tahefobu_edit_include_pages').val();
   const exclude = $('#tahefobu_edit_exclude_pages').val();
   const isSticky = $('#tahefobu_edit_is_sticky').is(':checked') ? 1 : 0;
   const hasAnimation = $('#tahefobu_edit_has_animation').is(':checked') ? 1 : 0;
   const displayTargets = $('#tahefobu_edit_display_targets').val();

   $.post(ajaxurl, {
       action: 'tahefobu_save_header_conditions',
       post_id: postId,
       include_pages: include,
       exclude_pages: exclude,
       is_sticky: isSticky,
       has_animation: hasAnimation,
       display_targets: displayTargets,
       _ajax_nonce: tahefobu_conditions_nonce_obj.tahefobu_conditions_nonce, // ✅ CORRECT field name
   }, function (res) {
       if (res.success) {
           const $saveBtn = $('#tahefobu-save-condition-edit');

           // ✅ Change button text and style
           $saveBtn.text('✓ Saved').removeClass('tahefobu_condition-save-button-removed').addClass('tahefobu_condition-save-button-success');

           // ✅ Create close button dynamically if not already there
           if (!$('#tahefobu-conditions-close-button').length) {
               $('<button id="tahefobu-conditions-close-button" class="button tahefobu-header-cancel-button" style="margin-left: 10px;">×</button>')
                   .insertAfter($saveBtn)
                   .on('click', function () {
                       $('#tahefobu-conditions-modal').fadeOut();
                       $saveBtn.text('Save').removeClass('tahefobu_condition-save-button-success').addClass('tahefobu_condition-save-button-removed');
                       $(this).remove();
                   });
           }
       } else {
           alert('Failed to save.');
       }
   });
});
});

wp.data.select('core').getEntityRecord('postType', 'tahefobu_header', 3071)
