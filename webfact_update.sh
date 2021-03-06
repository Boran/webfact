#/bin/sh
# webfact_update.sh  : Automated procedure to update the website , managed by the Dokcer API
#
#
# This is an example than can be activated by
# ln -s /var/www/html/sites/all/modules/custom/webfact/webfact_update.sh /var/www/html/webfact_update.sh
# or just copying into /var/www/html. Make sure it is executable.
#
# This example updated /var/www/html and all webfact modules from git.
# Note: it cannot be used for Mesos.

echo "___________ `date '+%Y-%m-%d %H:%M'` _________________";
echo "Running webfact_update.sh: updating elements of a Webfactory UI";

# load proxy settings, if any
if [ -f '/etc/profile.d/proxy.sh' ] ; then
  . /etc/profile.d/proxy.sh
fi

cd /var/www/html
if [ -d '.git' ] ; then
  if [ -x /root/backup.sh ] ; then
    echo "-- backup: running /root/backup.sh /data"
  fi;

  echo "-- git pull for /var/www/html"
  git pull
  # todo: if credential are needed, somehow add them to /root/.netrc

  #more precise control later
  #echo "-- git submodule update "
  #git submodule update
  git submodule init profiles/webfactp
  git submodule update profiles/webfactp

  echo "--"
  echo "-- pull latest master for webfact_content_type, webfact, webfactapi"
  cd /var/www/html/sites/all/modules/custom/webfact_content_types && git checkout master >/dev/null 2>&1 && git pull
  cd /var/www/html/sites/all/modules/custom/webfact && git checkout master >/dev/null 2>&1 && git pull
  cd /var/www/html/sites/all/modules/custom/webfactapi && git checkout master >/dev/null 2>&1 && git pull


  echo " "
  echo "-- last two git logs /var/www"
  git log -2 --pretty=email
else
  echo "-- no git repo in /var/www/html";
  echo "-- git pull for webfact components: module, theme, webfact_content_types, webfactapi:"
  (cd sites/all/themes/custom/webfact_theme  && git pull origin master)
  (cd sites/all/modules/custom/webfact       && git pull origin master)
  (cd sites/all/modules/custom/webfactapi    && git pull origin master)
  (cd sites/all/modules/custom/webfact_content_types/ && git pull origin master)
fi

echo "Note: if webfact_content_types changed, please re-apply the feature manually "


# finally:
echo " "
echo "-- Drush update, clear caches"
drush -y updatedb
drush cache-clear all
echo "-- `date '+%Y-%m-%d %H:%M'` webfact_update.sh done --"

