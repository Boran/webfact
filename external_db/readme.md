External Database
----------------

The aim of the webfactory is quickly build containers to primary run Drupal websites. 
Containers may have the database within the container by default, making containers independant, easy to manage and transport.

However if one adheres to the pattern of one process per container, or looks for better performance, one may wish to concentrate all DBs in a single instance. This makes the website/drupal containers more lightweight (simple) and performance should be better for mysql. DB backups can be easier too.

The aims of this feature is to automatically create an external database and inform the new container of the DB parameters.

If enabled, webfact creates a new DB for each new container in a dedicated DB instance. 


Usage:
 * Create a mysql username and password with rights to create databases in the webfact settings (see example commented in ext_db.sql)
 * Load the stored procedures in external_db/ext_db.sql
 * In the Webfact settings (admin/config/development/webfact), the Database management section: enable the external database and add the mysql user/passwd you used in the previous step.


How it works:
 * When creating a website, a database will be created and the docker enviroment set with MYSQL_USER, MYSQL_PASSWORD, MYSQL_DATABASE and MYSQL_HOST.
 * The boran/drupal image then uses these variables to find the DB when installing drupal.


Notes
 * By convention the database and database username are d_CONTAINERNAME, u_CONTAINERNAME
 * A docker image such as boran/drupal is needed that understands MYSQL_USER MYSQL_PASSWORD MYSQL_DATABASE and MYSQL_HOST

