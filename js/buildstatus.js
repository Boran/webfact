/*
 * Update the build status and docker status on /advanced,
 * query status regularly from /website/coget/<nid>
 * see controller.php
 */
// Using the closure to map jQuery to $.
(function ($) {
  
  Drupal.behaviors.buildCheck = {
    attach: function(context, settings) {
      //console.log(settings.webfact);
      // Run only when website is created, but waiting for final script run.
      if (settings.webfact.webfact_site_check == 1) {
        
        //time_interval = 30000;   // ms
        time_interval = settings.webfact.time_interval;   // ms
        url = '/website/coget/' + settings.webfact.webfact_nid;
        //console.log('buildCheck ' +url +' ' +time_interval);
        
        var refreshIntervalId = setInterval(function(){
          $.ajax({
            type: 'GET', url: url,
            success : function(response) {
              //console.log('buildCheck status,build=' +response.status +' ' +response.buildStatus);

              if (response.buildStatus == 100 || response.buildStatus == 200) {
                $('#buildstatus').html('<div class="running">completed(' +response.buildStatus +')</div>' );
                //$('#bs-postfix').html('completed');

                // if there is an alert message box, replace it with "done"
                $('.alert-success').html('Done');
              }
              else if (response.buildStatus==null) {
                $('#buildstatus').text('n/a');
              }
              else {
                $('#buildstatus').text('(unfinished) ' +response.buildStatus);
              }

              // sttaus, setting div to rhuning/stopped etc for colouring
              $('#website-status').html('<div class=' +response.status 
                +'>' +response.status +'</div>');

              // reset timer?
              //clearInterval(refreshIntervalId);
              //setInterval(location.reload(), time_interval);
            }
          });
        }, time_interval);
      }
    }
  }

  $(document).ready(function () {

  });
}(jQuery));

