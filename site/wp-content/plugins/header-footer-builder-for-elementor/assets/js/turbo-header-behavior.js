jQuery(function ($) {
  // Skip behavior inside Elementor preview / our CPT editors
  if (
    $('body').is('.tahefobu-header-template-editor, .tahefobu-footer-template-editor') ||
    window.location.search.indexOf('elementor-preview') !== -1
  ) return;

  // Prefer the new wrapper if present, else fall back to your existing class
  var $wrap = $('#tahefobu-header');
  if (!$wrap.length) {
    $wrap = $('.turbo-header-template').first();
    if (!$wrap.length) return;
  }
$wrap.addClass('tahefobu-ready');
  // Read sticky/animation flags
  var sticky = $wrap.data('sticky');
  var anim   = $wrap.data('animation');
  // Back-compat if data-* not present: infer from classes
  sticky = String(sticky === undefined ? $wrap.hasClass('ta-sticky-header') : sticky) === '1';
  anim   = String(anim   === undefined ? $wrap.hasClass('ta-header-scroll-animation') : anim) === '1';

  // Admin-bar offset so header doesn't hide under it
  var adminBarH = $('#wpadminbar').length ? $('#wpadminbar').outerHeight() : 0;
  $wrap.css('--ta-sticky-top', adminBarH + 'px');

  var $spacer = null, headerTop = 0, headerH = 0;

  function recalc() {
    headerH   = $wrap.outerHeight();
    headerTop = ($spacer && $spacer.is(':visible')) ? $spacer.offset().top : $wrap.offset().top;
  }

  function onScrollSticky() {
    var sc = window.pageYOffset || document.documentElement.scrollTop;

    // Fixed fallback when scrolled past the original top
    if (sc > headerTop) {
      if (!$wrap.hasClass('ta-sticky-active')) {
        headerH = $wrap.outerHeight();
        if (!$spacer) $spacer = $('<div class="ta-header-spacer" />').insertBefore($wrap).hide();
        $spacer.height(headerH).show();          // prevent layout jump
        $wrap.addClass('ta-sticky-active');
      }
    } else {
      if ($wrap.hasClass('ta-sticky-active')) {
        $wrap.removeClass('ta-sticky-active');
        if ($spacer) $spacer.hide();
      }
    }
  }

  if (sticky) {
    recalc();
    $(window).on('scroll.taSticky resize.taSticky', function () {
      recalc();
      onScrollSticky();
    });
    onScrollSticky();
  }

  if (anim) {
    var lastY = window.pageYOffset || document.documentElement.scrollTop;
    // Start visible
    $wrap.addClass('ta-scroll-up').removeClass('ta-scroll-down');

    $(window).on('scroll.taAnim', function () {
      var y = window.pageYOffset || document.documentElement.scrollTop;
      var down = y > lastY;

      // New animation classes
      $wrap.toggleClass('ta-scroll-down', down);
      $wrap.toggleClass('ta-scroll-up', !down);

      // Back-compat with your existing classes/thresholds
      if (down && y > 200) {
        $wrap.removeClass('ta-header-show').addClass('ta-header-hide ta-header-hidden');
      } else if (!down && y > 80) {
        $wrap.removeClass('ta-header-hide ta-header-hidden').addClass('ta-header-show');
      }

      lastY = y;
    });
  }
});
