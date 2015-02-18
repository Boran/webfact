<?php

/**
 * @file views-view-field--websites.tpl.php
 * Customising the output of specifc fields in the webistes view
 *
 * Variables available:
 * - $view: The view object
 * - $field: The field handler object that can process the input
 * - $row: The raw SQL result that can be used
 * - $output: The processed output that will normally be used.
 *
 * When fetching output from the $row, this construct should be used:
 * $data = $row->{$field->field_alias}
 *
 */

#dpm($row);
#dpm($field->options['label']);

  // get the status field
  if ($field->options['label']  == 'Status') {
    $w = new WebfactController;
    if (! $w) { return; }
    $result = $w->getStatus($row->nid); 
    print "<div class=website-status>";
    if ( ($result=='running') || ($result=='stopped') ) {
      print "<div class=$result>$result</div>";
    }
    else {
      print "$result";
    }
    print "</div>";
  }


  else if ($field->options['label']  == 'Website') {
    print "<div class=website-url>";
    #dpm($row->field_field_hostname[0]['raw']['safe_value']);
    if (isset($row->field_field_hostname[0]['raw']['safe_value'])) {
      $fserver   = variable_get('webfact_fserver', 'mywildcard.example.ch');
      $hostname=$row->field_field_hostname[0]['raw']['safe_value'] . ".$fserver";
      print "<a target=_blank href=http://$hostname>$hostname</a>";
    }
    else {
      print 'unknown';
    }
    print "</div>";
  }


  else if ($field->options['label']  == 'Menu') {
    #global $base_url;
    $destination = drupal_get_destination();
    $des = '?destination=' . $destination['destination']; // remember where we were

    // show admins "Edit template" menu
    $template_menu="";
    $node=node_load($row->nid);
    //if ( user_access('manage containers')) {
    if ( user_access('Template: Edit any content')) {
        // Load the associated template
        if (isset($node->field_template['und'][0]['target_id'])) {
          $tid=$node->field_template['und'][0]['target_id'];
          #$template=node_load($tid);
          $template_menu="<li><a href=/node/$tid/edit$des>Edit template</a></li>";
        }
    }

    $html = <<<END
<!-- Bootstrap: Single button -->
<div class="btn-group">
  <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-expanded="false">
    Action <span class="caret"></span>
  </button>
  <ul class="dropdown-menu" role="menu">
    <li><a href="/website/stop/$row->nid$des">Stop</a></li>
    <li><a href="/website/start/$row->nid$des">Start</a></li>
    <li class="divider"></li>
    <li><a href="/node/$row->nid/edit$des">Edit meta data</a></li>
    <li><a href="/website/create/$row->nid$des">Create</a></li>
    $template_menu
    <li class="divider"></li>
    <li><a href="/website/advanced/$row->nid">Advanced</a></li>
    <li><a href="/website/logs/$row->nid">logs</a></li>
    <li><a href="/website/inspect/$row->nid/0">status</a></li>
    <li><a href="/website/processes/$row->nid">processes</a></li>
  </ul>
</div>
END;
    print $html;
    // unused menu items
    //<li><a href="/website/restart/$row->nid$des">Restart</a></li>
    //<li><a href="/node/$row->nid$des">View meta data</a></li>
    //<li class="divider"></li>
    //<li><a href="/website/delete/$row->nid$des">Delete (definitive !!)</a></li>
    //<li><a onclick="return confirm('Are you sure you sure?')" href="/website/delete/$row->nid$des">Delete (definitive !!)</a></li>
  }

  else {  // all other fields
    print $output;
  }
?>

