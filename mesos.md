Introduction
------------
Containers can also managed on a Mesos cluster via Marathon and bamboo (experimental).
It is asssumed that a Mesos cluster is available with a marathon + bamboo API along with a mysql DB (cluster).

The Drupal containers store their data in the DB and a volume which is visible in the same mount point on all mesos slaves (e.g. via glusterfs).

Test environment: Ubuntu 14.04 with 3 masters and 3 slaves, Mesos 0.25.0, Marathon 0.11.1.


Installation of webfact
-----------------------

Use webfact-make to install a Webfactory container, indication WEBFACT_API=1 to indicate mesos.

On the DB, create a database for webfact, and grant a user full rights to it (see the MYSQL_* environemnt below).

In Marathon create an app which uses boran/drupl, maps volume mounts and expets the site to sun on port 443 e.g
```
{
  "type": "DOCKER",
  "volumes": [
    {
      "containerPath": "/data",
      "hostPath": "/opt/sites/webfact/data",
      "mode": "RW"
    },
    {
      "containerPath": "/var/www/html",
      "hostPath": "/opt/sites/webfact/www",
      "mode": "RW"
    },
    {
      "containerPath": "/opt/sites",
      "hostPath": "/opt/sites",
      "mode": "RO"
    }
  ],
  "docker": {
    "image": "boran/drupal",
    "network": "BRIDGE",
    "portMappings": [
      {
        "containerPort": 443,
        "hostPort": 0,
        "servicePort": 0,
        "protocol": "tcp"
      }
    ],
    "privileged": false,
    "parameters": [],
    "forcePullImage": false
  }
}
```
Environment
```
WEBFACT_API=1
http_proxy=http://IfYouNeedIt.example.ch:80
https_proxy=http://IfYouNeedIt.example.ch:80
DRUPAL_INSTALL_PROFILE=webfactp
DRUPAL_MAKE_REPO=https://github.com/Boran/webfact-make
DRUPAL_ADMIN=admin
DRUPAL_MAKE_DIR=webfact-make
DRUPAL_ADMIN_EMAIL=your@example.ch
DRUPAL_FINAL_SCRIPT=/opt/drush-make/webfact-make/scripts/final.sh
DRUPAL_SITE_NAME=WebFactory
DRUPAL_SITE_EMAIL=your@example.ch
DRUPAL_SSL=true
MYSQL_HOST=YOUR_DB.example.ch
MYSQL_USER=u_webfact
MYSQL_PASSWORD=Asecret$
MYSQL_DATABASE=webfact
```
Command had to be set to "/start.sh" too.
Set Instances=1 and deploy.

One the conatiner is running (checkout stdout of the task), vsiit the Webfact URL and set the ServerAPI settings for Mesos under /admin/config/development/webfact.


TODO: more complete doc..
