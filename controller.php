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
  protected $actual_restart, $actual_error, $actual_git;


  public function __construct() {
    global $user;
    $account = $user;
    #watchdog('webfact', 'WebfactController __construct()');
    $this->markup = '';
    $this->verbose = 1;
    $this->category = 'none';
    $this->docker_ports = array();
    $this->fqdn = '';

    # Load configuration defaults, override in settings.php or on admin/config/development/webfact
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
    #$this->client = new Docker\Http\DockerClient(array(), 'tcp://195.176.209.22:2375');
    # 'unix:///var/run/docker.sock'
    $this->client->setDefaultOption('timeout', 30);     // default should be a parameter?
    $this->docker = new Docker\Docker($this->client);
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
   * helper function. hack drupal file_transer(9 to set caching headers
   * and not call drupal exit.
   * https://api.drupal.org/api/drupal/includes%21file.inc/function/file_transfer/7
   */
  private function tar_file_transfer($uri, $filename) {
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
                  <li><a href="$wpath/create/$this->nid">Create</a></li>
                  <li class="divider"></li>
                  <li><a href="$wpath/delete/$this->nid" onclick="return confirm('Are you sure?')">Delete</a></li>
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

            <ul class="nav navbar-nav">
              <li class="dropdown">
                <a href="#" class="dropdown-toggle" data-toggle="dropdown" role="button" aria-expanded="false">Advanced<span class="caret"></span></a>
                <ul class="dropdown-menu" role="menu">
                  <li><a href="$wpath/inspect/$this->nid">Inspect</a></li>	
                  <li><a href="$wpath/cocmd/$this->nid">Run command</a></li>
                  <li class="divider"></li>
                  <li><a href="$wpath/coappupdate/$this->nid" onclick="return confirm('Backup the container and run webfact_update.sh to update the website?')">Run website update</a></li>
<!-- coosupdate prototype, not ready:
                  <li><a href="$wpath/coosupdate/$this->nid" onclick="return confirm('Backup, Stop+rename the container, create new container and resotore data&DB?')">Run container OS update</a></li>
-->
                  <li class="divider"></li>
                  <li><a href="$wpath/rebuild/$this->nid" onclick="return confirm('$rebuild_src_msg')">Rebuild from sources</a></li>
                  <li><a href="$wpath/rebuildmeta/$this->nid" onclick="return confirm('$rebuild_meta_msg')">Rebuild from meta-data</a></li>
                  <li class="divider"></li>
                  <li><a href="$wpath/cocopyfile/$this->nid">Folder download</a></li>
      <!--        <li><a href="$wpath/couploadfile/$this->nid">File Upload</a></li>	  -->
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
                  <li><a href="$wpath/proxyrestart/0$this->des" onclick="return confirm('Restarting nginx will break all sessions, refresh the page manually after a few secs. ')">Restart nginx</a></li>
                  <li><a href="$wpath/proxy2restart/0$this->des">Restart nginx-gen</a></li>
                  <li><a href="$wpath/proxylogs/0$this->des">Logs nginx</a></li>
                  <li><a href="$wpath/proxy2logs/0$this->des">Logs nginx-gen</a></li>
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
   * no checking on permissions,
   * todo: this is an experimental abstraction, initially used in hook_node_delete()
   */
  public function deleteContainer($name) {  
     $manager = $this->getContainerManager();
     $container = $manager->find($name);

     // todo: use nid, not name, and check if in production?
     #   if (stristr($this->category, 'production')) {
     #     $this->message("$this->id is categorised as production, deleting not allowed.", 'warning');
     #     return;
     #   }
     $manager->stop($container);
     $manager->remove($container);
     watchdog('webfact', "deleteContainer $name");
  }


  /*
   * create a container: test fucntion, really abstract this bit out?
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
   * todo: what is $this-> is not loaded?
   */
  private function load_meta() {
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
    sort($this->docker_env);

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
   * run a commind inside the conatiner and give back the results
   */
  protected function runCommand($cmd) {
    // todo: 
    // - check status code and do a watchdog or interactive error?

    $manager = $this->getContainerManager();
    $container = $manager->find($this->id);
    if ((strlen($cmd)<1) || ($container==null) || ($container->getRuntimeInformations()['State']['Running'] == FALSE)) {
      return;  // container not running, abort
    }

    $this->message("Running command: $cmd", 'status', 4);
    $execid = $manager->exec($container, ['/bin/bash', '-c', $cmd]);
    #dpm("Exec ID= <" . $execid. ">\n");
    #$response = $manager->execstart($execid, function(){} ,false,false);
    $response = $manager->execstart($execid);
    #dpm($response->getStatusCode());
    #dpm($response->__toString());
    if ($body = $response->getBody()) {
      $body->seek(0);
      $result = $body->read(4096); // get first 4k, todo: parameter?
    }
    #dpm($result);
    return(trim($result, "\x00..\x1F"));  // trim all ASCII control characters
  }

  protected function getGit() {
    // todo: improve the command, or make it a setting?
    $cmd = "if [ -d /var/www/html ] && cd /var/www/html && if [ -x 'webfact_status.sh' ] ; then ./webfact_status.sh; fi";
    #$cmd = "cd /var/www/html && ls";
    $this->actual_git = $this->runCommand($cmd);
  }


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
        $response = $this->client->get(["/events?since=2014-12-23",[]]);
        //$response = $this->client->get("/events?filter{'container':'mycontainer'}");
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
    //watchdog('webfact', "contAction() $this->action");
    try {
      $manager = $this->docker->getContainerManager(); 
      $container = $manager->find($this->id);

      if ($this->action=='delete') {
        // Stop accidental deleting of key containers
        if (stristr($this->category, 'production')) {
          $this->message("$this->id is categorised as production, deleting not allowed.", 'warning');
          return;
        }

        if (! $container) {
          $this->message("$this->id does not exist",  'error');
        }
        else if ($container->getRuntimeInformations()['State']['Running'] == TRUE) {
          $this->message("$this->id must be stopped first", 'warning');
        }
        else {
          $this->message("$this->action $this->id");
          $manager->remove($container);
          watchdog('webfact', "$this->action $this->id ", array(), WATCHDOG_NOTICE);
          drupal_goto("/website/advanced/$this->nid"); // show new status
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
        // a) if /data a volume, does sit have a mapping to a host directory?
        $datavolsrc = '/data'; // todo: parameter
        $datavol = $container->getRuntimeInformations()['Volumes'];
        if (! isset($datavol[$datavolsrc]) ) {
          $this->message("The container does not have a $datavolsrc volume.", 'error');
          #dpm($datavol);
          return;
        } else {
          $datavolmap = $datavol[$datavolsrc]; 
          if (strlen($datavolmap)<1) {
            $this->message("The container does not have a $datavolsrc volume mapped to a server directory.", 'error');
            #dpm($datavolmap);
            return;
          }
        }
        #dpm($datavolmap);

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

        // process
        watchdog('webfact', "coappupdate - backup, update", WATCHDOG_NOTICE);
        $this->message("Run /root/backup.sh (see results below)", 'status', 2); // check result?
        $logs = $this->runCommand("/root/backup.sh && ls -altr /data");
        $this->message("Stop $this->id", 'status', 3);
        $manager->stop($container);
        $this->message("Rename $this->id to $this->id" . '-preupdate', 'status', 3);
        $manager->rename($container, $this->id . '-preupdate');
        $this->message("Create new $this->id (but how do we know when it is done?)", 'status', 3);
        $this->create();    // new container from meta data

        $logs .= "<br>, stopping, renaming";
        $this->markup = "<h3>Update results</h3>Run /root/backup.sh && ls -altr /data :<pre>$logs</pre>";   // show output
        return;
      }
*/


      else if ($this->action=='coappupdate') {
        global $base_root;
        $this->client->setDefaultOption('timeout', 60);   // backups can take time
        // Stop accidental deleting of key containers
        #if (stristr($this->category, 'production')) {
        #  $this->message("$this->id is categorised as production, rebuild/delete not allowed.", 'error');
        #  return;
        #}
        if (! $container) {
          $this->message("$this->id does not exist", 'warning');
          return;
        }
        // backup, stop, delete:
        watchdog('webfact', "coappupdate - backup, update", WATCHDOG_NOTICE);
        $config = array('tag' => date('Ymd') . '-before-update', 'repo' => $this->id, 'author' => $this->user,
          'comment' => "saved before app update on $base_root",
        );
        $savedimage = $this->docker->commit($container, $config);
        $this->message("Saved to " . $savedimage->__toString(), 'status', 3);
        $this->message("Run webfact_update.sh (see results below)", 'status', 2);
        $logs = $this->runCommand("cd /var/www/html && ./webfact_update.sh");
        $this->markup = "<h3>Update results</h3><pre>$logs</pre>";   // show output
        $this->message("stop ", 'status', 3);
        $manager->stop($container);
        return;
      }

      else if ($this->action=='rebuildmeta') {
        global $base_root;
        $this->client->setDefaultOption('timeout', 30);
        // Stop accidental deleting of key containers
        if (stristr($this->category, 'production')) {
          $this->message("$this->id is categorised as production, rebuild/delete not allowed.", 'error');
          return;
        }
        if (! $container) {
          $this->message("$this->id does not exist", 'warning');
          return;
        }
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
        $this->message("Click on 'inspect' to verify the new environment", 'status', 2);
        return;
      }


      else if ($this->action=='rebuild') {
        global $base_root;
        $this->client->setDefaultOption('timeout', 30);
        // Stop accidental deleting of key containers
        if (stristr($this->category, 'production')) {
          $this->message("$this->id is categorised as production, rebuild/delete not allowed.", 'error');
          return;
        }
        if (! $container) {
          $this->message("$this->id does not exist", 'warning');
          return;
        }
        // backup, stop, delete: 
        watchdog('webfact', "rebuild - backup, stop, delete, create from sources", WATCHDOG_NOTICE);
        $config = array('tag' => date('Ymd') . '-rebuild', 'repo' => $this->id, 'author' => $this->user,
          'comment' => "saved before source rebuild on $base_root",
        );
        $savedimage = $this->docker->commit($container, $config);
        $this->message("saved to " . $savedimage->__toString(), 'status', 3);
        if ($this->getStatus($this->nid) == 'running' ) {
          $this->message("stop ", 'status', 3);
          $manager->stop($container);
        }
        $this->message("remove ", 'status', 3);
        $manager->remove($container);
        $this->message("rebuilding: saved to " . $savedimage->__toString() .", stopped+deleted.", 'status', 2);

        $this->action='create';
        $this->contAction();
        $this->message("Click on 'logs' to track progress", 'status', 2);
        return;
      }


      else if ($this->action=='create') {
        // create the container
        $config = ['Image'=> $this->cont_image, 'Hostname' => $this->fqdn,
                   'Env'  => $this->docker_env, 'Volumes'  => $this->docker_vol
        ];
        #dpm($config);
        $container= new Docker\Container($config);
        $container->setName($this->id);
        $this->message("create $this->id from $this->cont_image", 'status', 3);
        $manager->create($container);
        $this->message("start ", 'status', 3);
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
          if ($this->$action==='create') {
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

    //$this->client->setDefaultOption('timeout', 20);  //todo: parameter
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
        case 'create':
        case 'restart':
        case 'pause':
        case 'kill':
        case 'unpause':
        case 'rebuild':
        case 'rebuildmeta':
        case 'coappupdate':
        case 'coosupdate':
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
      case 'restart':
      case 'pause':
      case 'kill':
      case 'unpause':
      case 'create':
      case 'rebuild':
      case 'rebuildmeta':
      case 'coappupdate':
      case 'coosupdate':
        if (($this->user!=$owner) && (! user_access('manage containers')  )) {
          $this->message("Permission denied, $this->user is not the owner ($owner) or admin", 'error');
          break;
        }
        $result=$this->contAction();
        if (isset($_GET['destination'])) {  // go back where we were
          #dpm(request_uri());
          $from = drupal_parse_url($_GET['destination']);
          drupal_goto($from['path']);  
        }
        break;

      case 'logs':
      case 'processes':
        if (($this->user!=$owner) && (!user_access('manage containers')  )) {
          $this->message("Permission denied, $this->user is not the owner ($owner) or admin", 'error');
          break;
        }
      case 'containers':
        $result=$this->contAction();
        break;

      case 'proxy2restart':
      case 'proxyrestart':
        // The reverse proxy  had an issue
        //$this->id=$this->rproxy;
        if ($action == 'proxy2restart') { 
          $this->id = 'nginx-gen' ;
        } else {
          $this->id = 'nginx';
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


      case 'proxylogs':
      case 'proxy2logs':
        if ($action == 'proxy2logs') {
          $this->id = 'nginx-gen';
        } else {
          $this->id = 'nginx';
        }
        $container = $manager->find($this->id);
        if (! $container) {
          $this->message("$this->id does not exist");
          break;
        }
        else {
          #$logs=$manager->logs($container, false, true, true, false, $this->loglines);
          $logs=$manager->logs($container, false, true, true, false, 1000);
          $this->markup = '<pre>Logs for ' . $this->id. ' (1000 lines)<br>';
          foreach ($logs as $log) {
            $this->markup .= $log['output'];
          }
          $this->markup .= '</pre>';
        }
        break;


      case 'advanced':  // just drop through to menu below
        break;

      case 'imres':     // restore an image to the current container
      case 'imdel':     // delete a named image
        $this->client->setDefaultOption('timeout', 25);
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
        $this->client->setDefaultOption('timeout', 30);
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
        $this->client->setDefaultOption('timeout', 25);
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
        $this->client->setDefaultOption('timeout', 30);
        if (($this->user!=$owner) && (!user_access('manage containers')  )) {
          $this->message("Permission denied, $this->user is not the owner ($owner) or admin", 'error');
          break;
        }
        $container = $manager->find($this->id);
        $config = array('tag' => date('Ymd'), 'repo' => $this->id, 'author' => $this->user,
          'comment' => "saved manually on $base_root ",
        );
        $savedimage = $this->docker->commit($container, $config);
        $this->message("saved $this->id to image " . $savedimage->__toString() );
        break;


      case 'cocmd':  
        $this->client->setDefaultOption('timeout', 30);
        $this->markup = '<div class="container-fluid">';
        $html = <<<END
<!-- Bootstrap: -->
<form >
<fieldset>
<legend>Run a command inside the container</legend>
<!-- Button -->
<div class="col-xs-2">
  <div class="control-group">
    <div class="controls">
      <button id="submit" name="submit" class="btn btn-default">Execute</button>
    </div>
  </div>
</div>
<!-- Text input-->
<div class="col-xs-10">
  <div class="control-group">
    <div class="controls">
      <input id="textinput-0" name="cmd" type="text" placeholder="" class="input-xxlarge">
      <p class="help-block">e.g. A non-blocking command such as: /bin/date or 'cd /var/www/html; ls' or 'tail 100 /var/log/apache2/*error*log' or 'cd /var/www/html; drush watchdog-show'</p>
    </div>
  </div>
</div>

</fieldset>
</form>
END;
        $this->markup .= $html;
        $_url = drupal_parse_url($_SERVER['REQUEST_URI']);
        if (isset ($_url['query']['cmd']) ) {
          $cmd = $_url['query']['cmd'];   // todo: security checking?
          $result = $this->runCommand($cmd);
          $this->markup .= "<pre>Output of the command '$cmd':<br>" . $result . '</pre>';
/*
          $container = $manager->find($this->id);
          if ($container->getRuntimeInformations()['State']['Running'] == FALSE) {
            $this->message("$this->id must be started", 'warning');
            break;
          }
          // todo: if cmd had several parts, create an array?
          #$cmds = preg_split ('/[\s]+/', $cmd);
          #$execid = $manager->exec($container, $cmds);

          $execid = $manager->exec($container, ['/bin/bash', '-c', $cmd]);
          #dpm("Exec ID= <" . $execid. ">\n");
          $result = $manager->execstart($execid);
          $this->markup .= "<pre>Output of the command '$cmd':<br>" . $result->__toString() . '</pre>';
*/
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
    $statushtml='<div class=website-status>';
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
      $this->getGit();    // grab git status
      #if (strlen($this->actual_git)>0) {
        $description.= "<div class=col-xs-2><abbr title='If /var/www/html/webfact_status.sh exists it is run and the output show here. It could be the last git commit for example.'>App status</abbr>:</div> <div class=col-xs-10>$this->actual_git</div>";
      #}
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
    if (!empty($this->markup) ) {
      $render_array['webfact_arguments'][3] = array(
        '#type' => 'markup',
        '#markup' => '<h3> </h3>' . $this->markup,
      );
    }

    return $render_array;
  }
}      // class

/* see logtail above, todo: delete if unneeded
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
} */



