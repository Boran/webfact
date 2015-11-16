Introduction
------------
Containers can also managed on a Mesos cluster via Marathon and bamboo (experimental).
It is asssumed that a Mesos cluster is available with a marathon + bamboo API along with a mysql DB (cluster).
The Drupal containers store their data in the DB and a volume which is visible in the same mount point on all mesos slaves (e.g. via glusterfs).

Installation of webfact
-----------------------

Use webfact-make to install a Webfactory container, indication WEBFACT_API=1 to indicate mesos.

TDOD: more to come..
