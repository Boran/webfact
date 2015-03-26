(function($) {

  $.fn.ajax_link = function() {
    $('#ajax-link').hide();
    setTimeout(function() {
      $('#ajax-display').fadeOut().html("").show();
      $('#ajax-link').fadeIn();
    }, 5000)
  }

  $.fn.myJavascriptFunction = function(data) {
      alert(data);
  };

})(jQuery);
