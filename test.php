<?php

/*
 * example script for direct testing of the docker API
 * e.g.
 * https://webfactx.webfact.vptt.ch/sites/all/modules/custom/webfact/test.php
 */

# comment out the next line to enable tests
# this script not not be enabled permanently
#exit;

require_once '/var/www/html/sites/all/libraries/composer/autoload.php';

$client = new Docker\Http\DockerClient(array(), 'unix:///var/run/docker.sock');
$docker = new Docker\Docker($client);
$manager = $docker->getContainerManager();
$lookfor='vanilla2';
$container = $manager->find($lookfor);

## page header
$html = <<<END
<!DOCTYPE html> 
<html lang="en"> 
<head> 
<meta charset="utf-8"> 
<title>Twitter Bootstrap Version2.0 default form layout example</title> 
<meta name="description" content="tests"> 
<link type="text/css" rel="stylesheet" href="//netdna.bootstrapcdn.com/bootstrap/3.0.2/css/bootstrap.min.css" media="all" />
</head>
<body>
END;
echo $html;

/*
 * example: list images with menu
 */
/*
$lookfor='vanilla2';
        $imagemgr = $docker->getImageManager();
        $images = $imagemgr->findAll();
        $markup = "<h2>Images of $lookfor: </h2>";
        $markup .= '<div class="container">';
        foreach ($images as $image) {
          if (! strcmp($lookfor, $image->getRepository())) {
            $data=$imagemgr->inspect($image);
            $imagename=$image->__toString();
            #print("$imagename " . $data['Created'] . " " . $image->getID() . "\n");
            $markup .= '<div class="row"><div class="span9"><p>'
            . "$imagename "
            . $data['Created'] . " ${data['Author']}, ${data['Comment']} \n"
            . '</p></div><div class="span3">';
$html = <<<END
<!-- Bootstrap: Single button -->
<div class="btn-group">
  <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-expanded="false">
    Action <span class="caret"></span>
  </button>
  <ul class="dropdown-menu" role="menu">
    <li><a href="/website/imres/8/$imagename">Restore now</a></li>
    <li class="divider"></li>
    <li><a href="/website/imdel/8/$imagename">Delete</a></li>
  </ul>
</div>
END;
$markup .= $html . '</div></div>';;
          }
        }
        $markup .= '</div>';
echo $markup;

 *
 */

#header("Content-type: text/plain");
#disable_ob();

# Create + start a container
/*
  if ($container) {
    print("vanilla2:  stop, remove \n");
    $manager->stop($container)->remove($container);
  }
  $config = ['Image'=> 'boran/drupal',
            'Hostname' => '[\'vanilla2\']',
            'WorkingDir' => '/var/www/html',
            #'Cmd' =>'git status',
  ];
  $container= new Docker\Container($config);
  $container->setName('vanilla2');
  $config = [
          'Cmd' =>'bash',
          #'VolumesFrom' => '[\'drupal8003\']'
  ];
*/

/*
  #$manager->create($container)->start($container, $config);
  #print_r("Running=" . $container->getRuntimeInformations()['State']['Running']. "<br>");
  print("create and wait for logs ...\n");
  #print '<pre>';
  try { 
    $manager->run($container, function($output, $type) { print $output; }, $config);
  } catch (Exception $e) {
      if ($e->hasResponse()) {
        print($e->getResponse()->getReasonPhrase() .
          " (error code " . $e->getResponse()->getStatusCode(). " ");
      }
      else {
        print($e->getMessage());
      }
  }

  #print '</pre><br>';
  print_r("Running=" . $container->getRuntimeInformations()['State']['Running']. "<br>");
*/


/* real time logging 
echo "<h3>'attach' logging for $lookfor</h3>";
echo "<pre>";
$manager->attach($container, function ($output, $type) {print($output);} , true)->getBody()->getContents();
echo "</pre>";
*/


#echo "Last 20 logs<br>";
#$logs=$manager->logs($container, false, true, true, false, 20);
#print_r($logs);


/* interact() test
 */
#echo "<h3>interact for $lookfor</h3>";
#$manager->interact($container);



## page bottom
$html = <<<END
<script src="https://ajax.googleapis.com/ajax/libs/jquery/1.7.1/jquery.min.js"></script>
<script src="//netdna.bootstrapcdn.com/bootstrap/3.0.2/js/bootstrap.min.js"></script>
</body>
</html>
END;
echo $html;

function disable_ob() {
    ini_set('output_buffering', 'off');
    ini_set('zlib.output_compression', false);
    ini_set('implicit_flush', true);
    ob_implicit_flush(true);
    // Clear, and turn off output buffering
    while (ob_get_level() > 0) {
        // Get the curent level
        $level = ob_get_level();
        // End the buffering
        ob_end_clean();
        // If the current level has not changed, abort
        if (ob_get_level() == $level) break;
    }
    // Disable apache output buffering/compression
    if (function_exists('apache_setenv')) {
        apache_setenv('no-gzip', '1');
        apache_setenv('dont-vary', '1');
    }
}

?>
