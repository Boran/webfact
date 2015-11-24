<?php

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;

/**
 * Abstract Marathon and Bamboo API for management
 * of containers with Mesos
 */

class Mesos
{
    private $client, $mserver, $mserver_name, $mesosserver, $bserver;
    private $id;   // webfact id for website
    private $website, $marathon_name;
    private $url_postfix;   // Domain part of URL for reverse proxy
    private $serverdir;
    private $frameworkId;

    public function __construct($nid)
    {
      $this->mserver = variable_get('webfact_mserver', '');  // marathon api
      $this->bserver = variable_get('webfact_bserver', '');  // bamboo api
      $this->client = new GuzzleHttp\Client();
      // todo: create the initial request object with URL/proxy etc.
      // exception if empty

      // who is current leader in the cluster?
      $url = $this->mserver . 'v2/leader';
      $res = $this->client->get($url, [ 'auth' => ['user', 'pass'], 'proxy' => '' ]);
      if ($res->getStatusCode()==200) {
        $this->mserver_name =  $res->json()['leader'];  // update 
        $this->mserver = 'http://' . $res->json()['leader']  . '/';  // update target
      }
      #watchdog('webfact', 'mesos: leader is ' . $this->mserver);
      
      // get the webfact meta data for the website, specifically the container name
      // load website node if needed
      if ($this->website==null) {
        $this->website=node_load($nid);
        #if ($this->website->nid == 0 ) {
        #  return;   // not on a valid node, throw exception?
        #}
      }
      if (empty($this->website->field_hostname['und'][0]['safe_value']) ) {
        return;  // throw exception?
      }
      $this->id = $this->website->field_hostname['und'][0]['safe_value'];

      if (!empty($this->website->field_marathon_name['und'][0]['safe_value']) ) {
        $this->marathon_name = $this->website->field_marathon_name['und'][0]['safe_value'];
      }
      if (strlen($this->marathon_name) < 2) {
        $this->marathon_name = $this->id; // fall back to the hostname
      }
      # else throw exception

      #watchdog('webfact', 'mesos: connect to ' . $this->mserver . ' for ' . $this->marathon_name);

      $this->url_postfix='.' . variable_get('webfact_fserver','mywildcard.example.ch'); 
      $this->serverdir = variable_get('webfact_server_sitesdir_host', '/opt/sites/');
      $this->mesosserver = variable_get('webfact_mesosserver', '');
    }


    /******** bambo ******/
    public function getBambooMaster() {
      return $this->bserver;
    }
    /* 
     * delete bamboo config: i.e. haproxy for mapping urls to app
     */
    public function deleteBamboo($verbose=0) {
      $url = $this->bserver . 'api/services/' . $this->marathon_name;
      try {
        watchdog('webfact', 'deleteBamboo ' . $this->marathon_name);
        $res = $this->client->delete($url, [ 'auth' => ['user', 'pass'] , 'proxy' => '']);
        #if ($verbose > 0) {
          #dpm( var_export($res->json(), true) );
        #}
        return $res->json();
      } catch (RequestException $e) {
        if ($verbose > 0) {
          if ($e->hasResponse()) {
            if ($e->getResponse()->getStatusCode()==404) { // or 409?
              drupal_set_message( 'mesos: ' . $e->getResponse()->json()['message']  );
            } else {
              drupal_set_message( 'deleteBamboo ' . $e->getResponse()->getStatusCode()
                . ' ' . $e->getResponse()->getReasonPhrase()
                . ': ' . $e->getResponse()->json()['message']  );
            }
          }
        }
        throw($e);    // abort  downstream
      }
    }

    /* 
     * update bamboo config: i.e. haproxy for mapping urls to app
     * curl -i -X PUT -d '{"id":"/ExampleAppGroup/app1", "acl":"path_beg -i /group/app-1"}' http://localhost:8000/api/services//ExampleAppGroup/app1
     */
    public function updateBamboo($verbose=0) {
      $url = $this->bserver . 'api/services/' . $this->marathon_name;
      try {
        $data = json_encode(array('id'=>$this->marathon_name, 'acl' =>'hdr(host) -i ' . $this->id . $this->url_postfix));
        watchdog('webfact', 'updateBamboo ' . $this->marathon_name . ' hdr(host) -i ' . $this->id . $this->url_postfix);
        $res = $this->client->put($url, [ 'auth' => ['user', 'pass'] , 'proxy' => '', 'headers' => ['Content-Type' => 'application/json'], 'body' => $data ]);
        if ($verbose > 0) {
          #dpm( var_export($res->json(), true) );
          drupal_set_message('bamboo updated');
        }
        return $res->json();

      } catch (RequestException $e) {
        if ($verbose > 0) {
          #echo $e->getRequest();
          if ($e->hasResponse()) {
            if ($e->getResponse()->getStatusCode()==404) { // or 409?
              drupal_set_message( 'mesos: ' . $e->getResponse()->json()['message']  );
            } else {
              drupal_set_message( 'updateBamboo ' . $e->getResponse()->getStatusCode()
                . ' ' . $e->getResponse()->getReasonPhrase()
                . ': ' . $e->getResponse()->json()['message']  );
              #dpm( var_export( $e->getResponse(), true) );
              #dpm(  $e->__toString() );
            }
          }
        }
        throw($e);    // abort  downstream
      }
    }


    /******** marathon ******/
    public function stopApp() {
      $url = $this->mserver . 'v2/apps/' . $this->marathon_name;
      try {
        $data = json_encode(array('instances'=>0));
        $res = $this->client->put($url, [ 'auth' => ['user', 'pass'] , 'proxy' => '', 'headers' => ['Content-Type' => 'application/json'], 'body' => $data ]);
        #dpm( var_export($res->json(), true) );
        return $res->json();
      } catch (RequestException $e) {
        #echo $e->getRequest();
        if ($e->hasResponse()) {
          if ($e->getResponse()->getStatusCode()==409) { // conflict
            drupal_set_message( $e->getResponse()->json()['message']  );
          } else {
            drupal_set_message( 'stopApp ' . $e->getResponse()->getStatusCode()
              . ', ' . $e->getResponse()->getReasonPhrase()
              . ': ' . $e->getResponse()->json()['message']  );
            #dpm( var_export( $e->getResponse(), true) );
            drupal_set_message(  $e->__toString() );
          }
          throw($e);    // abort  downstream
        }
      }
    }

    public function startApp() {
      $url = $this->mserver . 'v2/apps/' . $this->marathon_name;
      try {
        $data = json_encode(array('instances'=>1));
        $res = $this->client->put($url, [ 'auth' => ['user', 'pass'] , 'proxy' => '', 'headers' => ['Content-Type' => 'application/json'], 'body' => $data ]);
        #dpm( var_export($res->json(), true) );
        return $res->json();
      } catch (RequestException $e) {
        #echo $e->getRequest();
        if ($e->hasResponse()) {
          if ($e->getResponse()->getStatusCode()==409) { // conflict
            drupal_set_message( $e->getResponse()->json()['message']  );
          } else {
            drupal_set_message( 'startApp ' . $e->getResponse()->getStatusCode()
              . ', ' . $e->getResponse()->getReasonPhrase()
              . ': ' . $e->getResponse()->json()['message']  );
            #dpm( var_export( $e->getResponse(), true) );
            drupal_set_message(  $e->__toString() );
          }
          throw($e);    // abort  downstream
        }
      }
    }


    public function restartApp($verbose=0) {
      $url = $this->mserver . 'v2/apps/' . $this->marathon_name . '/restart';
      try {
        #dpm('mesos restartApp ' . $this->marathon_name);
        $res = $this->client->post($url, [ 'auth' => ['user', 'pass'] , 'proxy' => '']);
        #dpm( var_export($res->json(), true) );
        return $res->json();
      } catch (RequestException $e) {
        if ($verbose > 0) {
          #echo $e->getRequest();
          if ($e->hasResponse()) {
            if ($e->getResponse()->getStatusCode()==404) {
              drupal_set_message( 'mesos: ' . $e->getResponse()->json()['message']  );
            } else {
              drupal_set_message( 'restartApp ' . $e->getResponse()->getStatusCode()
                . ' ' . $e->getResponse()->getReasonPhrase()
                . ': ' . $e->getResponse()->json()['message']  );
            }
          }
        }
        throw($e);    // abort  downstream
      }
    }

    public function deleteApp($verbose=0) {
      $url = $this->mserver . 'v2/apps/' . $this->marathon_name;
      try {
        #dpm('mesos deleteApp ' . $this->marathon_name);
        $res = $this->client->delete($url, [ 'auth' => ['user', 'pass'] , 'proxy' => '']);
        #dpm( var_export($res->json(), true) );
        $this->deleteBamboo($verbose);
        return $res->json();

      } catch (RequestException $e) {
        if ($verbose > 0) {
          #echo $e->getRequest();
          if ($e->hasResponse()) {
            if ($e->getResponse()->getStatusCode()==404) {
              drupal_set_message( 'mesos: ' . $e->getResponse()->json()['message']  );
            } else {
              drupal_set_message( 'deleteApp ' . $e->getResponse()->getStatusCode()
                . ' ' . $e->getResponse()->getReasonPhrase() 
                . ': ' . $e->getResponse()->json()['message']  );
            } 
          } 
        } 
        throw($e);    // abort  downstream
      } 
    }

    public function createApp($cont, $verbose=1) {
      $url = $this->mserver . 'v2/apps';
      // todo more generic data array with variables
      $data = array(
        'id' => $this->marathon_name,
        'cmd' => $cont['cmd'],
        'cpus' => 0.5,
        'mem' => isset($cont['mem']) ? $cont['mem'] :  512.0,
        'healthChecks' => [[
            "path" => "/",
            "protocol" => "TCP",
            "gracePeriodSeconds" => 3600,
            "intervalSeconds" => 5,
            "timeoutSeconds" => 10,
            "maxConsecutiveFailures" => 3
        ]],
        'container' => [ 
          'type' => 'DOCKER',
          'volumes' => [
             [
                 'containerPath' => '/data',
                 'hostPath'      => $this->serverdir . "$this->marathon_name/data",
                 'mode'          => 'RW'
             ],
             [
                 'containerPath' => '/var/www/html',
                 'hostPath'      => $this->serverdir . "$this->marathon_name/www",
                 'mode'          => 'RW'
             ]
          ],
          'docker' => [ 
            'image' => $cont['image'],
            'network' => 'BRIDGE',
            'portMappings' => [[
              'containerPort' =>$cont['port'],  
              'hostPort'=>0, 
            ]]
          ],
        ],
        'env' => $cont['env'],
      );
      $data = json_encode($data);

      try {
        #dpm($url); dpm( var_export($data, true) );
        $res = $this->client->post($url, [ 'auth' => ['user', 'pass'] , 'proxy' => '', 'headers' => ['Content-Type' => 'application/json'], 'body' => $data ]);
        watchdog('webfact', 'mesos createApp ' . $this->id);
        $this->updateBamboo($cont['url']);        // update bamboo
        #dpm('Mesos create, answer:');
        #dpm( var_export($res->json(), true) );

        // save the current app name used by marathon
        if (empty($this->website->field_marathon_name['und'][0]['safe_value']) ) {
          $this->website->field_marathon_name['und'][0]['value'] = $this->marathon_name;
          node_save($this->website);     // Save the updated node
          $this->website=node_load($this->website->nid);  // reload cache
          watchdog('webfact', 'mesos save field_marathon_name for ' . $this->id);
        }
        return $res->json();

      } catch (RequestException $e) {
          #echo $e->getRequest();
          if ($e->hasResponse() && ($verbose > 0)) {
            if ($e->getResponse()->getStatusCode()==409) {
              drupal_set_message( $e->getResponse()->json()['message']  );
            } else {
              dupal_set_message( $e->getResponse()->getStatusCode()
                . ', ' . $e->getResponse()->getReasonPhrase() 
                . ': ' . $e->getResponse()->json()['message']  );
              #dpm( var_export( $e->getResponse(), true) );
              #dpm(  $e->__toString() );
              #dpm(  $e->getResponse()->getReasonPhrase() );
              #dpm(  $e->getResponse()->json()['message'] );
              #dpm(  $e->getResponse()->getBody() );
              #dpm( var_export( $e->getResponse()->getBody(), true) );
              #echo $e->getResponse();
            }
            throw($e);    // abort  downstream
          }
      }
    }

    public function getApps() {
      $result = $this->mserver;
      $url = $this->mserver . 'v2/apps';
      $res = $this->client->get($url, [ 'auth' => ['user', 'pass'], 'proxy' => '' ]);
      if ($res->getStatusCode()==200) {
        $result = $res->json();
      }
      return $result;
    }

    public function getTasks() {
      $result = $this->mserver;
      $url = $this->mserver . 'v2/tasks';
      $res = $this->client->get($url, [ 'auth' => ['user', 'pass'], 'proxy' => '' ]);
      if ($res->getStatusCode()==200) {
        $result = $res->json();
      }
      return $result;
    }

    public function getGroups() {
      $result = $this->mserver;
      $url = $this->mserver . 'v2/groups';
      $res = $this->client->get($url, [ 'auth' => ['user', 'pass'], 'proxy' => '' ]);
      if ($res->getStatusCode()==200) {
        $result = $res->json();
      }
      return $result;
    }

    public function getDeployments() {
      $result = $this->mserver;
      $url = $this->mserver . 'v2/deployments';
      $res = $this->client->get($url, [ 'auth' => ['user', 'pass'], 'proxy' => '' ]);
      if ($res->getStatusCode()==200) {
        $result = $res->json();
      }
      return $result;
    }

    public function getLeader() {
      $result = $this->mserver;
      /*$url = $this->mserver . 'v2/leader';
      $res = $this->client->get($url, [ 'auth' => ['user', 'pass'], 'proxy' => '' ]);
      if ($res->getStatusCode()==200) {
        $result = $res->json()['leader'];
      }*/
      return $result;
    }
    public function getMesosMaster() {
      return $this->mesosserver;
    }



    public function getInfo() {
      $result = $this->mserver;
      $this->frameworkId='';
      $url = $this->mserver . 'v2/info';
      $res = $this->client->get($url, [ 'auth' => ['user', 'pass'], 'proxy' => '' ]);
      if ($res->getStatusCode()==200) {
        $result = $res->json();
        $this->frameworkId=$result['frameworkId'];
      }
      return $result;
    }

    /*
     * get the app status and return a big text array (for the webfact inspect page)
     */
    public function getInspect() {
      $result = 'mesos ';
      if ($this->website==null) {
        return 'n/a';
      }
      $url = $this->mserver . 'v2/apps/' . $this->marathon_name;
      $res = $this->client->get($url, [ 'auth' => ['user', 'pass'], 'proxy' => '' ]);
      if ($res->getStatusCode()==200) {
        $result = var_export($res->json(), true);
      }
      return $result;
    }

    /*
     * get a short status for the webfact 'advanced' page
     */
    public function getStatus() {
      $runstatus = 'n/a';

      if ($this->website==null) {
        return 'no website';
      }
      #dpm($this->website);
      $url = $this->mserver . 'v2/apps/' . $this->marathon_name;
      #dpm($url);

      try {
        $res = $this->client->get($url, [ 'auth' => ['user', 'pass'], 'proxy' => '' ]);
        #dpm( var_export($res->json(), true) );

        if ($res->getStatusCode()==200) {
          if (isset($res->json()['app']['tasks'][0]['startedAt'])) {
            $runstatus = $res->json()['app']['tasks'][0]['startedAt'];

          } else if (isset($res->json()['app']['instances'])) {
            if ($res->json()['app']['instances'] == 0 ) { 
              $runstatus = 'suspended';
            } else {
              $runstatus = ' instances ' . $res->json()['app']['instances'];
            }
          } else  {
            dpm($res->json());
            $runstatus = ' n/a';
          }
        }

      } catch (RequestException $e) {
        #echo $e->getRequest();
        if ($e->hasResponse()) {
          if ($e->getResponse()->getStatusCode()==404) { // conflict
            #dpm( $e->getResponse()->json()['message']  );
            $runstatus = 'not found';
            throw($e);    // abort  downstream
          } else {
            dpm( 'getStatus ' . $e->getResponse()->getStatusCode()
              . ', ' . $e->getResponse()->getReasonPhrase()
              . ': ' . $e->getResponse()->json()['message']  );
            #dpm( var_export( $e->getResponse(), true) );
            #dpm(  $e->__toString() );
            throw($e);    // abort  downstream
          }
        }
      } catch (Exception $e) {
        watchdog('webfact', $e->getMessage());
        throw($e);    // abort  downstream
      }

      return $runstatus;
    }

}


