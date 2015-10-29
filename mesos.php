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
      $this->client = new GuzzleHttp\Client();
      $this->mserver = variable_get('webfact_mserver', '');  // marathon api
      // exception if empty

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
    }


    public function deleteApp() {
      $url = $this->mserver . 'v2/apps/' . $this->marathon_name;
      try {
        $res = $this->client->delete($url, [ 'auth' => ['user', 'pass'] , 'proxy' => '']);
        dpm( var_export($res->json(), true) );
        return $res->json();
      } catch (RequestException $e) {
          #echo $e->getRequest();
          if ($e->hasResponse()) {
              dpm( $e->getResponse()->getStatusCode()
                . ' ' . $e->getResponse()->getReasonPhrase() 
                . ': ' . $e->getResponse()->json()['message']  );
          } 
      } 
    }

    public function createApp() {
      $url = $this->mserver . 'v2/apps';
      $data = array(
        'id' => '/drupal1',
        'cmd' => '/start.sh',
        'cpus' => 0.5,
        'mem' => 256.0,
        'container' => [ 'type' => 'DOCKER',
          'docker' => [ 'image' => 'boran/drupal',
            'network' => 'BRIDGE',
            'portmappings' => [ 'containerPort' =>80, 'hostPort'=>0 ]
          ]
        ]
      );
      $data = json_encode($data);
      try {
        $res = $this->client->post($url, [ 'auth' => ['user', 'pass'] , 'proxy' => '', 'body' => $data ]);
        dpm( var_export($res->json(), true) );
        return $res->json();
      } catch (RequestException $e) {
          #echo $e->getRequest();
          if ($e->hasResponse()) {
              dpm( $e->getResponse()->getStatusCode()
                . ' ' . $e->getResponse()->getReasonPhrase() 
                . ': ' . $e->getResponse()->json()['message']  );
              #dpm( var_export( $e->getResponse(), true) );
              #dpm(  $e->__toString() );
              #dpm(  $e->getResponse()->getReasonPhrase() );
              #dpm(  $e->getResponse()->json()['message'] );
              #dpm(  $e->getResponse()->getBody() );
              #dpm( var_export( $e->getResponse()->getBody(), true) );
              #echo $e->getResponse();
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
        $runstatus = $res->json()['app']['tasks'][0]['startedAt'];
      }
      else if ($res->getStatusCode()==404) {
        $runstatus = 'mesos: not found';
      }
      return $runstatus;
    }

}


