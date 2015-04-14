<?php

/**
 * @file
 * Contains \Drupal\webfact\Controller\WebfactController
 */


#use GuzzleHttp\Exception\ClientException;
#use GuzzleHttp\Exception\RequestException;
#use Monolog\Logger;
#use Monolog\Handler\StreamHandler;

use Docker\Http\DockerClient;
use Docker\Exception\ImageNotFoundException;
#use Docker\Docker;


/*
 * encapsulate the Webfactory stuff into a calls, for easier Drupal7/8 porting
 */
class WebfactController {
  protected $client, $hostname, $nid, $id, $markup, $result, $status, $docker;
  protected $config, $user, $website, $des, $category;
  protected $verbose, $msglevel1, $msglevel2, $msglevel3;
  protected $cont_image, $dserver, $fserver, $loglines, $env_server; // settings
  protected $docker_start_vol, $docker_ports, $docker_env, $startconfig;
  protected $actual_restart, $actual_error, $actual_status, $actual_buildstatus;


  public function __construct($user_id_override = FALSE, $nid=0) {
    //allow the controller user to be set on creation - needed for api calls
    if($user_id_override){
      $account = user_load($user_id_override);
    }else{
      global $user;
      $account = $user;
    }
    #watchdog('webfact', 'WebfactController __construct()');
    $this->markup = '';
    $this->verbose = 1;
    $this->category = 'none';
    $this->docker_ports = array();
    $this->fqdn = '';

    # Load configuration defaults, override in settings.php or on admin/config/webfact
    $this->cont_image= variable_get('webfact_cont_image', 'boran/drupal');
    //$this->dserver   = variable_get('webfact_dserver', 'tcp://mydockerserver.example.ch:2375');
    $this->dserver   = variable_get('webfact_dserver', 'unix:///var/run/docker.sock');
    $this->fserver   = variable_get('webfact_fserver', 'webfact.example.ch');
    $this->rproxy    = variable_get('webfact_rproxy', 'nginx');
    $this->loglines  = variable_get('webfact_loglines', 300);
    $this->restartpolicy = variable_get('webfact_restartpolicy', 'on-failure');
    $this->env_server  = variable_get('webfact_env_server');
    $this->msglevel1 = variable_get('webfact_msglevel1', TRUE);  // normal infos
    $this->msglevel2 = variable_get('webfact_msglevel2', TRUE);  // more
    $this->msglevel3 = variable_get('webfact_msglevel3', TRUE);  // debug

    if (isset($account->name)) {
      $this->user= $account->name;
    } else {
      $this->user= 'anonymous';
    }

    /*$log = new Logger('name');
      $log->pushHandler(new StreamHandler('/tmp/mono.log', Logger::WARNING));
      $log->addWarning('Foo');
      $log->addError('Bar');*/

    $destination = drupal_get_destination();
    $this->des = '?destination=' . $destination['destination']; // remember where we were

    // define docker connection params
    $this->client = new Docker\Http\DockerClient(array(), $this->dserver);
    $this->client->setDefaultOption('timeout', variable_get('webfact_api_timeout', 30)); 
    $this->docker = new Docker\Docker($this->client);

    // api call: load minimal website infos: node, container name
    if ($nid>0) { 
      $this->website=node_load($nid);
      if ($this->website!=null) {
        $this->id=$this->website->field_hostname['und'][0]['safe_value'];
      }
    }
  }


  public function getContainerManager() {
    return $this->docker->getContainerManager();
  }
  public function getImageManager() {
    return $this->docker->getImageManager();
  }

  public function helloWorldPage() {   // nostalgia: the first function
    #dpm('helloWorldPage');
    return array('#markup' => '<p>' . t('Hello World') .  '</p>');
  }


  /*
   * helper function. hack drupal file_transer() to set caching headers
   * and not call drupal exit.
   * https://api.drupal.org/api/drupal/includes%21file.inc/function/file_transfer/7
   */
  public function tar_file_transfer($uri, $filename) {
    if (ob_get_level()) {
      ob_end_clean();
    }
    drupal_add_http_header('Pragma', 'public');
    drupal_add_http_header('Expires', '0');
    drupal_add_http_header('Cache-Control', 'must-revalidate, post-check=0, pre-check=0');
    drupal_add_http_header('Content-Type', 'text/tar');
    drupal_add_http_header('Content-Disposition', 'attachment; filename=' . basename($filename) . ';');
    drupal_add_http_header('Content-Transfer-Encoding', 'binary');
    drupal_add_http_header('Content-Length', filesize($filename));

    #drupal_send_headers();   // not needed?
    $scheme = file_uri_scheme($uri);
    // Transfer file in 1024 byte chunks to save memory usage.
    if ($scheme && file_stream_wrapper_valid_scheme($scheme) && $fd = fopen($uri, 'rb')) {
      while (!feof($fd)) {
        print fread($fd, 1024);
      }
      fclose($fd);
    }
    else {
      drupal_not_found();
    }
  }

  /*
   * make docker date more readable:
   * convert "2015-01-21T12:42:36.662998775Z"
   * to 2015-01-21 12:42
   * todo: correct timezone to local?
   */
  public function parseDockerDate ($d) {
    #$d="2015-01-21T12:42:36.662998775Z";
    preg_match('/(.+:.+):.+/', $d, $matches);
    $map= array('T' => ' ');
    return strtr($matches[1], $map);
  }


  /*
   * bootstrap navigation on the website/advanced page
   */
  private function nav_menu($wpath, $des) {
    if (! isset($this->website) ) {
      return;
    }
    $rebuild_src_msg = "Backup, stop, delete and recreate " . $this->website->title .". Data in the container will be lost! Are you sure?";
    $rebuild_meta_msg = "Backup, stop, delete and recreate from that same backup. i.e. to rebuild after changing an environment setting. Are you sure?";

    $nav1 = <<<END
      <nav class="navbar navbar-default">
        <div class="container-fluid">
          <div class="navbar-header">
            <button type="button" class="navbar-toggle collapsed" data-toggle="collapse" data-target="#navbar" aria-expanded="false" aria-controls="navbar">
              <span class="sr-only">Toggle navigation</span>
              <span class="icon-bar"></span>
              <span class="icon-bar"></span>
              <span class="icon-bar"></span>
            </button>
            <a class="navbar-brand" href="$wpath/advanced/$this->nid">Status</a>
          </div>
          <div id="navbar" class="navbar-collapse collapse">
            <ul class="nav navbar-nav">
              <li class="dropdown">
                <a href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-expanded="false">Manage<span class="caret"></span></a>
                <ul class="dropdown-menu" role="menu">
                  <li><a href="$wpath/advanced/$this->nid">Status</a></li>
                  <li><a href="$wpath/logs/$this->nid">Logs</a></li>
                  <li><a href="$wpath/stop/$this->nid">Stop</a></li>
                  <li><a href="$wpath/start/$this->nid">Start</a></li>
                  <li><a href="$wpath/restart/$this->nid">Restart</a></li>
                  <li class="divider"></li>
                  <li><a href="$wpath/createui/$this->nid">Create</a></li>
                  <li class="divider"></li>
                  <li><a href="$wpath/deleteui/$this->nid" onclick="return confirm('Are you sure?')">Delete container</a></li>
                  <li><a href="$wpath/deleteall/$this->nid" onclick="return confirm('Are you sure?')">Delete container+meta data</a></li>
                </ul>
              </li>
            </ul>


            <ul class="nav navbar-nav">
              <li class="dropdown">
                <a href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-expanded="false">Advanced<span class="caret"></span></a>
                <ul class="dropdown-menu" role="menu">
                  <li><a href="$wpath/inspect/$this->nid">Inspect</a></li>
                  <li><a href="$wpath/cocmd/$this->nid">Run command</a></li>
                  <li class="divider"></li>
                  <li><a href="$wpath/coappupdate/$this->nid" onclick="return confirm('Backup the container and run webfact_update.sh to update the website?')">Run website update</a></li>
                  <li><a href="$wpath/coosupdate/$this->nid" onclick="return confirm('Backup, stop, rename the container create a new container and finally restore /var/www/html/sites. Any other local changes wil be lost (e.g. local DB). Continue?')">Run container OS update</a></li>
                  <li class="divider"></li>
                  <li><a href="$wpath/rebuild/$this->nid" onclick="return confirm('$rebuild_src_msg')">Rebuild from sources</a></li>
                  <li><a href="$wpath/rebuildmeta/$this->nid" onclick="return confirm('$rebuild_meta_msg')">Rebuild from meta-data</a></li>
                  <li class="divider"></li>
                  <li><a href="$wpath/cocopyfile/$this->nid">Folder download</a></li>
      <!--        <li><a href="$wpath/couploadfile/$this->nid">File Upload</a></li>   -->
                </ul>
              </li>
            </ul>

            <ul class="nav navbar-nav">
              <li class="dropdown">
                <a href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-expanded="false">Backup/restore<span class="caret"></span></a>
                <ul class="dropdown-menu" role="menu">
                  <li><a href="$wpath/backup/$this->nid">Backup container now</a></li>
      <li><a href="$wpath/backuplist/$this->nid">List backups </a></li>
      <li class="divider"></li>
                  <li><a href="$wpath/backuplistdelete/$this->nid" onclick="return confirm('This will take some time to delete all backups. Continue?')">Remove ALL backup images of $this->id</a></li>
      <li class="divider"></li>
                  <li><a href="$wpath/coexport/$this->nid" onclick="return confirm('Download this container to a tarfile? This will be slow as hundreds of MB are typical. ')">Download container</a></li>
                </ul>
              </li>
            </ul>
END;

    ## Admin menu
    if ( user_access('manage containers')) {
      // Load the associated template
      $tlink='';
      if (isset($this->website->field_template['und'][0]['target_id'])) {
        $tid=$this->website->field_template['und'][0]['target_id'];
        if (isset($tid)) {
          $tlink="<li><a href=/node/$tid/edit$des>Edit template</a></li> ";
        }
      }
      $nav2 = <<<END
            <ul class="nav navbar-nav navbar-right">
              <li class="dropdown">
                <a href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-expanded="false">Docker Admin<span class="caret"></span></a>
                <ul class="dropdown-menu" role="menu">
                  <li><a href="$wpath/version/$this->nid">Docker version</a></li>
                  <li><a href="$wpath/containers/$this->nid">Containers</a></li>
                  <li><a href="$wpath/images/$this->nid">Images</a></li>
                  <li class="divider"></li>
                  $tlink
                  <li><a href="$wpath/processes/$this->nid">Container processes</a></li>
                  <li><a href="$wpath/kill/$this->nid">Container kill</a></li>
                  <li><a href="$wpath/impull/$this->nid" onclick="return confirm('Pulling the base image from Dockerhub will affect all future builds using that image. Are you sure you sure?')">Pull latest image</a></li>
                  <li><a href="$wpath/changes/$this->nid">Container fs changes</a></li>
                  <li class="divider"></li>
                  <li><a href="$wpath/proxyrestart/$this->nid$this->des" onclick="return confirm('Restarting nginx will break all sessions, refresh the page manually after a few secs. ')">Restart nginx</a></li>
                  <li><a href="$wpath/proxy2restart/$this->nid$this->des">Restart nginx-gen</a></li>
                  <li><a href="$wpath/proxylogs/$this->nid$this->des">Logs nginx</a></li>
                  <li><a href="$wpath/proxy2logs/$this->nid$this->des">Logs nginx-gen</a></li>
                  <li><a href="$wpath/proxyconf/$this->nid$this->des">Conf. nginx</a></li>
                </ul>
              </li>
            </ul>
END;
      } else {
        $nav2='';
      }
      $navend = <<<END
          </div><!--/.nav-collapse -->
        </div><!--/.container-fluid -->
      </nav>
END;
      return $nav1 . $nav2 . $navend;
  }


  /*
   * stop or delete a container by name
   * no checking of permissions,
   * todo: this is an experimental abstraction, initially used in hook_node_delete()
   */
  public function deleteContainer($name) {
     $manager = $this->getContainerManager();
     $container = $manager->find($name);
     if (!$container) {
       watchdog('webfact', "deleteContainer $name - no such container");
       return;
     }
     if  ($container->getRuntimeInformations()['State']['Running'] == TRUE) {
       $manager->stop($container);
     }
     $manager->remove($container);
     watchdog('webfact', "deleteContainer $name - removed");
  }


  public function stopContainer($name) {
     $manager = $this->getContainerManager();
     $container = $manager->find($name);
     if (!$container) {
       watchdog('webfact', "stopContainer $name - no such container");
       return;
     }
     if  ($container->getRuntimeInformations()['State']['Running'] == TRUE) {
       $manager->stop($container);
     }
     watchdog('webfact', "stopContainer $name - done");
  }


  /*
   * rename a container
   * no checking of permissions,
   */
  public function renameContainer($old, $new) {
     $manager = $this->getContainerManager();
     $container = $manager->find($old);
     if (!$container) {
       watchdog('webfact', "renameContainer $old - no such container");
       return;
     }
     if  ($container->getRuntimeInformations()['State']['Running'] == TRUE) {
       $manager->stop($container);
     }
     $manager->rename($container, $new);
     watchdog('webfact', "renameContainer $old to $new");
  }

  /*
   * backup a container (commit an image)
   * parameter: container name, returns name of the saved container
   */
  public function backupContainer($id, $comment='', $tag_postfix='') {
     $this->client->setDefaultOption('timeout', 80); // can take time
     $manager = $this->getContainerManager();
     $container = $manager->find($id);
     if (!$container) {
       watchdog('webfact', "backupContainer $id - no such container");
       return;
     }
     $config = array('tag' => date('Ymd') . $tag_postfix, 
       'repo'    => $id, 
       'author'  => $this->user,
       'comment' => $comment,);
     $savedimage = $this->docker->commit($container, $config);
     $savedimage = $savedimage->__toString();
     watchdog('webfact', "backupContainer $id to $savedimage");
     return($savedimage);
  }


  /*
   * create a container: test function, really abstract this bit out?
   * implementation does not smell good!
   */
  public function create($verbose = 0) {   // create a container
     // todo: make sure $id and all "this" stuff is loaded
     $manager = $this->getContainerManager();

     // create the container
        $config = ['Image'=> $this->cont_image, 'Hostname' => $this->fqdn,
                   'Env'  => $this->docker_env, 'Volumes'  => $this->docker_vol
        ];
        #dpm($config);
        $container= new Docker\Container($config);
        $container->setName($this->id);
        $manager->create($container);

     // start
        #dpm($this->startconfig);
        $manager->start($container, $this->startconfig);

        $msg= "$this->action $this->id: title=" . $this->website->title
          . ", docker image=$this->cont_image" ;
        watchdog('webfact', $msg);

        if ($verbose == 1) {
          $this->message($msg, 'status', 2);
          // inform user:
          $cur_time=date("Y-m-d H:i:s");  // calculate now + 6 minutes
          $newtime=date('H:i', strtotime('+6 minutes', strtotime($cur_time))); // todo setting
          $this->message("Provisioning: you can connect to the new site at $newtime.", 'status');
          drupal_goto("/website/advanced/$this->nid"); // show new status
        }
        return;
  }


  /*
   * load the meta spec for a container from the website and template
   * todo: what if $this->id is not loaded?
   */
  public function load_meta() {
    $this->docker_start_vol=array();
    $this->docker_vol=array();
    $this->docker_env=array();

    // Initial docker environment variables
    // todo: should only be for Drupal sites?
    $this->fqdn = $this->id . '.' . $this->fserver;  // e.g. MYHOST.webfact.example.ch
    $this->docker_env = [
      'DRUPAL_SITE_NAME='   . $this->website->title,
      'DRUPAL_SITE_EMAIL='  . $this->website->field_site_email['und'][0]['safe_value'],
      'DRUPAL_ADMIN_EMAIL=' . $this->website->field_site_email['und'][0]['safe_value'],
      "VIRTUAL_HOST=$this->fqdn",
    ];
    // webfactory server default
    if (isset($this->env_server)) {     // pull in default env
      $this->docker_env[] = $this->env_server;
    }
    // Create a '/data' volume by default?
    if (variable_get('webfact_data_volume', 1) == 1 ) {
      $this->docker_vol[] = "/data =>{}";
      $this->docker_start_vol[] = variable_get('webfact_server_sitesdir', '/opt/sites/') 
        . $this->id . ":/data:rw";
    }

    // Template? : Load values there first
    if (isset($this->website->field_template['und'][0]['target_id'])) {
      #$this->message("A template must be associated with $this->id($this->website->title)", 'error');

      $tid=$this->website->field_template['und'][0]['target_id'];
      if ($tid!=null) {
        #dpm("reading template ");
        $template=node_load($tid);
        if ($template!=null) {
          #dpm($template);

          // image
          if (!empty($template->field_docker_image['und'][0]['safe_value']) ) {
            $this->cont_image = $template->field_docker_image['und'][0]['safe_value'];
          }
          // environment key/value array
          if (!empty($template->field_docker_environment['und']) ) {
            foreach ($template->field_docker_environment['und'] as $row) {
              #dpm($row['safe_value']);
              if (!empty($row['safe_value'])) {
                $this->docker_env[]= $row['safe_value'];
              }
            }
          }
          // volumes from template
          if (!empty($template->field_docker_volumes['und']) ) {
            foreach ($template->field_docker_volumes['und'] as $row) {
              //dpm($row);
              if (!empty($row['safe_value'])) {
                # image "create time" mapping: foo:bar:baz, extract the foo:
                $count = preg_match('/^(.+)\:.+:/', $row['safe_value'], $matches);
                #dpm($matches);
                if (isset($matches[1])) {
                  # $this->docker_vol = ["/root/gitwrap/id_rsa" =>"{}", "/root/gitwrap/id_rsa.pub" =>"{}" ];
                  $this->docker_vol[] .= "$matches[1] =>{}";
                  # runtime mapping
                  $this->docker_start_vol[] .= $row['safe_value'];
                }
                else {
                  $this->message("Template volume field must be of the form xx:y:z", 'error');
                  return;
                }
              }
            }
          }
          // ports from template
          if (!empty($template->field_docker_ports['und']) ) {
            foreach ($template->field_docker_ports['und'] as $row) {
              #dpm($row['safe_value']);
              if (!empty($row['safe_value'])) {
                # port "create time" mapping: sourceport:dstnport
                $count = preg_match('/^(.+)\:(.+)/', $row['safe_value'], $matches);
                #dpm($matches);
                if (isset($matches[1])) {
                  $this->docker_ports[ "$matches[1]/tcp" ] =
                    [[ 'HostIp' => '0.0.0.0', 'HostPort' => "$matches[2]" ]] ;
                }
                else {
                  $this->message("Website ports field must be of the form SOURCE_NUMBER:DESTN_NUMBER", 'error');
                  return;
                }
              }
            }
          }   // ports

        }
      }
    } // if $template

    //
    // website settings: (override templates)
    //
    // get category:
    if (isset($this->website->field_category['und'][0]['tid'])) {
      $category=taxonomy_term_load($this->website->field_category['und'][0]['tid']);
      if (strlen($category->name) > 1) {
        $this->category = $category->name;
      }
    }
    // send to the container environment
    $this->docker_env[] = "WEBFACT_CATEGORY=" . $this->category;

    // image:
    if (!empty($this->website->field_docker_image['und'][0]['safe_value']) ) {
      $this->cont_image = $this->website->field_docker_image['und'][0]['safe_value'];
    }
    // add to environment key/value array:
    if (!empty($this->website->field_docker_environment['und']) ) {
      foreach ($this->website->field_docker_environment['und'] as $row) {
        #dpm($row['safe_value']);
        if (!empty($row['safe_value'])) {
          $this->docker_env[]= $row['safe_value'];
        }
      }
    }
    // add to volumes key/value array:
    if (!empty($this->website->field_docker_volumes['und']) ) {
      foreach ($this->website->field_docker_volumes['und'] as $row) {
        if (!empty($row['safe_value'])) {
          # image "create time" mapping: foo:bar:baz, extract the foo:
          $count = preg_match('/^(.+)\:.+:/', $row['safe_value'], $matches);
          if (isset($matches[1])) {
            $this->docker_vol[] .= "$matches[1] =>{}";
            # runtime mapping
            $this->docker_start_vol[] .= $row['safe_value'];
          }
          else {
            $this->message("Website volume field must be of the form xx:y:z", 'error');
            return;
          }
        }
      }
    }
    // add to ports:
    if (!empty($this->website->field_docker_ports['und']) ) {
      foreach ($this->website->field_docker_ports['und'] as $row) {
        #dpm($row['safe_value']);
        if (!empty($row['safe_value'])) {
          # port "create time" mapping: sourceport:dstnport
          $count = preg_match('/^(.+)\:(.+)/', $row['safe_value'], $matches);
          #dpm($matches);
          if (isset($matches[1])) {
            // 'PortBindings' => [ '80/tcp' => [ [ 'HostPort' => '2000' ] ] ],
            $this->docker_ports[ "$matches[1]/tcp" ] =
              [[ 'HostIp' => '0.0.0.0', 'HostPort' => "$matches[2]" ]] ;
          }
          else {
            $this->message("Website ports field must be of the form SOURCE_NUMBER:DESTN_NUMBER", 'error');
            return;
          }
        }
      }
    }
    //  actualy, do not sort the array, to preserve the order of settings, allowing override
    //sort($this->docker_env);

    if (!empty($this->website->field_docker_restartpolicy['und'][0]) ) {
      $this->restartpolicy= $this->website->field_docker_restartpolicy['und'][0]['value'];
    }
    #dpm($this->restartpolicy);

    #dpm($this->docker_start_vol);
    if (empty($this->docker_start_vol)) {  // API will not accept an empty Binds
      $this->startconfig = [
        'RestartPolicy'=> [ 'MaximumRetryCount'=>3, 'Name'=> $this->restartpolicy ],
        'PortBindings'  => $this->docker_ports,
      ];
    } else {
      $this->startconfig = [
        'RestartPolicy'=> [ 'MaximumRetryCount'=>3, 'Name'=> $this->restartpolicy ],
        'Binds'=> $this->docker_start_vol,
        'PortBindings'  => $this->docker_ports,
      ];
    }

    #dpm($this->docker_ports);
    #dpm($this->startconfig);
    #dpm($this->docker_vol);
    #dpm($this->docker_env);

  }  // function


  /*
   * run a command inside the container and give back the results
   * todo: check status code and do a watchdog or interactive error?
   * Params: 
   *  command to run
   *  container name/id
   *  max number of bytes to read from the result
   */
  public function runCommand($cmd, $id='', $maxlength=4096, $verbose=0) {
    if (strlen($id)<1) {
      $id = $this->id;
    }

    $manager = $this->getContainerManager();
    $container = $manager->find($id);
    if (strlen($cmd)<1) {
      watchdog('webfact', 'runCommand: invalid cmd=' . $cmd);
      return;  // container not running, abort
    }
    if (($container==null) || 
      (! isset($container->getRuntimeInformations()['State'])) ||
      ($container->getRuntimeInformations()['State']['Running'] == FALSE)) {
      #watchdog('webfact', 'runCommand: ignore, container not running');
      return;  // container not running, abort
    }
    if ($verbose==1 ) {
      watchdog('webfact', 'runCommand: ' . $cmd);
      #$this->message("Running command: $cmd", 'status', 4);
    }

    # todo: nice to lower priority
    $execid = $manager->exec($container, ['/bin/bash', '-c', $cmd]);
    #dpm("Exec ID= <" . $execid. ">\n");
    #$response = $manager->execstart($execid, function(){} ,false,false);
    $response = $manager->execstart($execid);
    #dpm($response->getStatusCode());
    #dpm($response->__toString());
    if ($body = $response->getBody()) {
      $body->seek(0);
      $result = $body->read($maxlength); // get first XX bytes
      
    #if ($verbose==1 ) {
    #  dpm($result);
    #}
    return(trim($result, "\x00..\x1F"));  // trim all ASCII control characters
  }
}


  /*
   * get the build status number created by start.sh in the boran/drupal image, if available
   */
  public function getContainerBuildStatus() {
    $cmd = "if [[ -f /var/log/start.sh.log ]] ; then tail -1 /var/log/start.sh.log; fi";
    $this->actual_buildstatus = $this->runCommand($cmd);
    return($this->actual_buildstatus);
  }

  /*
   * get the status withing the container, from webfact_status.sh
   */
  public function getContainerStatus() {
    // todo: make the command a parameter
    $cmd = "if [[ -d /var/www/html ]] && [[ -x /var/www/html/webfact_status.sh ]] ; then /var/www/html/webfact_status.sh; fi;";
    #$cmd = "cd /var/www/html && ls";
    $this->actual_status = $this->runCommand($cmd);
    return($this->actual_status);
  }

  /*
   * query the docker status on the container
   */
  public function getStatus($nid) {
    $runstatus = 'n/a';
    if (! is_numeric($nid) ) {
      return $runstatus;
    }
    // get the node and find the container name
    $this->website=node_load($nid);
    if ($this->website==null) {
      return $runstatus;
    }
    if (empty($this->website->field_hostname['und'][0]['safe_value']) ) {
      return $runstatus;
    }
    $this->id=$this->website->field_hostname['und'][0]['safe_value'];

    // get container and status
    $manager = $this->getContainerManager();
    $container = $manager->find($this->id);
    if ($container==null) {
      $runstatus = 'unknown';
      return $runstatus;
    }
    $manager->inspect($container);
    $cont = array();
    $cont = $container->getRuntimeInformations();
    if (isset($cont['State'])) {
      if ($cont['State']['Paused']==1) {
        $runstatus = 'paused';
      }
      else if ($cont['State']['Running']==1) {
        $runstatus = 'running';
      }
      else if ($cont['State']['Restarting']==1) {
        $runstatus = 'restarting';
      }
      else {
        //dpm($cont['State']);
        $runstatus = 'stopped';
      }
    }

    // grab some more key run info
    #dpm($cont);
    $this->actual_restart='';
    if (isset($cont['HostConfig']['RestartPolicy'])) {
      $this->actual_restart= $cont['HostConfig']['RestartPolicy']['Name'];
    }
    $this->actual_error='none';
    if (isset($cont['State']['Error']) && !empty($cont['State']['Error'])) {
      $this->actual_error= $cont['State']['Error'];
    }
    return $runstatus;
  }


  public function message($msg, $status='status', $msglevel=1) {
    if (($msglevel==1) && ($this->msglevel1)) {
       drupal_set_message($msg, $status);
    }
    if (($msglevel==2) && ($this->msglevel2)) {
       drupal_set_message($msg, $status);
    }
    if (($msglevel==3) && ($this->msglevel3)) {
       drupal_set_message($msg, $status);
    }
    // else stay silent
  }


  protected function imageAction($verbose=1) {
    //watchdog('webfact', "imageAction() $this->action");
    try {
      $manager = $this->docker->getImageManager();

      if ($this->action=='images') {
        #$imagemgt = $this->docker->getImageManager();   // todo
        $response = $this->client->get(["/images/json?all=0",[]]);
        $this->markup = "<pre>Docker Images:\n" ;
          $procarray    = $response->json();
          $this->markup .= "Created     Id\t\t\t\t\t\t\t              Size\t Tag\n";
          foreach ($procarray as $row) {
            //$this->markup .= print_r($row, true);
            $this->markup .= "${row['Created']}  ${row['Id']}  ${row['Size']}\t " . $row['RepoTags'][0] . "\n";
            //$this->markup .= $row['RepoTags'][0] . "\t ${row['Size']}\t ${row['Created']} \n";
          }
        $this->markup .= "</pre>" ;
        return;
      }

      else if ($this->action=='events') {
        // todo: hangs and gives nothing back
        //$response = $this->client->get(["/events",[]]);
        $response = $this->client->get(["/events?since=2015-04-02",[]]);
        //$response = $this->client->get("/events?filter{'container':" . $this->id . "}");
        $this->markup = 'Docker events: <pre>' .$response->getBody() . '</pre>';
      }

      else if ($this->action=='version') {
        $response = $this->client->get(["/version",[]]);
        $this->markup = '<pre>' . $response->getBody() .'</pre>';
      }

    } catch (Exception $e) {
      if ($e->hasResponse()) {
        $this->message($e->getResponse()->getReasonPhrase() .
          " (error code " . $e->getResponse()->getStatusCode(). " in imageAction() )" , 'warning');
      }
      else {
        $this->message($e->getMessage(), 'error');
      }
    }
  }


  protected function contAction($verbose=1) {
    #watchdog('webfact', "contAction() $this->action");
    try {
      $manager = $this->docker->getContainerManager();
      $container = $manager->find($this->id);


      // delete meta data and container
      if ($this->action=='deleteall') {
        // Stop accidental deleting of key containers
        if (stristr($this->category, 'production')) {
          $this->message("$this->id is categorised as production, deleting not allowed.", 'warning');
          return;
        }
        # even if there is no container, allow node to be wiped
        #if (! $container) {
        #  $this->message("$this->id does not exist",  'error');
        #  return;
        #}

        watchdog('webfact', 'deleteall node id ' . $this->nid);
        $batch = array(
          'title' => t('Remove meta data & container ' . $this->id),
          'operations' => array(
            array('batchRemoveCont', array($this->website->nid, $this->id, 0)),
            array('batchRemoveNode', array($this->website->nid, $this->id, 0)),
          ),
          'finished' => 'batchDone',
          'file' => drupal_get_path('module', 'webfact') . '/batch.inc',
        );
        batch_set($batch);
        batch_process('websites'); // go here when done
        #unset($_SESSION['batch_results']);  // empty logs, else will show on next /advanced visit

        #node_delete($this->nid);   // this will trigger deleteContainer() too
        #$this->message("Meta data and website deleted");
        #drupal_goto('/websites');
        return;
      }


      else if (($this->action=='delete') || ($this->action=='deleteui') ) {
        // Stop accidental deleting of key containers
        if (stristr($this->category, 'production')) {
          $this->message("$this->id is categorised as production, deleting not allowed.", 'warning');
          return;
        }
        if (! $container) {
          $this->message("$this->id does not exist",  'error');
          return;
        }
        else if ($container->getRuntimeInformations()['State']['Running'] == TRUE) {
          if ($this->verbose===1) {
            $this->message("$this->id must be stopped first", 'warning');
            return;
          } else {
            $manager->stop($container);
          }
        }

        watchdog('webfact', "$this->action $this->id ", array(), WATCHDOG_NOTICE);
        if ($this->action=='deleteui') {  // use batch
          $batch = array(
            'title' => t('Remove ' . $this->id),
            'operations' => array(
              array('batchRemoveCont', array($this->website->nid, $this->id, 1)),
            ),
            'finished' => 'batchDone',
            'file' => drupal_get_path('module', 'webfact') . '/batch.inc',
          );
          batch_set($batch);
          batch_process('website/advanced/' . $this->website->nid); // go here when done

        } else {
          $manager->remove($container);
          if ($this->verbose===1) {
            $this->message("$this->action $this->id");
            drupal_goto("/website/advanced/$this->nid"); // show new status
          }
        }
        return;
      }

      else if ($this->action=='containers') {
        $this->markup = "<pre>Running containers:\n" ;
        $this->markup .= "Name\t\tImage\t\t\t Running?\t StartedAt\n";
        $containers = $manager->findAll();
        foreach ($containers as $container) {
          $manager->inspect($container);
          #dpm($container->getID() . " " . $container->getName() . "\n");
          $this->markup .= $container->getName() . "\t " . $container->getImage()
            . "\t " . $container->getRuntimeInformations()['State']['Running']
            . "\t " . $container->getRuntimeInformations()['State']['StartedAt']
          #  . "\t " . $container->getCmd()
          #  . "\t " . $container->getID()
            . "\n";
        }
        $this->markup .= "</pre>" ;
        return;
      }

      else if ($this->action=='inspect') {
        if (! $container) {
          $this->message("$this->id does not exist", 'warning');
        }
        else {
          $this->message("$this->action $this->id");
          //watchdog('webfact', "$this->action $this->id ", array(), WATCHDOG_NOTICE);
          //$manager->inspect($container);
          $cont=$container->getRuntimeInformations();
          $this->markup  = '<pre>';
          $this->markup .="Hostname:" . $cont['Config']['Hostname'] .'<br>';
          $this->markup .='<br>';
          $this->markup .="Created "    . $cont['Created'] .'<br>';
          $this->markup .="FinishedAt " . $cont['State']['FinishedAt'] .'<br>';
          $this->markup .="ExitCode "   . $cont['State']['ExitCode'] .'<br>';
          $this->markup .='<br>';

          $this->markup .="Base image " . $cont['Config']['Image'] .'<br>';
          if (isset($cont['Config']['Env'])) {
            #$this->markup .="Environment " . print_r($cont['Config']['Env'], true) .'<br>';
            $this->markup .="<br>Environment: (excluding password entries)<br>";
            sort($cont['Config']['Env']);
            foreach($cont['Config']['Env'] as $envline) {
              // hide variables that might containing passwords
              if (! preg_match('/$envline|_PW|_GIT_REPO/', $envline)) {
                $this->markup .= $envline .'<br>';
              }
            }
            $this->markup .="<br>";
          }
          if (empty($cont['Volumes'])) {
            $this->markup .="Volumes: none <br>";
          }
          else {
            $this->markup .="Volumes " . print_r($cont['Volumes'], true) .'<br>';
            # todo: print array paired elements on each line
            #$this->markup .="Volumes:<br>";
            #foreach($cont['Volumes'] as $line) {
            #  $this->markup .=$line .'<br>';
            #}
            #$this->markup .="<br>";
          }

          if (isset($cont['HostConfig']['PortBindings'])) {
            $this->markup .="PortBindings " . print_r($cont['HostConfig']['PortBindings'], true) .'<br>';
          }
          else {
            $this->markup .="PortBindings: none <br>";
          }

          $this->markup .="Container id: " . $cont['Id'] .'<br>';
          if ($this->msglevel2) {
            $this->markup .="RestartPolicy " . print_r($cont['HostConfig']['RestartPolicy'], true);
            $this->markup .="Network " . print_r($cont['NetworkSettings'], true);
          }

          $this->markup .= '</pre>';
        }
        return;
      }

      else if ($this->action=='processes') {
        if (! $container) {
          $this->message("$this->id does not exist", 'warning');
        }
        else {
          //$procs=$manager->top($container, "aux");
          //todo: dont use the library api, as titles are hard to extract
          $response = $this->client->get(['/containers/{id}/top', [ 'id' => $this->id, ]]);
          $procarray      = $response->json();
          $this->markup = '<pre>';
          for ($i = 0; $i < count($procarray['Titles']); ++$i) {
            $this->markup .= $procarray['Titles'][$i] . ' ';
          }
          $this->markup .= "\n";
          foreach ($procarray['Processes'] as $process) {
            for ($i = 0; $i < count($process); ++$i) {
              $this->markup .= $process[$i] . ' ';
            }
            $this->markup .= "\n";
          }
          $this->markup .= '</pre>';
        }
        return;
      }

      else if ($this->action=='changes') {
        if (! $container) {
          $this->message("$this->id does not exist", 'warning');
        }
        else {
          $logs=$manager->changes($container);
          $this->markup = '<pre>';
          $this->markup .= "Kind  Path<br>";
          foreach ($logs as $log) {
            $this->markup .= $log['Kind'] . " " . $log['Path'] . "<br>";
          }
          $this->markup .= '</pre>';
        }
        return;
      }

      else if ($this->action=='logs') {
        if (! $container) {
          $this->message("$this->id does not exist", 'warning');
        }
        else {
          // refresh every 5 secs
          $meta_refresh = array(
            '#type' => 'html_tag', '#tag' => 'meta',
            '#attributes' => array( 'content' =>  '5', 'http-equiv' => 'refresh',));
          drupal_add_html_head($meta_refresh, 'meta_refresh');

          //                               $follow $stdout $stderr $timestamp $tail = "all"
          $logs=$manager->logs($container, false, true, true, false, $this->loglines);
          //$logs=$manager->logs($container, true, true, true, false, $this->loglines);
          $this->markup = '<pre>';
          foreach ($logs as $log) {
            $this->markup .= $log['output'];
          }
          $this->markup .= '</pre>';
        }
        return;
      }

      else if ($this->action=='start') {
        if (! $container) {
          $this->message("$this->id does not exist", 'warning');
        }
        else if ($container->getRuntimeInformations()['State']['Running'] == TRUE) {
          $this->message("$this->id already started", 'warning');
        }
        else {
          $this->message("$this->action $this->id");
          //dpm($container->getRuntimeInformations()['State']['Error']);
          $manager->start($container, $this->startconfig);
          drupal_goto("/website/advanced/$this->nid"); // show new status
        }
        return;
      }


      /*
       * Process for container level updates (update the lamp stack in the container)
Assumptions: Ubuntu updates are not enabled in the container and we don't plan to "apt-get upgrade". The DB is external and does not change. The only data that needs to be backed-up/restored is in /var/www/html/sites (done by /root/backup.sh). The /data volume is mounted from the server (and thus survives containder removal)
      */
      else if ($this->action=='coosupdate') {
        global $base_root;
        $this->client->setDefaultOption('timeout', 60);   // backups can take time
        if (! $container) {
          $this->message("$this->id does not exist", 'warning');
          return;
        }
        if ($this->getStatus($this->nid) != 'running' ) {
          $this->message("The container is not running.", 'error');
          return;
        }

        // preconditions:
        // a) if /data a volume, does it have a mapping to a host directory?
        $datavolsrc = '/data'; // todo: parameter
        $datavol = $container->getRuntimeInformations()['Volumes'];
        if (! isset($datavol[$datavolsrc]) ) {
          $this->message("The container does not have a $datavolsrc volume.", 'error');
          #dpm($datavol);
          return;
        } else {
          $datavolmap = $datavol[$datavolsrc];
          if (strlen($datavolmap)<1) {
            $this->message("Container does not have a $datavolsrc volume mapped to a server directory.", 'error');
            #dpm($datavolmap);
            return;
          }
          else if (strlen($datavolmap)>70) {   // looks like a long patch with a hash
            $this->message("Container $datavolsrc volume is not mapped to a server directory (found $datavolmap).", 'error');
            #dpm($datavolmap);
            return;
          }
        }

        // b) /root/backup.sh exists
        $backup="/root/backup.sh";
        $ret = $this->runCommand("if [[ -x $backup ]] && [[ -d $datavolsrc ]]; then echo 'OK'; else echo 'NOK'; fi;");
        //if ( strcmp($answer, 'OK')!==0 ) {  //note: $ret!= 'OK' does not work
        if ($ret!= 'OK') {
          #dpm($ret);
          $this->message("The container does not have $backup or $datavolsrc", 'error');
          $logs = $this->runCommand(" ls -al $backup $datavolsrc;");
          $this->markup = "<h3>Update results</h3>ls -al $backup $datavolsrc :<pre>$logs</pre>";   // show output
          return;
        }

        // process: lets do it
        watchdog('webfact', "coosupdate batch: inside backup, stop, rename, create", WATCHDOG_NOTICE);
        // update: via batch API
        $batch = array(
          'title' => t('Update Container OS '),
          'init_message' => t('Run backup inside container to /data'),
          'operations' => array(
            array('batchCommandCont', array("/root/backup.sh && ls -altr /data", $this->id)),
            # todo: check that /data/html_sites.tgz exists
            # todo: verify that sql is external
            array('batchStopCont', array($this->website->nid, $this->id)),
            array('batchRemoveCont', array($this->website->nid, $this->id . '-preupdate', 0)),
            array('batchRenameCont', array($this->id, $this->id . '-preupdate')),
            array('batchCreateCont', array($this->website->nid, $this->id)),
            # wait one minute, then loop until fully provisioned
            array('batchTrack', array($this->website->nid, $this->id, 5)),
            array('batchTrack', array($this->website->nid, $this->id, 10)),
            array('batchTrack', array($this->website->nid, $this->id, 20)),
            array('batchWaitInstalled', array($this->website->nid, $this->id, 20, 6)), // 2min
            array('batchWaitInstalled', array($this->website->nid, $this->id, 20, 6)),
            array('batchWaitInstalled', array($this->website->nid, $this->id, 20, 6)),
            # restore files from /data/html_sites.tgz
            array('batchCommandCont', array("cd /var/www/html && mv sites sites.$$ && tar xzf /data/html_sites.tgz", $this->id)),
            array('batchContLog', array($this->website->nid, $this->id, variable_get('webfact_cont_log', '/tmp/webfact.log'))),
          ),
          'finished' => 'batchUpdateDone',
          'file' => drupal_get_path('module', 'webfact') . '/batch.inc',
        );
        batch_set($batch);
        batch_process('website/advanced/' . $this->website->nid); // go here when done
dpm('coosupdate done');
        return;
      }


      else if ($this->action=='coappupdate') {
        global $base_root;
        $this->client->setDefaultOption('timeout', 120);   // can take time
        // Stop accidental deleting of key containers
        if (stristr($this->category, 'production')) {
          $this->message("$this->id is categorised as production, website update not allowed.", 'error');
          return;
        }
        if (! $container) {
          $this->message("$this->id does not exist", 'warning');
          drupal_goto("/website/advanced/$this->nid"); // go back to status page
          return;
        }

// URGENT FIX 7.4.15: disable batchapi in favour of direct ommand
/*
 updates do not work via the batch API; why?
 - cannot get logs to both stdout and the /tmp/webfact.log
 - updating webfact causes: Docker\Exception\APIException: EOF
 in Docker\Http\Adapter\DockerAdapter->send() (line 77 of /var/www/html/sites/all/libraries/composer/stage1/docker-php/src/Docker/Http/Adapter/DockerAdap
 - todo: come back and investigate

        // Update via batch API: for a better user experince (progress bar), and the option to do stuff in setps.
        $batch = array(
          'title' => t('Run Website update'),
          #'init_message' => t('Website update starting.'),
          'operations' => array(
#            array('batchSaveCont',   array($this->website->nid, $this->id)),
            array('batchCommandCont', array('cd /var/www/html && ./webfact_update.sh', $this->id)),
            # Do we really need to restart?
            #array('batchRestartCont',array($this->website->nid, $this->id)),
            array('batchContLog', array($this->website->nid, $this->id, variable_get('webfact_cont_log', '/tmp/webfact.log'))),
          ),
          'finished' => 'batchUpdateDone',
          'file' => drupal_get_path('module', 'webfact') . '/batch.inc',
        );
        batch_set($batch);
        batch_process('website/advanced/' . $this->website->nid); // go here when done
*/

// Directly, without batch:
// XX
        // backup, stop, delete:
        if (variable_get('webfact_rebuild_backups', 1) == 1 ) {
          $savedimage = $this->backupContainer($this->id, "saved before app update on $base_root", '-before-update');
          $this->message("Saved to " . $savedimage, 'status', 3);
        }

        $this->message("Run webfact_update.sh (see results below)", 'status', 2);
        watchdog('webfact', "coappupdate $this->id - run webfact_update.sh, log to /tmp/webfact.log", WATCHDOG_NOTICE);
        #$cmd='ps';
        $cmd = "cd /var/www/html && ./webfact_update.sh |tee -a /tmp/webfact.log "; // todo: parameter
        $logs = $this->runCommand($cmd);
        $this->markup = "<h3>Update results</h3><p>Running '${cmd}':</p><pre>$logs</pre><p>See also /tmp/webfact_update.log</p>";   // show output
        return;
      }


      else if ($this->action=='rebuildmeta') {
        global $base_root;
        $this->client->setDefaultOption('timeout', 60);
        // Stop accidental deleting of key containers
        if (stristr($this->category, 'production')) {
          $this->message("$this->id is categorised as production, rebuild not allowed.", 'error');
          drupal_goto("/website/advanced/$this->nid"); // go back to status page
          return;
        }
        if (! $container) {
          $this->message("$this->id does not exist", 'warning');
          drupal_goto("/website/advanced/$this->nid"); // go back to status page
          return;
        }

        //TODO: use the batch API as above
        // backup, stop, delete:
        watchdog('webfact', "rebuildmeta - backup, stop, delete, create from backup", WATCHDOG_NOTICE);
        $config = array('tag' => date('Ymd') . '-rebuild-meta', 'repo' => $this->id, 'author' => $this->user,
          'comment' => "saved before meta rebuild on $base_root",
        );
        $savedimage = $this->docker->commit($container, $config);
        $this->message("saved to " . $savedimage->__toString(), 'status', 3);

        // change image configured for this node
          #$this->cont_image = $this->id . ":" .date('Ymd') . '-rebuild-meta';
          $this->cont_image = $savedimage->__toString();
          $this->website->field_docker_image['und'][0]['value'] = $this->cont_image;
          $this->website->revision = 1;    // history of changes
          $this->website->log = 'change docker_image ' . $this->cont_image
            . " by $this->user on " . date('c');
          node_save($this->website);     // Save the updated node
          $this->website=node_load($this->website->nid);  # necessary to reload the node to avoid caching

        $this->message("stop ", 'status', 3);
        $manager->stop($container);
        $this->message("remove ", 'status', 3);
        $manager->remove($container);
        $this->message("rebuilding meta: saved to " . $savedimage->__toString()
          .", stopped, deleted.", 'status', 2);

        $this->action='create';
        $this->contAction(0);     // 0=no verbose message
        $this->message("Click on 'inspect' to verify the new environment. You may also wish to reset the category and docker image in the meta data.", 'status', 2);
        drupal_goto("/website/advanced/$this->nid"); // go back to status page
        return;
      }


      else if ($this->action=='rebuild') {
        global $base_root;
        $this->client->setDefaultOption('timeout', 180);
        // Stop accidental deleting of key containers
        if (stristr($this->category, 'production')) {
          $this->message("$this->id is categorised as production, rebuild/delete not allowed.", 'error');
          return;
        }
        if (! $container) {
          $this->message("$this->id does not exist. Use Create rather then Rebuild.", 'warning');
          return;
        }

        // Rebuilding via batch API
        watchdog('webfact', "rebuild batch- backup, stop, delete, create from sources", WATCHDOG_NOTICE);
        // todo: onlky backup if (variable_get('webfact_rebuild_backups', 1) == 1 ) {
        $batch = array(
          'title' => t('Rebuilding from sources'),
          #'init_message' => t('Rebuild starting.'),
          'operations' => array(
            array('batchSaveCont', array($this->website->nid, $this->id)),
            array('batchRemoveCont', array($this->website->nid, $this->id, 1)),
            array('batchCreateCont', array($this->website->nid, $this->id)),
            // repeat until hopefuly 100% reached
            array('batchTrack', array($this->website->nid, $this->id, 10)),
            array('batchTrack', array($this->website->nid, $this->id, 10)),
            array('batchTrack', array($this->website->nid, $this->id, 10)),
            array('batchTrack', array($this->website->nid, $this->id, 10)),
            array('batchTrack', array($this->website->nid, $this->id, 10)),
            array('batchTrack', array($this->website->nid, $this->id, 10)),
            array('batchTrack', array($this->website->nid, $this->id, 20)),
            array('batchTrack', array($this->website->nid, $this->id, 20)),
            array('batchTrack', array($this->website->nid, $this->id, 20)),
            // loop until hopefuly 100% reached
            array('batchWaitInstalled', array($this->website->nid, $this->id, 20, 6)), // 2min
            array('batchWaitInstalled', array($this->website->nid, $this->id, 20, 6)),
            array('batchWaitInstalled', array($this->website->nid, $this->id, 20, 6)),
          ),
          'finished' => 'batchRebuildDone',
          'file' => drupal_get_path('module', 'webfact') . '/batch.inc',
        );
        batch_set($batch);
        batch_process('website/advanced/' . $this->website->nid); // go here when done
        return;
      }


      /* batchAPI wrapper arput "create" for progress bar */
      else if ($this->action=='createui') {
        $batch = array(
          'title' => t('Creating ' . $this->id),
          'operations' => array(
            array('batchCreateCont', array($this->website->nid, $this->id)),
            // loop for 3 mins until hopefuly 100% reached
            array('batchTrack', array($this->website->nid, $this->id, 10)),
            array('batchTrack', array($this->website->nid, $this->id, 10)),
            array('batchTrack', array($this->website->nid, $this->id, 10)),
            array('batchTrack', array($this->website->nid, $this->id, 10)),
            array('batchTrack', array($this->website->nid, $this->id, 10)),
            array('batchTrack', array($this->website->nid, $this->id, 10)),

            #array('batchTrack', array($this->website->nid, $this->id, 20)),
            #array('batchTrack', array($this->website->nid, $this->id, 20)),
            #array('batchTrack', array($this->website->nid, $this->id, 20)),

            #array('batchTrack', array($this->website->nid, $this->id, 20)),
            #array('batchTrack', array($this->website->nid, $this->id, 20)),
            #array('batchTrack', array($this->website->nid, $this->id, 20)),
          ),
          'finished' => 'batchRebuildDone',
          'file' => drupal_get_path('module', 'webfact') . '/batch.inc',
        );
        batch_set($batch);
        batch_process('website/advanced/' . $this->website->nid); // go here when done

      }

      else if ($this->action=='create') {
        // create the container
        $config = ['Image'=> $this->cont_image, 'Hostname' => $this->fqdn,
                   'Env'  => $this->docker_env, 'Volumes'  => $this->docker_vol
        ];
        #dpm($config);
        $container= new Docker\Container($config);
        $container->setName($this->id);
        if ($verbose == 1) {
          $this->message("create $this->id from $this->cont_image", 'status', 3);
        }
        $manager->create($container);
        if ($verbose == 1) {
          $this->message("start ", 'status', 3);
        }
        #dpm($this->startconfig);
        $manager->start($container, $this->startconfig);

        $msg= "$this->action $this->id: title=" . $this->website->title
          . ", docker image=$this->cont_image" ;
        watchdog('webfact', $msg);

        if ($verbose == 1) {
          $this->message($msg, 'status', 2);
          // inform user:
          $cur_time=date("Y-m-d H:i:s");  // calculate now + 6 minutes
          $newtime=date('H:i', strtotime('+6 minutes', strtotime($cur_time))); // todo setting
          $this->message("Provisioning: you can connect to the new site at $newtime. Select logs to follow progress in real time", 'status');

          // XX: do some ajax to query the build status and confirm when done
          #$this->message("Build=" .$this->getContainerBuildStatus());
          #if ($this->getContainerBuildStatus() == 100) {
          #  $this->message("Build finished");
          #}
          drupal_goto("/website/advanced/$this->nid"); // show new status
          #drupal_goto("/website/logs/$this->nid"); // show new status
        }
        return;
      }

      else if ($this->action=='kill') {
        if (! $container) {
          $this->message("$this->id does not exist", 'warning');
        }
        else if ($container->getRuntimeInformations()['State']['Running'] == FALSE) {
          $this->message("$this->id already stopped", 'warning');
        }
        else {
          $this->message("$this->action $this->id");
          $manager->kill($container);
          drupal_goto("/website/advanced/$this->nid"); // show new status
        }
        return;
      }

      else if ($this->action=='restart') {
        if (! $container) {
          $this->message("$this->id does not exist", 'warning');
        }
        else {
          $this->message("$this->action $this->id");
          $manager->restart($container);
          drupal_goto("/website/advanced/$this->nid"); // show new status
        }
        return;
      }

      else if ($this->action=='stop') {
        if (! $container) {
          $this->message("$this->id does not exist", 'warning');
        }
        else if ($container->getRuntimeInformations()['State']['Running'] == FALSE) {
          $this->message("$this->id already stopped", 'warning');
        }
        else {
          $this->message("$this->action $this->id");
          $manager->stop($container);
          drupal_goto("/website/advanced/$this->nid"); // show new status
        }
        #dpm($container->getId() . ' ' . $container->getExitCode());
        return;
      }

      else if (($this->action=='pause') || ($this->action=='unpause')) {
        $this->message("$this->action $this->id : not implemented", 'warning');
        watchdog('webfact', "$this->action $this->id : todo: not implemented", array(), WATCHDOG_NOTICE);
        return;
      }

      else {  // wait
        #$response = $this->client->post("$this->dserver/containers/$this->id/$this->action");
        #watchdog('webfact', "$this->action $this->id result:" . $response->getStatusCode(), array(), WATCHDOG_NOTICE);
        watchdog('webfact', "unknown action $this->action on $this->id :", array(), WATCHDOG_NOTICE);
      }


    // Exception handling: todo: old code from guzzle, needs a clean
    } catch (ClientException $e) {
      $this->message("ClientException", 'warning');
      $this->result=$e->getResponse()->getStatusCode();
      #$this->message('Unknown http error to docker', 'warning');
      $this->message("$this->action container $this->id: "
        . $e->getResponse()->getBody() . ' code:'
        . $e->getResponse()->getStatusCode() , 'warning');

      #echo '<pre>RequestException Request: ' . $e->getRequest() .'</pre>';
        #echo '<pre>Response ' . $e->getResponse() .'</pre>';
        #echo '<pre>Response ' . $e->getResponse()->getBody() .'</pre>';
        #echo '<pre>' . print_r($cont, true) .'</pre>';

    } catch (ServerException $e) {
      $this->message("ServerException", 'warning');
      $this->message($e->getResponse()->getBody(), 'warning');
      #$this->message('Unknown docker ServerException error', 'warning');

    } catch (RequestException $e) {
#      $this->message($e->getResponse()->getBody(), 'warning');
      $this->message('Unknown RequestException to docker', 'warning');

    } catch (Exception $e) {
      if ($e->hasResponse()) {
        // user friendly error messages
        if ( ($this->action=='create') && ($e->getResponse()->getStatusCode() == '409') ) {
          $this->message("Container already exists", 'warning');
        }
        else if ( $e->getResponse()->getStatusCode() == '404' ) {
          if ($this->action==='create') {
            $this->message("Cannot find container $this->id (or image $this->cont_image)", 'warning');
          } else {
            $this->message("Cannot find container $this->id", 'warning');
          }
        }  // todo: add for each use case

        if (isset($container)) {
           if (isset($container->getRuntimeInformations()['State']['Error'])) {
             $this->message($container->getRuntimeInformations()['State']['Error'], 'error');
           }
        }

        // generic messages
        $this->message($e->getResponse()->getReasonPhrase() .
          #" (error code " . $e->getResponse()->getStatusCode(). " in contAction)" , 'warning');
          " (error code " . $e->getResponse()->getStatusCode(). ")" , 'warning');
        #$this->message($e->__toString(), 'info', 3);

        if ($e->getResponse()->getStatusCode() == '500') {
          // hopefully never arrive here,
          // long detailed trace, but only for level3 messages
          $this->message($e->__toString(), 'warning', 3);
        }
      }
      else {
        $this->message($e->getMessage(), 'error');
      }
    }
  }


 /**
   * This callback is mapped to the path
   * 'website/{action}/{id}'.
   *
   * @param string $action
   *   Container Action: add/delete/stop/start/..
   * @param string $id
   *   Node is to derive the name
   */
  public function arguments($action, $id, $verbose=1) {
    $list=array();
    #$this->message("arguments $action, $id");
    $this->action = $action;  // todo: only allow alpha
    $this->verbose=$verbose;  // todo: remove param, just use setting

    if  (!is_numeric($id)) {
      $this->message("$this->action container: node invalid node id $id", 'error');
      return;
    }
    $this->nid = $id;

    $this->result = -1;     // default: fail
    $this->status = 'n/a';  // default: unknown
    $owner = 'n/a';
    $runstatus = 'n/a';
    $part2 = '';

    try {
      $manager = $this->docker->getContainerManager();

      // container operations must have a node and container
      switch ($action) {
        case 'advanced':
        case 'wait':
        case 'changes':
        case 'events':
        case 'inspect':
        case 'stop':
        case 'start':
        case 'delete':
        case 'deleteui':
        case 'deleteall':
        case 'create':
        case 'createui':
        case 'restart':
        case 'pause':
        case 'kill':
        case 'unpause':
        case 'rebuild':
        case 'rebuildmeta':
        case 'coappupdate':
        case 'coosupdate':
        case 'coget':
        case 'logs':
        case 'processes':
        case 'logtail':
        case 'backup':
        case 'backuplist':
        case 'backuplistdelete':
        case 'imdel':
        case 'imres':
        case 'impull':
        case 'coexport':
        case 'cogetfile':
        case 'cocmd':
        case 'cocopyfile':
        case 'couploadfile':
          // get the node and find the container name
          $this->website = node_load($this->nid);
          //dpm($this->website);
          if ($this->website==null) {
            $this->message("$this->action container: node $this->nid not found", 'error');
            drupal_goto('/websites');   // go back to the list, we are lost
            return;
          }
          if ($this->website->type!='website') {
            $this->message("$this->action container: node $this->nid is not a website (it is type $this->website->type)", 'error');
            return;
          }
          if (empty($this->website->field_hostname['und'][0]['safe_value']) ) {
            $this->message("$this->action container: node $this->nid, hostname is not set ", 'error');
            return;
          }
          $this->id=$this->website->field_hostname['und'][0]['safe_value'];

          // check rights
          $owner=$this->website->name;
          if (($this->user!=$owner) && (!user_access('manage containers')  )) {
            $this->message("Permission denied, $this->user is not the owner ($owner) or admin", 'error');
            break;
          }

          // get status, load meta fields information
          $runstatus = $this->getStatus($this->nid);
          $this->load_meta();

          #$container = $manager->find($this->id);
          break;
    }



    /* 
     * direct path that returns just data
     * no menus.  see js/buildstatus.js
     * todo: use json, or move to services?
     * e.g. 
     * {"status":"running","buildStatus":"200"}
     */
    if ($action=='coget') {
      if ( ! user_access('manage containers')) {
        $this->message("Permission denied, $this->user is not admin", 'error');
        break; // drop through to menu
      }
      // $id has been loaded above already
      #echo "<p id=getStatus>" . $this->getStatus($this->nid) .'</p>';
      #echo "<p id=getContainerBuildStatus>" . $this->getContainerBuildStatus() .'</p>';
      drupal_add_http_header('Content-Type', 'application/json');
      echo json_encode(array('status'=>$this->getStatus($this->nid),
        'buildStatus' => $this->getContainerBuildStatus() ));
      return;
    }



    // check permission, call action, handle feedback
    switch ($action) {

      // image management
      case 'events':   // todo: just hangs
        if ( ! user_access('manage containers')) {
          $this->message("Permission denied, $this->user is not admin", 'error');
          break;
        }
      case 'images':
      case 'version':
        $result=$this->imageAction();
        if ($verbose==0) {
          $render_array['webfact_arguments'][0] = array(
            '#result' => $this->result,
            '#status' => $this->status,
          );
          return $render_array;  // dont sent back any html
        }
        break;


      // image management
      case 'wait':       // Block until container id stops, then return the exit code. TODO: broken
        $this->client->setDefaultOption('timeout', 25);
      case 'changes':
        if ( ! user_access('manage containers')) {
          $this->message("Permission denied, $this->user is not admin", 'error');
          break;
        }
      case 'inspect':
        // todo: should only be for the owner but views needs to be able to query the status
        // todo   $this->client->setDefaultOption('timeout', 100);
        $result=$this->contAction();
        if ($verbose==0) {
          $render_array['webfact_arguments'][0] = array(
            '#result' => $this->result,
            '#status' => $this->status,
          );
          return $render_array;  // dont sent back any html
        }
        break;

      // the following are immediate actions, redirected back to the caller page
      case 'stop':
      case 'start':
      case 'delete':
      case 'deleteui':
      case 'restart':
      case 'pause':
      case 'kill':
      case 'unpause':
      case 'create':
      case 'createui':
      case 'rebuild':
      case 'rebuildmeta':
      case 'coappupdate':
      case 'coosupdate':
        if (($this->user!=$owner) && (! user_access('manage containers')  )) {
          $this->message("Permission denied, $this->user is not the owner ($owner) or admin", 'error');
          break;
        }
        $result=$this->contAction($verbose);
        if (isset($_GET['destination'])) {  // go back where we were
          #dpm(request_uri());
          $from = drupal_parse_url($_GET['destination']);
          drupal_goto($from['path']);
        }
        break;

      #case 'coget':
      case 'logs':
      case 'deleteall':
      case 'processes':
        if (($this->user!=$owner) && (!user_access('manage containers')  )) {
          $this->message("Permission denied, $this->user is not the owner ($owner) or admin", 'error');
          break;
        }
      case 'containers':
        $result=$this->contAction();
        break;


      // reverse proxy management
      case 'proxy2restart':
      case 'proxyrestart':
        //$this->id=$this->rproxy;
        if ($action == 'proxy2restart') {
          $this->id = variable_get('webfact_rproxygen', 'nginx-gen');
        } else {
          $this->id = $this->rproxy;
        }
        $container = $manager->find($this->id);
        if (! $container) {
          $this->message("$this->id does not exist");
          break;
        }
        watchdog('webfact', 'Restart reverse proxy ' . $this->id);
        $this->message('Restart reverse proxy ' . $this->id);
        $manager->restart($container);
        break;

      case 'proxyconf':
        $cmd = 'cat ' . variable_get('webfact_rproxy_conf', '/etc/nginx/conf.d/default.conf');
        $log = $this->runCommand($cmd, $this->rproxy, variable_get('webfact_rproxy_confsize', 50000)); 
        $this->markup = '<pre>' . $cmd . '<br>__________<br>' . $log . '</pre>';
        break;

      case 'proxylogs':
      case 'proxy2logs':
        if ($action == 'proxy2logs') {
          $this->id = variable_get('webfact_rproxygen', 'nginx-gen');
        } else {
          $this->id = $this->rproxy;
        }
        $container = $manager->find($this->id);
        if (! $container) {
          $this->message("$this->id does not exist");
          break;
        }
        else {
          $logs=$manager->logs($container, false, true, true, false, variable_get('webfact_rproxy_loglines', 1000));
          $this->markup = '<pre>Logs for ' . $this->id. ' (' . variable_get('webfact_rproxy_loglines', 1000) 
            . ' lines)<br>';
          foreach ($logs as $log) {
            $this->markup .= $log['output'];
          }
          $this->markup .= '</pre>';
        }
        break;


      case 'advanced':  // just drop through to menu below
        // two ways of updating the status regularly
        // a) browser refresh every XX secs
          #$meta_refresh = array(    // refresh status every minute
          # '#type' => 'html_tag', '#tag' => 'meta',
          # '#attributes' => array( 'content' =>  '60', 'http-equiv' => 'refresh',));
          #drupal_add_html_head($meta_refresh, 'meta_refresh');
        // b) Setup polling via ajax
        drupal_add_js(array('webfact' => array(
          'webfact_site_check' => '1',
          'webfact_nid'        => $this->website->nid,
          'time_interval'      => 10000, // ms
        )), 'setting');
        $website_status = '<div class="loader"></div>' . t('Please wait...');
        drupal_add_js(drupal_get_path('module', 'webfact') . '/js/buildstatus.js', 'file');

        break;     // just through to menu below


      case 'imres':     // restore an image to the current container
      case 'imdel':     // delete a named image
        $this->client->setDefaultOption('timeout', 40);
        if (($this->user!=$owner) && (!user_access('manage containers')  )) {
          $this->message("Permission denied, $this->user is not the owner ($owner) or admin", 'error');
          break;
        }
        # todo: is there a better way to get at URI query arguments?
        $_url = drupal_parse_url($_SERVER['REQUEST_URI']);
        if (! isset ($_url['query']['image']) ) {
          $this->message("Image name argument missing", 'error');
          break;
        }
        # security: the first part of the image name must be the same as the container name
        $imname=$_url['query']['image'];
        if ( ! preg_match("/^$this->id:(.+)/", $imname, $matches) ) {
          $this->message("Image name $imname does not start with $this->id", 'error');
          break;
        }
        $imagemgr = $this->getImageManager();
        $thismage = $imagemgr->find($this->id, $matches[1]);

        if ($action == 'imdel') {
          $imagemgr->remove($thismage);
          $this->message("Image $matches[0] deleted");
        }

        else if ($action == 'imres') {
          $container = $manager->find($this->id);
          if (! $container) {
            $this->message("$this->id does not exist", 'error');
            return;
          }
          // Stop accidental deleting of key containers
          if (stristr($this->category, 'production')) {
            $this->message("$this->id is categorised as production, delete+restore not allowed.", 'warning');
            return;
          }

          // change image configured for this node
          $this->cont_image = $thismage->__toString();
          $this->website->field_docker_image['und'][0]['value'] = $this->cont_image;
          $this->website->revision = 1;    // history of changes
          $this->website->log = 'change docker_image ' . $this->cont_image
            . " by $this->user on " . date('c');
          node_save($this->website);     // Save the updated node
          $this->website=node_load($this->website->nid);  # necessary to reload the node to avoid caching

          // stop, delete:
          $manager->stop($container)
                  ->remove($container);
          $this->message("restoring: existing container stopped, deleted.", 'status', 2);
          $this->action = 'create';
          $this->contAction(0);     // 0=no verbose message
        }
        if (isset($_GET['destination'])) {  // go back where we were
          $from = drupal_parse_url($_GET['destination']);
          drupal_goto($from['path']);
        }
        break; // imdel|imres


      case 'impull':  // download the latest version of an image
        $this->client->setDefaultOption('timeout', 60);
        if (($this->user!=$owner) && (!user_access('manage containers')  )) {
          $this->message("Permission denied, $this->user is not the owner ($owner) or admin", 'error');
          break;
        }

        $pull_callback=function ($output, $type) {
           if (array_key_exists('status', $output)) {
             #print($output['status'] . "\n");
             $this->markup .= $output['status'] . "\n";
           } else if (array_key_exists('error', $output)) {
             $this->markup .= $output['error'] . "\n";
           }
           #todo else
        };

        $this->markup = "<pre>";
        $container = $manager->find($this->id);
        if ($container) {
          # find() does inspect() which gets ['Config'] infos, giving the image name
          $cont=$container->getRuntimeInformations();
          $str=$cont['Config']['Image'];
          if (preg_match('/(.*):(.*)/', $str, $matches)) { // vanilla2:20015
            #print_r($matches);
            $imrepo=$matches[1];
            $imtag=$matches[2];
          } else {
            $imrepo=$str;
            $imtag='latest';  // presume there is no tag
          }
          $this->markup .= "Container $this->id is based on image $imrepo tag:$imtag, pulling that from https://hub.docker.com/\n";
          $imagemgr = $this->getImageManager();
          $image=$imagemgr->pull($imrepo, $imtag, $pull_callback);
        }
        $this->message("pull image $imrepo tag:$imtag");
        $this->markup .= '</pre>';
        break;       // impull


      case 'backuplist':  // list images of current container
        $this->client->setDefaultOption('timeout', 60);
        // todo: cache the image list for speed
        if (($this->user!=$owner) && (!user_access('manage containers')  )) {
          $this->message("Permission denied, $this->user is not the owner ($owner) or admin", 'error');
          break;
        }
        $imagemgr = $this->getImageManager();
        $images = $imagemgr->findAll();
        $this->markup .= '<div class="container-fluid">';
        $this->markup .= "<h3>Backup images of $this->id</h3>";
        $imagenid = $this->nid;
        foreach ($images as $image) {
          if (! strcmp($this->id, $image->getRepository())) {
            $data = $imagemgr->inspect($image);
            $imagename = $image->__toString();
            $imageline = $this->parseDockerDate($data['Created']) . " ${data['Author']}, ${data['Comment']}";
            $destination = drupal_get_destination();
            $imagearg = $imagename . '&destination=' . $destination['destination']; // remember where we were
            #print("$imageline " . "\n");

            $html = <<<END
<!-- Bootstrap: -->
<div class="row">
<div class="col-xs-3"><p>$imagename</p></div>
<div class="col-xs-7"><p>$imageline</p></div>
<!-- dropdown button -->
<div class="col-xs-2">
<div class="btn-group">
  <button type="button" class="btn btn-default dropdown-toggle" data-toggle="dropdown" aria-expanded="false">
    Action <span class="caret"></span>
  </button>
  <ul class="dropdown-menu" role="menu">
    <li><a href="/website/imres/${imagenid}?image=$imagearg">Restore now</a></li>
    <li class="divider"></li>
    <li><a href="/website/imdel/${imagenid}?image=$imagearg">Delete now</a></li>
  </ul>
</div>
</div>
</div>
END;
            $this->markup .= $html;
          }
        }
        $this->markup .= '</div>';
        break;

      case 'backuplistdelete':  // delete images of current container
        $this->client->setDefaultOption('timeout', 180);
        if (($this->user!=$owner) && (!user_access('manage containers')  )) {
          $this->message("Permission denied, $this->user is not the owner ($owner) or admin", 'error');
          break;
        }
        $imagemgr = $this->getImageManager();
        $this->markup .= '<div class="container-fluid">';
        $this->markup .= "<h3>Delete backup images of $this->id</h3>";
        $this->message("Finding images ..", 'status', 3);
        $images = $imagemgr->findAll();
        $imagenid = $this->nid;
        foreach ($images as $image) {
          if (! strcmp($this->id, $image->getRepository())) {  // look for names
            $data = $imagemgr->inspect($image);
            #dpm($data);
            $imagename = $image->__toString();
            $this->message("Deleting $imagename", 'status', 3);
            watchdog('webfact', "deleting $imagename");
            try {
              $imagemgr->remove($image);
            } catch (Exception $e) {   // ignore 409s, seems to work anyway
              if ($e->getResponse()->getStatusCode() == 409) {
                ; // ignore conflicts
                #dpm($e->getResponse());
              } else {
                throw($e);
              }
            }
            $this->markup .= "<p>deleted $imagename</p>";
          }
        }
        $this->message("Deleting done");
        $this->markup .= '<p>Deleting done</p></div>';
        break;


      case 'backup':  // commit a container to an image
        global $base_root;
        $this->client->setDefaultOption('timeout', 80);
        if (($this->user!=$owner) && (!user_access('manage containers')  )) {
          $this->message("Permission denied, $this->user is not the owner ($owner) or admin", 'error');
          break;
        }
        $container = $manager->find($this->id);
        if ($container) {
          $config = array('tag' => date('Ymd'), 'repo' => $this->id, 'author' => $this->user,
            'comment' => "saved on $base_root ",);
          $savedimage = $this->docker->commit($container, $config);
          if ($this->verbose===1) {
            $this->message("saved $this->id to image " . $savedimage->__toString() );
          }
        } else if ($this->verbose===1) {
          $this->message("No container $this->id to backup" );
        } else {
          // fail silently
        }
        break;


      case 'cocmd':
        $this->client->setDefaultOption('timeout', 60);
        $this->markup = '<div class="container-fluid">';
        $html = <<<END
<!-- Bootstrap: -->
<form >
  <legend>Run a command inside the container</legend>
  <textarea id="runcommand" name="cmd" type="text" placeholder="" class="form-control" rows="3" style="font-family:monospace; background-color: black; color: white;"></textarea>
  <p class="help-block">e.g. A non-blocking command such as: /bin/date or 'cd /var/www/html; ls' or 'tail 100 /var/log/apache2/*error*log' or 'cd /var/www/html; drush watchdog-show'. This screen automatically refreshes every 5 seconds.</p>
  <button id="submit" name="submit" class="btn btn-primary btn-lg">
    <span class="glyphicon glyphicon-play-circle" aria-hidden="true"></span>
    Run
  </button>
  <hr>
</form>
END;
        $this->markup .= $html;
        $_url = drupal_parse_url($_SERVER['REQUEST_URI']);
        if (isset ($_url['query']['cmd']) ) {
          $cmd = $_url['query']['cmd'];   // todo: security checking?
          $result = $this->runCommand($cmd);
          $this->markup .= "<pre>Output of the command '$cmd':<br>" . $result . '</pre>';
        }
        break;


      case 'couploadfile':     // download a folder from the container
        $this->client->setDefaultOption('timeout', 300);
        $container = $manager->find($this->id);
        if (! $container) {
          $this->message("$this->id does not exist");
          break;
        }
        $html = <<<END
<!-- Bootstrap: -->
<form action="/website/couploadfile/$this->nid" method="post" enctype="multipart/form-data">
<fieldset>
<legend>Upload a file to the container</legend>
<!-- input-->
<div class="col-xs-5">
  <div class="control-group">
    <div class="controls">
      <input type="file" name="fileToUpload" id="fileToUpload">
      <p class="help-block">Select a file to upload</p>
    </div>
  </div>
</div>
<!-- Button -->
<div class="col-xs-5">
  <div class="control-group">
    <div class="controls">
      <input type="submit" value="Upload" name="submit">
    </div>
  </div>
</div>
</fieldset>
</form>
END;
        $this->markup .= $html;
        $this->message("file upload not yet implemented", "error"); // TODO
        break;

        # 1. Upload the file to this webserver
        if(isset($_POST["submit"])) {
          #dpm($_FILES);
          $target_dir = "/tmp/couploadfile/";
          if (!is_dir($target_dir)) { mkdir($target_dir); }
          $target_file = $target_dir . basename($_FILES["fileToUpload"]["name"]);
            // todo: first check file type, size?
            if (move_uploaded_file($_FILES["fileToUpload"]["tmp_name"], $target_file)) {
              $this->message("The file ". basename( $_FILES["fileToUpload"]["name"]). " has been uploaded.");
            } else {
              $this->message("Sorry, there was an error uploading your file.", 'error');
            }
        }

/*
        # 2. send it to the container
        $container = $manager->find($this->id);
        if (! $container) {
          $this->message("$this->id does not exist");
          break;
        }
        #$execid = $manager->exec($container, ["/bin/bash", "-c", "cat >/tmp/upload.file"]);
        #$result=$manager->execstart($execid);
*/
//TODO
        break;


      case 'cocopyfile':     // download a folder from the container
        $html = <<<END
<!-- Bootstrap: -->
<form >
<fieldset>
<legend>Download a file/folder from the container</legend>
<!-- Button -->
<div class="col-xs-2">
  <div class="control-group">
    <div class="controls">
      <button id="submit" name="submit" class="btn btn-default">Download</button>
    </div>
  </div>
</div>
<!-- Text input-->
<div class="col-xs-10">
  <div class="control-group">
    <div class="controls">
      <input id="textinput-0" name="cmd" type="text" placeholder="" class="input-xxlarge">
      <p class="help-block">A file or folder e.g. '/var/log/apache2', '/var/www/html/sites/default/files', '/var/www/html/sites/all/modules/custom'</p>
    </div>
  </div>
</div>
</fieldset>
</form>
END;
        $this->markup .= $html;
        $_url = drupal_parse_url($_SERVER['REQUEST_URI']);
        if (! isset ($_url['query']['cmd']) ) {
          //dpm($_url['query']);
          break;
        }
        $resource = $_url['query']['cmd'];   // todo: security checking?
        $container = $manager->find($this->id);
        if (! $container) {
          $this->message("$this->id does not exist");
          break;
        }
        # 1. Get  the folder form the container as tar file
        $tarFileName = $this->id . '.resource.tar';
        $tarpath = file_directory_temp() . '/' . $tarFileName;
        $manager->copyToDisk($container, $resource, $tarpath);
        # 2. send tar file to the browser
        $drupalpath = 'temporary://' . $tarFileName;   // drupal temp folder/stream
        $this->tar_file_transfer($drupalpath, $tarpath);
        # 3. delete the tar file
        if (! unlink($tarpath) ) {
          watchdog('webfact', "Container export, could not delete $tarpath", array(), WATCHDOG_NOTICE);
        };
        //$this->message("sent $tarFileName");  // will not work due to buffering
        break;


      case 'coexport':     // export a container as a tarfile
        $this->client->setDefaultOption('timeout', 300);
        if (($this->user!=$owner) && (!user_access('manage containers')  )) {
          $this->message("Permission denied, $this->user is not the owner ($owner) or admin", 'error');
          break;
        }
        # 1. get the container, choose tarfile name
        $container = $manager->find($this->id);
        $tarFileName = $this->id. "-export-" . date('Ymd') . ".tar";
        $exportStream = $manager->export($container);   // will use massive memory?
        # 2. export to tar
        $this->message("export to $tarFileName", 3);
        $tarpath = file_directory_temp() . '/' . $tarFileName;
        $tarfp = fopen($tarpath, 'w+');
        stream_copy_to_stream($exportStream->detach(), $tarfp); // write to tarball
        fclose($tarfp);
        # 3. send tar file to the browser
        $drupalpath = 'temporary://' . $tarFileName;   // drupal temp folder/stream
        $this->tar_file_transfer($drupalpath, $tarpath);
        if (! unlink($tarpath) ) {
          watchdog('webfact', "Container export, could not delete $tarpath", array(), WATCHDOG_NOTICE);
        };
        //$this->message("export $tarFileName");  // will not work due to buffering
        break;


      case 'logtail':
        /* todo: try to do real-time log tailing  DOES NOT WORK YET
         * see http://stackoverflow.com/questions/8765251/printing-process-output-in-realtime
         */
        if (($this->user!=$owner) && (!user_access('manage containers')  )) {
          $this->message("Permission denied, $this->user is not the owner ($owner) or admin", 'error');
          break;
        }
        $container = $manager->find($this->id);
        #header("Content-type: text/plain");
    #    disable_ob();
    #    echo '<pre>';
        #$response=$manager->attach($container, function ($output, $type) {print($output);} , true);
        #$response->getBody()->getContents();
        #$manager->attach($container, function($output, $type) {print($output);} , true)->getBody()->getContents();
/*
        ini_set('output_buffering', 'off');
        ini_set('zlib.output_compression', false);
        ini_set('implicit_flush', true);
        ob_implicit_flush(true);
        echo "<div style=\"display:none\"></div>";
        #$a = 1/0;

        echo "<pre>";
        echo "---- todo: Realtime event:  ping -c 15 dock2 ------";
        system('ping -c 15 dock2');
        echo "</pre>";
*/

        return;

      default:
        //$this->list[] = t("Unknown action @act", array('@act' => $action));
        //$this->list[] = t("ID  @id.", array('@id' => $id));
        break;
      }


    } catch (ImageNotFoundException $e) {
      $this->message($e->getMessage(), 'warning');
    } catch (RequestException $e) {
      if ($e->hasResponse()) {
        $this->message($e->getResponse()->getBody(), 'warning');
      }
      else {
        $this->message('Unknown http RequestException to docker', 'warning');
      }
    } catch (Exception $e) {
      if ($e->hasResponse()) {
        $this->message($e->getResponse()->getReasonPhrase() .
          " (error code " . $e->getResponse()->getStatusCode(). " for action=$action in arguments())" , 'warning');
        $this->message("Response details: " . $e->getResponse()->__toString(), 'warning', 3);
      }
      else {
        $this->message($e->getMessage(), 'error');
      }
    }
    #watchdog('webfact', "arguments $action, $this->id $this->nid $owner");




    // quick links to actions
    // todo: what is the right (secure) way to create links?
    $wpath='/website';
    $destination = drupal_get_destination();
    $des = '?destination=' . $destination['destination']; // remember where we were
    $nav = $this->nav_menu($wpath, $des);

    // send back the HTML to be shown on the page
    $render_array['webfact_arguments'] = array();
    // prepare the top, summary part of the page
    $fqdn="http://$this->id.$this->fserver";
    $description ='';

    // prepare colour status
    $statushtml='<div id=website-status>';
    if ( ($runstatus == 'running') || ($runstatus == 'stopped') ) {
      $statushtml .= "<div class=$runstatus>$runstatus</div>";
    }
    else {
      $statushtml .= "$runstatus";
    }
    $statushtml .= '</div>';

    if ($this->website) {
      $description .='<div class=container-fluid>';
      $description .='<h3>' . $this->website->title . '</h3>'
       . "<div class=col-xs-6><a href=/node/$this->nid/edit$des>Meta data</a></div>"
       . "<div class=col-xs-6><strong>Actual</strong></div>"

       . "<div class=col-xs-2>Website:</div> <div class=col-xs-4>"
       .   "<a target=_blank href=///$this->id.$this->fserver>$this->id.$this->fserver</a></div>"
       . "<div class=col-xs-2>Run status:</div> <div class=col-xs-4>$statushtml</div>"

       . "<div class=col-xs-2>Category:</div> <div class=col-xs-4>$this->category</div>"
       . "<div class=col-xs-2><abbr title='If docker operations failed, an explanation may be show here'>Error</abbr>:</div> <div class=col-xs-4>($this->actual_error)</div>"

       . "<div class=col-xs-2>Auto start:</div> <div class=col-xs-4>$this->restartpolicy</div>"
       . "<div class=col-xs-2>Auto start:</div> <div class=col-xs-4>($this->actual_restart)</div>"

       . "<div class=col-xs-2>Owner:</div> <div class=col-xs-10>$owner</div>"
      ;
      if (isset($this->website->body['und'][0]['safe_value'])) {
        $description.= "<div class=col-xs-2>Description:</div> <div class=col-xs-10>"
          . $this->website->body['und'][0]['safe_value'] . "</div>";
      }

      $description.= '<div class="clearfix"></div><h4>Run time:</h4>';
      // 18.3.15/SB: uptime disabled since it refers to docker host, not container
      //$uptime = $this->runCommand('uptime');
      //$description.= "<div class=col-xs-2><abbr title='Result of the uptime command run within the container'>Uptime</abbr>:</div> <div class=col-xs-10>$uptime</div>";
      $this->getContainerStatus();    // grab git status
      $this->getContainerBuildStatus();  
      #if (strlen($this->actual_status)>0) {
        $description.= "<div class=col-xs-2><abbr title='If /var/www/html/webfact_status.sh exists it is run and the output is show here. It could be the last git commit for example.'>App status</abbr>:</div> <div class=col-xs-4>$this->actual_status</div>";

      if (strlen($this->actual_buildstatus)>0) {
        $description.= "<div class=col-xs-2><abbr title='Build completion % for a Drupal website (may go to 200 where provisioning required).'>Build status</abbr>:</div> <div class=col-xs-4><div id=buildstatus>$this->actual_buildstatus</div> <div id=bs-postfix></div></div>";
      } else {
        $description.= "<div class=col-xs-4>.</div>";
      }
      $description.= '</div></div>';
    }
    $description.= '<div class="clearfix"></div>';

    $render_array['webfact_arguments'][0] = array(
      '#type'   => 'markup',
      '#markup' => $nav,
    );
    $render_array['webfact_arguments'][1] = array(
      '#type'   => 'markup',
      '#markup' => $description,
    );
/*
    $render_array['webfact_arguments'][2] = array(
      '#type'   => 'markup',
      '#markup' => $part2,
      '#result' => $this->result,
      '#status' => $this->status,
    );
*/
    /* any batch result? If so display at the bottom
     * and empty the batch array.
     */
    if ( (isset($_SESSION['batch_results'])) 
        && (is_array($_SESSION['batch_results'])) ) {
      #dpm($_SESSION['batch_results']);   
      $batchlog='<h3>Operation log</h3><div id=batchlog>';
      foreach ($_SESSION['batch_results'] as $line) {
        #$batchlog .= check_plain($line) .', '; // sanitise
        $batchlog .= filter_xss($line) .'<br/> '; // sanitise
      }
      $batchlog .= '</div>';
      $render_array['webfact_arguments'][2] = array(
        '#type'   => 'markup',
        '#markup' => $batchlog,
      );
      unset($_SESSION['batch_results']);  // empty log for next operation
    }


    if (!empty($this->markup) ) {
      $render_array['webfact_arguments'][3] = array(
        '#type' => 'markup',
        '#markup' => '<h3> </h3>' . $this->markup,
      );
    }

    return $render_array;
  }
}      // class



