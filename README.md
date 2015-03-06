The Webfactory provides a UI to interface to the Docker API, allowing operations on containers/images. It aims to streamline dev and operations for Drupal websites. See also https://github.com/Boran/webfact

The Webfactory consists of several modules: webfact (main logic), webfact_content_types (features/views), webfact_theme (bootstrap UI), webfact-make (build/install), webfactapi (optional remote control) and webfactory (deprecated).

You need:
* Docker server (e.g. Ubuntu 14.04) with docker 1.5 or later
* Container for the webfactory (e.g. the drupal lamp "boran/drupal" container https://github.com/Boran/docker-drupal)
* Docker-php library 
* Drupal + contrib modules and the bootstrap theme* 
* This module and the modules above
  
Version
-------
This is still in early beta and in a state of flux, but actaully used on several servers. No version tagging yet.

Installation
----------------
See the readme in the https://github.com/Boran/webfact-make repo.


TODO
----
The issue queue in this repo, and the meta list https://github.com/Boran/webfact/issues/2
Lots of docs needed too.

Programming notes
-----------------
See test.php cmdline.php for examples on using the apis and testing individual functions.
* Tested with Docker API v1.15
* The docker-php library (https://github.com/stage1/docker-php) 
* The guzzle http client is used. This makes porting with Drupal8 (if fact the first attempt was on Drupal 8 and then focus was shifted back to D7), hence the dependancy on the composer_manager module


