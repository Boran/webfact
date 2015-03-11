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
  git pull
  # todo: if credential are needed, somehow add them to /root/.netrc

  echo " "
  echo "-- last two git logs /var/www"
  git log -2 --pretty=email
else
  echo "no git repo in /var/www/html";
fi


echo "-- git pull for webfact components: module, theme, webfact_content_types, webfactapi:"
(cd sites/all/modules/custom/webfact    && git pull origin master)
(cd sites/all/themes/webfact_theme      && git pull origin master)
(cd sites/all/modules/custom/webfact_content_types/ && git pull origin master)
(cd sites/all/modules/custom/webfactapi && git pull origin master)


# finally:
echo " "
echo "-- Drush update, clear caches"
drush updatedb
drush cache-clear all
echo "-- webfact_update.sh done --"

