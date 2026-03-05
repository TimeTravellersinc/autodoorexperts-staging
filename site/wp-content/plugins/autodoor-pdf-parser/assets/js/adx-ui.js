(function($){
  function uiConfig(){
    return window.ADX_UI_CONFIG || {};
  }

  function debugEnabled(){
    var v = uiConfig().debugEnabled;
    return v === 1 || v === true || v === '1';
  }

  function renderDebug(channels){
    if (!debugEnabled()) {
      return;
    }

    var map = {
      extract: '#adx-debug-extract',
      split: '#adx-debug-split',
      parse: '#adx-debug-parse',
      scope: '#adx-debug-scope'
    };

    Object.keys(map).forEach(function(k){
      var el = $(map[k]);
      if (!el.length) {
        return;
      }
      var lines = (channels && channels[k]) ? channels[k] : [];
      el.text(lines.join('\n'));
    });
  }

  function setStatus(msg, isError){
    $('#adx-status').text(msg).toggleClass('adx-error', !!isError);
  }

  function setBusy(isBusy){
    var btn = $('#adx-submit');
    if (!btn.length) {
      return;
    }
    btn.prop('disabled', !!isBusy);
    btn.text(isBusy ? 'Processing...' : 'Generate Outputs');
  }

  function setLink(selector, url){
    var el = $(selector);
    if (!el.length) {
      return;
    }
    if (url) {
      el.attr('href', url).show();
    } else {
      el.attr('href', '#').hide();
    }
  }

  function resetOutputs(){
    setLink('#adx-download-parser', '');
    setLink('#adx-download-scope', '');
    setLink('#adx-download-quote', '');
    setLink('#adx-download-quote-debug', '');

    $('#adx-quote-notice').text('');
    $('#adx-preview-quote').text('');

    if (debugEnabled()) {
      $('#adx-preview-parser').text('');
      $('#adx-preview-scope').text('');
      renderDebug({extract: [], split: [], parse: [], scope: []});
    }
  }

  function renderQuoteSummary(data){
    var notice = (data && data.quote_notice) ? data.quote_notice : '';
    $('#adx-quote-notice').text(notice);

    var lines = [];
    if (data && data.line_item_count) {
      lines.push('Line items: ' + data.line_item_count);
    }

    if (data && data.quote_summary) {
      var s = data.quote_summary;
      if (typeof s.subtotal !== 'undefined') {
        lines.push('Subtotal: $' + Number(s.subtotal).toFixed(2));
      }
      if (typeof s.tax !== 'undefined') {
        lines.push('Tax: $' + Number(s.tax).toFixed(2));
      }
      if (typeof s.total !== 'undefined') {
        lines.push('Total: $' + Number(s.total).toFixed(2));
      }
    }

    if (data && data.preview_quote) {
      lines.push('');
      lines.push(data.preview_quote);
    }

    $('#adx-preview-quote').text(lines.join('\n'));
  }

  $(document).on('submit', '#adx-form', function(e){
    e.preventDefault();

    var form = this;
    var config = uiConfig();
    var fd = new FormData(form);
    fd.append('action', 'adx_parse_pdf');

    if (!fd.get('adx_nonce') && config.nonce) {
      fd.append('adx_nonce', config.nonce);
    }
    if (fd.get('adx_debug_mode') === null) {
      fd.append('adx_debug_mode', debugEnabled() ? '1' : '0');
    }

    setBusy(true);
    setStatus('Processing...', false);
    resetOutputs();

    $.ajax({
      url: config.ajaxUrl,
      method: 'POST',
      data: fd,
      processData: false,
      contentType: false,
      dataType: 'json'
    }).done(function(resp){
      if (!resp) {
        setStatus('No response from server.', true);
        return;
      }

      if (resp.success) {
        var data = resp.data || {};
        setLink('#adx-download-parser', data.download_url_parser || '');
        setLink('#adx-download-scope', data.download_url_scope || '');
        setLink('#adx-download-quote', data.download_url_quote || '');
        setLink('#adx-download-quote-debug', data.download_url_quote_debug || '');

        if (debugEnabled()) {
          $('#adx-preview-parser').text(data.preview_parser || '');
          $('#adx-preview-scope').text(data.preview_scope || '');
          renderDebug(data.debug || {});
        }

        renderQuoteSummary(data);
        setStatus(data.quote_notice || 'Outputs generated successfully.', false);
      } else {
        var message = (resp.data && resp.data.message) ? resp.data.message : 'Processing failed.';
        setStatus(message, true);
        if (debugEnabled() && resp.data && resp.data.debug) {
          renderDebug(resp.data.debug);
        }
      }
    }).fail(function(xhr){
      setStatus('Request failed. Please try again.', true);
      if (debugEnabled()) {
        try {
          var body = xhr.responseJSON;
          if (body && body.data && body.data.debug) {
            renderDebug(body.data.debug);
          }
        } catch (e) {}
      }
    }).always(function(){
      setBusy(false);
    });
  });
})(jQuery);
