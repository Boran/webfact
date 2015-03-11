#/bin/sh
# webfact_status.sh  : Send a status summary back to the webfactory UI for this container
#
# This is an example than can be activated by
# ln -s /var/www/html/sites/all/modules/custom/webfact/webfact_status.sh /var/www/html/webfact_status.sh
# or just copying into /var/www/html. Make sur it is executable.

cd /var/www/html
if [ -d '.git' ] ; then
  lastcommit=` git log -n 1 --date=short --abbrev-commit --pretty=oneline 2>&1 `;
  remote=`git remote -v 2>&1|grep fetch|awk '{print $2}' `
  #changes=`git status -s -b 2>&1 `
  #echo "Last commit: '$lastcommit', remote=$remote, changes=$branch";
  echo "Last commit: '$lastcommit', remote=$remote";
else
  echo "no git repo in /var/www/html";
fi

