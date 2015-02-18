<?php


  $query = new EntityFieldQuery();
    $query
    ->entityCondition('entity_type', 'node')
    ->entityCondition('bundle', array('website'))
    ->propertyCondition('status', 1)
    ->propertyOrderBy('created', 'DESC');
  $result = $query->execute();

  $nodes = array();
  if (isset($result['node'])) {
    $nids = array_keys($result['node']);
    $nodes = node_load_multiple($nids);
  }

  // todo: build the container management gui
  $html = <<<END
<div>
<div class="page-header">
  <h1>Website <small>containers on XXX</small></h1>
</div
</div>
<ul class="list-group">
END;
   print $html;

  foreach ($nodes as $node) {
    print "Container name: <li class=list-group-item>$node->title</li>: "   
    //. $node->body['und'][0]['safe_value']
    ;
  }

$html = <<<END
</ul>
END;
   print $html;
?>

