# webfact
Introduction
----------------
The Webfactory provides a UI to interface to the Docker API, allowing operations on containers/images. It is opinionated aiming to streamline dev and operations for Drupal websites.

You need:
- Docker server (e.g. Ubuntu 14.04)
- Container for the webfactory (e.g. the drupal lamp "boran/drupal" container)
- This module and the webfactfeature module
- Some Drupal contrib modules.
- Bootstrap theme and link in the theme function for status fields

Installation
----------------
Grab the latest container image
```
docker pull boran/drupal
```

Create a webfact container which will be visible on port 8000 (adapt name/email below). The boran/drupal container will provide lamp, drupal and tools such as composer.
```
name=webfact.EXAMPLE.CH
email=ME@EXAMPLE.CH
image="boran/drupal"
docker run -td -p 8000:80 -e "VIRTUAL_HOST=$name" -v /opt/sites/webfact:/data -v /var/run/docker.sock:/var/run/docker.sock -e "DRUPAL_SITE_NAME=WebFactory" -e "DRUPAL_ADMIN_EMAIL=$email" -e "DRUPAL_SITE_EMAIL=$email" --restart=always --hostname $name --name webfact $image
```

A basic Drupal website should now be running.

Login and change the admin password.

