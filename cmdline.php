#!/usr/bin/php
<?php

/*
 * example usage of the docker api from the command line
 *
 * used for testing docker-php and webfactory 
 */

# normal drupal way
#require_once '/var/www/html/sites/all/libraries/composer/autoload.php';
# local composer: only way to load logging class, why?
chdir(dirname(__DIR__));
require_once 'vendor/autoload.php';

require '/var/www/html/sites/all/libraries/composer/stage1/docker-php/src/Docker/Manager/ContainerManager.php';

/*
#require_once "docker-php/src/Docker/Docker.php";
#require "docker-php/src/Docker/Http/DockerClient.php";
require_once 'vendor/autoload.php';
require "docker-php/src/Docker/Http/Adapter/DockerAdapter.php";
require "docker-php/src/Docker/Manager/ContainerManager.php";
require "docker-php/src/Docker/Manager/ImageManager.php";
require "docker-php/src/Docker/Docker.php";
*/

#putenv("DOCKER_HOST=unix:///var/run/docker.sock");
#$client = new Docker\Http\DockerClient(array(), 'tcp://dock2.vptt.ch:2375');
$client = new Docker\Http\DockerClient(array(), 'unix:///var/run/docker.sock');
#$client = new Docker\Http\DockerClient(array(), 'unix:////tmp/socat.sock');
##socat -t100 -v UNIX-LISTEN:/tmp/socat.sock,mode=777,reuseaddr,fork UNIX-CONNECT:/var/run/docker.sock &
$docker = new Docker\Docker($client);
$manager = $docker->getContainerManager();
$imagemgr = $docker->getImageManager();


# todo: events gives nothing back
#$response = $client->get(["/events?since=2014-12-23",[]]);
#$response = $client->get(["/events",[]]);
#print("response code= " . $response->getStatusCode() . "\n");
#print_r($response->json());

# basic docker daemon infos
#$version = $docker->getVersion();
#print_r($version);
#$version = $docker->getInfo();
#print_r($version);


# findAll
#$containers = $manager->findAll();
#foreach ($containers as $container) {
#  $manager->inspect($container);
#  print($container->getID() . " " . $container->getName() . "\n");
#}


/*
# Example: get container data
#$container = $manager->find('d900c975316e');
$container = $manager->find('vanilla1');
if ($container) {
  #print_r($container);
  #TODO: $manager->pause($container);
  #$manager->stop($container);
  print_r($container->getName());
  #print_r($container->getRuntimeInformations());
  print_r($container->getRuntimeInformations()['State']);
  #print_r($container->getRuntimeInformations()['State']['Running']);
  #print_r($container->getData());
  #print_r($container->getConfig());
  #print_r($container->getEnv());
  #print_r($container->getExitCode());
  #print_r($container->getMappedPorts());
  print("\n");
}*/

# Create + start a container
$container = $manager->find('vanilla2');
/*
  print("vanilla2: find, stop,");
  $response=$manager->stop($container)->remove($container);
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
  // create and continue
  #$manager->create($container)->start($container, $config);

  // interactive: follow logs
  $manager->run($container, function($output, $type) {
    fputs($type === 1 ? STDOUT : STDERR, $output);
  }, $config);
  print("\n");
  #print_r("Running=" . $container->getRuntimeInformations()['State']['Running']. "\n");
*/


/*
 *  logs, attaching
 */
  //$logs=$manager->logs($container, false, true, true, false, 20);
  //print_r($logs);

  # method 1:
  #$manager->attach($container, function ($output, $type) {print($output);} , true);
  #$str=$manager->attach($container, function ($output, $type) {print($output);} , true)->getBody()->__toString();

  # method 2:
  #$manager->attach($container, function ($output, $type) {print($output);} , true)->getBody()->getContents();

  # method 3:
  #$response=$manager->attach($container, function ($output, $type) {print($output);} , true);
  #$response->getBody()->getContents();

/*
$container = $container= new Docker\Container();
$container->setName('test1');
$container->setImage('ubuntu:precise');
$container->setMemory(1024*1024*128);
$container->setEnv(['SYMFONY_ENV=prod', 'FOO=bar']);
$container->setCmd(['/bin/echo', 'Hello Docker!']);
$manager->run($container);
*/


/*
 * inspect images
 */
/*
$lookfor="boran/drupal";
echo "inspect image $lookfor: \n";
try {
  $image = $imagemgr->find($lookfor);
  $im=$imagemgr->inspect($image);
  print_r($im['Comment'] . "\n");    # commit message
  print_r($im['Created'] . "\n");
  print_r($im['Author'] . "\n");
  print_r("name: " . $image->__toString() . "\n");
} catch (Exception $e) {
  echo $e->getMessage();
}
*/


/*
 * commit a container to an image
 */
/*
echo "commit a container vanilla2\n";
$container = $manager->find('vanilla2');
$config=array(
  'tag' => 'mytag',
  'repo' => 'vanilla2', # name=vanilla2:mytag
  'comment' => 'test commit',
  'author' => 'Sean ',
);
$savedimage = $docker->commit($container, $config);
echo "details of saved image:\n";
print_r('__toString=' . $savedimage->__toString() . "\n");
print_r('getId=' . $savedimage->getId() . "\n");
print_r('getTag=' . $savedimage->getTag() . "\n");
print_r('getRepository=' . $savedimage->getRepository() . "\n");

echo "inspect comment, date, author from image: \n";
$foo = $imagemgr->inspect($savedimage);
#print_r($foo);
print_r($foo['Comment'] . "\n");    # commit message
print_r($foo['Created'] . "\n"); 
print_r($foo['Author'] . "\n"); 
*/


/*
 * searching for local images
 */
/*
echo "findAll images, list name and Created:\n";
$images = $imagemgr->findAll();
foreach ($images as $image) {
  #print($image->__toString() . "  " . $image->getID() . "\n");
  $data=$imagemgr->inspect($image);
  print($image->__toString() . " " . $data['Created'] . " " . $image->getID() . "\n");
}

echo "find images containing vanilla2:\n";
$images = $imagemgr->findAll();
foreach ($images as $image) {
  $matches=array();
  #if (preg_match('/^vanilla2/', $image->__toString(), $matches)) {
  #if (preg_match('/^vanilla2$/', $image->getRepository(), $matches)) {
  # more exact:
  if (! strcmp('vanilla2', $image->getRepository())) {
    #print($matches[0] . "\n");
    $data=$imagemgr->inspect($image);
    print($image->__toString() . " " . $data['Created'] . " " . $image->getID() . "\n");
  }
}
*/

#echo date('Ymd');
#$d="2015-01-21T12:42:36.662998775Z";
#preg_match('/(.+:.+):.+/', $d, $matches);
#$map= array('T' => ' ');
#print_r( strtr($matches[1], $map) );

/*
 * pulling latest image for a container
 */
/*$image=$imagemgr->pull('ubuntu', 'latest', 
  #function ($output, $type) {print_($output);} 
  function ($output, $type) {print($output['status']. "\n"  );} 
  );*/
/*
$pull_callback=function ($output, $type) {
   if (array_key_exists('status', $output)) {
     print($output['status'] . "\n");
   } else if (array_key_exists('error', $output)) {
     print($output['error'] . "\n");
   }
   #todo else 
 };

$lookfor='vanilla2';
$container = $manager->find($lookfor);
if ($container) {
  # find() does inspect() which gets ['Config'] infos
  $cont=$container->getRuntimeInformations();
  $str=$cont['Config']['Image'];
  print_r($str);
  if (preg_match('/(.*):(.*)/', $str, $matches)) {
    print_r($matches);
    #$image=$imagemgr->find($matches[1], $matches[2]);
    $image=$imagemgr->pull($matches[1], $matches[2], $pull_callback);
  }
}
*/

/* interact() : does not yet work
 *
$lookfor='vanilla2';
$container = $manager->find($lookfor);
$stream = $manager->interact($container);
$stream->write("apt-get update \n");
#$stream->write("ls /var/www \n");
echo $stream->receive()['data'];
 */


/*
 * exec:
 * running Ubuntu container named "vanilla2", run the "date" command 
curl -X POST -H "Content-Type: application/json" http://127.0.0.1:2375/containers/vanilla2/exec -d '{ "AttachStdin":false,"AttachStdout":true,"AttachStderr":true, "Tty":false, "Cmd":["/bin/date"] }'

curl -X POST -H "Content-Type: application/json" http://127.0.0.1:2375/containers/vanilla2/exec -d '{ "AttachStdin":false,"AttachStdout":true,"AttachStderr":true, "Tty":false, "Cmd":["/bin/bash", "-c", "ls /var/www/html"]

{"Id":"213f4f0abd592b302a6af43637f4422f6387b63a9759643b3e6024655099b298"}

curl -X POST -H "Content-Type: application/json" http://127.0.0.1:2375/exec/213f4f0abd592b302a6af43637f4422f6387b63a9759643b3e6024655099b298/start -d '{ "Detach":false,"Tty":false}'

 */
/*
try {
  $lookfor='vanilla2';
  $container = $manager->find($lookfor);
  #$execid = $manager->exec($container, ['/bin/date']);
  $execid = $manager->exec($container, ["/bin/bash", "-c", "ls /var/www/html"]);
  print_r("Exec ID= <" . $execid. ">\n");
  $result=$manager->execstart($execid);
  print_r("Result= <" . $result->__toString() . ">\n");

} catch (Exception $e) {
  echo $e->getMessage();
}
*/


/*
 * upload the file /tmp/foo.txt to /tmp/dest.foo.txt in the conatiner
 cat /tmp/foo.txt | docker exec -i vanilla2 sh -c 'cat >/tmp/dest.foo.txt'
 docker exec -i vanilla2 sh -c 'cat /tmp/dest.foo.txt'
        #$execid = $manager->exec($container, ["/bin/bash", "-c", "cat >/tmp/upload.file"]);
        #$result=$manager->execstart($execid);
*/
try {
  $resource="/tmp/foo.txt";
  $dest="/tmp/upload.file";
  $lookfor='vanilla2';

  system("echo bar > $resource");
  $container = $manager->find($lookfor);
  $execid = $manager->exec($container, ["/bin/bash", "-c", "cat >$dest"], true); // connect stdin
  print_r($execid . "\n");
  ## TODO: how to stream the file contents in?
  #$result=$manager->execstart($execid);
  print_r("Result= <" . $result->__toString() . ">\n");
} catch (Exception $e) {
  echo $e->getMessage();
}




#print_r("\n");


/*
 * file copy (download)
 * API: 
   curl -X POST -H "Content-Type: application/json" http://127.0.0.1:2375/containers/vanilla2/copy -d '{ "Resource": "/etc/hostname" }' >/tmp/1
 */
/*
$lookfor='vanilla2';
$container = $manager->find($lookfor);
#$resource='/etc/hosts';    # why is file empty
$resource='/etc/default';
$tarFileName  = "/tmp/resource.tar";

#$manager->copyToDisk($container, $resource, $tarFileName);
$exportStream = $manager->copy($container, $resource);
  $tarFile      = fopen($tarFileName, 'w+');
  stream_copy_to_stream($exportStream->detach(), $tarFile);
  fclose($tarFile);
echo "$tarFileName written\n";
system("file $tarFileName");
*/

/*
 * dump container to a tar file.
 * usage: php cmdline.php > /tmp/1.tar
$lookfor='vanilla2';
$container = $manager->find($lookfor);
$exportStream = $manager->export($container);
  $tarFileName  = sys_get_temp_dir() . "/$lookfor-container.tar";
  $tarFile      = fopen($tarFileName, 'w+');
  stream_copy_to_stream($exportStream->detach(), $tarFile);
  fclose($tarFile);
echo "$tarFileName written\n";
*/


?>
