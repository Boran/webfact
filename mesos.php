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


