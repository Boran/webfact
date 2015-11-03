<?php

use GuzzleHttp\Client as HttpClient;
use GuzzleHttp\Exception\RequestException;

class Mesos
{
    private $client, $mserver;
    private $id;   // webfact id for website
    private $website, $marathon_name;


    public function __construct($nid)
    {
      $this->mserver = variable_get('webfact_mserver', '');  // marathon api
      $this->client = new GuzzleHttp\Client();
      // todo: create the initial request object with URL/proxy etc.
      // exception if empty

      // who is current leader in the cluster?
      $url = $this->mserver . 'v2/leader';
      $res = $this->client->get($url, [ 'auth' => ['user', 'pass'], 'proxy' => '' ]);
      if ($res->getStatusCode()==200) {
        $this->mserver = 'http://' . $res->json()['leader'] . '/';  // update target
      }
      #watchdog('webfact', 'mesos: leader is ' . $this->mserver);
      
      // get the webfact meta data for the website, specifically the container name
      if ($this->website==null) {
        // get the node and find the container name
        // todo: check nid must be >0?
        $this->website=node_load($nid);
        //return;   // not on a valid node, throw exception?
      }
      if (empty($this->website->field_hostname['und'][0]['safe_value']) ) {
        return;
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
    }


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
            dpm( $e->getResponse()->json()['message']  );
          } else {
            dpm( 'stopApp ' . $e->getResponse()->getStatusCode()
              . ', ' . $e->getResponse()->getReasonPhrase()
              . ': ' . $e->getResponse()->json()['message']  );
            #dpm( var_export( $e->getResponse(), true) );
            dpm(  $e->__toString() );
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

    public function createApp() {
      $url = $this->mserver . 'v2/apps';
      $data = array(
        'id' => $this->marathon_name,
        'cmd' => '/start.sh',
        'cpus' => 0.5,
        'mem' => 256.0,   // todo: parameter
        'container' => [ 
          'type' => 'DOCKER',
          'docker' => [ 
            'image' => 'boran/drupal',   // todo: parameter
            'network' => 'BRIDGE',
            'portMappings' => [[
              'containerPort' =>80,    // todo: parameter
              'hostPort'=>0, 
            ]]
          ]
        ]
      );
      #dpm($data['container']['docker']);
      $data = json_encode($data);
      try {
        #dpm($url);
        #dpm( var_export($data, true) );
        $res = $this->client->post($url, [ 'auth' => ['user', 'pass'] , 'proxy' => '', 'headers' => ['Content-Type' => 'application/json'], 'body' => $data ]);
        #dpm('Mesos create, answer:');
        #dpm( var_export($res->json(), true) );
        return $res->json();
      } catch (RequestException $e) {
          #echo $e->getRequest();
          if ($e->hasResponse()) {
            if ($e->getResponse()->getStatusCode()==409) {
              dpm( $e->getResponse()->json()['message']  );
            } else {
              dpm( $e->getResponse()->getStatusCode()
                . ', ' . $e->getResponse()->getReasonPhrase() 
                . ': ' . $e->getResponse()->json()['message']  );
              #dpm( var_export( $e->getResponse(), true) );
              dpm(  $e->__toString() );
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


    public function getLeader() {
      $result = $this->mserver;
      $url = $this->mserver . 'v2/leader';
      $res = $this->client->get($url, [ 'auth' => ['user', 'pass'], 'proxy' => '' ]);
      if ($res->getStatusCode()==200) {
        $result = $res->json()['leader'];
      }
      return $result;
    }

    public function getVersion() {
      $result = $this->mserver;
      $url = $this->mserver . 'v2/info';
      $res = $this->client->get($url, [ 'auth' => ['user', 'pass'], 'proxy' => '' ]);
      if ($res->getStatusCode()==200) {
        $result = $res->json();
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


