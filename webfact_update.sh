#/bin/sh
# webfact_update.sh  : Automated procedure to update the website 
#
# This is an example than can be activated by
# ln -s /var/www/html/sites/all/modules/custom/webfact/webfact_update.sh /var/www/html/webfact_update.sh
# or just copying into /var/www/html. Make sure it is executable.
#
# This example updated /var/www/html and all webfact modules from git.

echo "Running webfact_update.sh: updating elements of a Webfactory UI";
cd /var/www/html
if [ -d '.git' ] ; then
  echo "-- todo: dump the db and files to /data (or other external volume)"
  if [ -x /root/backup.sh ] ; then
    echo "-- backup: running /root/backup.sh /data"
  fi;

  echo "-- git pull for /var/www"
  #echo "todo: how to use save/use credientials for git? "
  git pull


  echo " "
  echo "-- last two git logs:"
  git log -2 --pretty=email
else
  echo "no git repo in /var/www/html";
fi


echo "-- git pull for webfact components: module, theme, webfact_content_types, webfactapi:"
(cd sites/all/modules/custom/webfact && git pull)
(cd sites/all/themes/webfact_theme && git pull)
(cd sites/all/modules/custom/webfact_content_types/ && git pull)
(cd sites/all/modules/custom/webfactapi && git pull)


# finally:
echo " "
echo "-- Drush update, clear caches"
drush updatedb
drush cache-clear all
echo "-- webfact_update.sh done --"

