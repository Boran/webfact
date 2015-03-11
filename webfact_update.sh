#/bin/sh
# webfact_update.sh  : Automated procedure to update the website 
#
# This is an example than can be activated by
# ln -s /var/www/html/sites/all/modules/custom/webfact/webfact_update.sh /var/www/html/webfact_update.sh
# or just copying into /var/www/html. Make sure it is executable.
#

echo "Running webfact_update.sh";
cd /var/www/html
if [ -d '.git' ] ; then
  echo "-- todo: dump the db and files to /data (or other external volume)"
  if [ -x /root/backup.sh ] ; then
    echo "-- backup: running /root/backup.sh /data"
  fi;
  #echo "-- todo: dump the db and files to /data (or other external volume)"


  echo "-- git pull"
  git pull
  echo "todo: how to use save/use credientials for git? "

  echo " "
  echo "-- Drush update, clear caches"
  drush updatedb
  drush cache-clear all

  echo " "
  echo "-- last two git logs:"
  git log -2 --pretty=email
else
  echo "no git repo in /var/www/html";
fi

