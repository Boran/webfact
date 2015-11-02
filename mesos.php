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

      // get the node and find the container name
      // todo: check nid must be >0?
      $this->website=node_load($nid);
      
      // get the webfact meta dat for the website, specifically the container name
      if ($this->website==null) {
        return;   // not on a valid node, throw exception?
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

      $this->client = new GuzzleHttp\Client();
      // todo: create the initial request object
      // exception if empty
    }


    public function deleteApp() {
      $url = $this->mserver . 'v2/apps/' . $this->id;
      try {
        #dpm('mesos deleteApp ' . $this->id);
        $res = $this->client->delete($url, [ 'auth' => ['user', 'pass'] , 'proxy' => '']);
        #dpm( var_export($res->json(), true) );
        return $res->json();
      } catch (RequestException $e) {
          #echo $e->getRequest();
          if ($e->hasResponse()) {
            if ($e->getResponse()->getStatusCode()==404) {
              dpm( $e->getResponse()->json()['message']  );
            } else {
              dpm( 'deleteApp ' . $e->getResponse()->getStatusCode()
                . ' ' . $e->getResponse()->getReasonPhrase() 
                . ': ' . $e->getResponse()->json()['message']  );
            } 
          } 
          throw($e);    // abort  downstream
      } 
    }

    public function createApp() {
      $url = $this->mserver . 'v2/apps';
      $data = array(
        'id' => $this->id,
        'cmd' => '/start.sh',
        'cpus' => 0.5,
        'mem' => 256.0,
        'container' => [ 
          'type' => 'DOCKER',
          'docker' => [ 
            'image' => 'boran/drupal',
            'network' => 'BRIDGE',
            'XportMappings' => [ 
              'containerPort' =>80, 
              'hostPort'=>0 , 
              'servicePort'=>10005 , 
              'protocol'=>'tcp'
            ]
          ]
        ]
      );
      dpm($data['container']['docker']);
      $data = json_encode($data);
      try {
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
     * get a short status for the webfact 'advnaced' page
     */
    public function getStatus() {
      $runstatus = 'mesos ';

      if ($this->website==null) {
        return 'n/a';
      }
      #dpm($this->website);

      $url = $this->mserver . 'v2/apps/' . $this->marathon_name;
      #dpm($url);
      # todo auth, proxy forced off
      try {
        $res = $this->client->get($url, [ 'auth' => ['user', 'pass'], 'proxy' => '' ]);
      }
      finally {
        # cannot use $res, not valid
        #dpm($res->__toString());
      }

      #dpm($res->__toString());
      if ($res->getStatusCode()==200) {
        #dpm($res->getHeader('content-type'));
        #dpm($res->getBody());
        #dpm($res->json()['app']['tasks'][0]['startedAt']);
        #dpm($res->json());
        #if ($response->getBody()) {
          #dpm($res->json());
        #}
        if (isset($res->json()['app']['tasks'][0]['startedAt'])) {
         $runstatus = $res->json()['app']['tasks'][0]['startedAt'];
        }
        else  {
          $runstatus = ' n/a';
        }
      }
      else if ($res->getStatusCode()==404) {
        $runstatus = 'mesos: not found';
      }
      return $runstatus;
    }

}


