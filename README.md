webfact: core logic module

The Webfactory provides a UI to interface to the Docker API, allowing operations on containers/
images. It aims to streamline dev and operations for Drupal websites. See also https://github.c
om/Boran/webfact

The Webfactory consists of several modules: webfact (main logic), webfact_content_types (featur
es/views), webfact-make (build/install), webfactapi (optional remote control) and webfactory (d
eprecated: full site install)

You need:
  Docker server (e.g. Ubuntu 14.04) with docker 1.5 or later
  Container for the webfactory (e.g. the drupal lamp "boran/drupal" container)
  This module and the webfactfeature module
  Some Drupal contrib modules.
  Bootstrap theme and link in the theme function for status fields

Installation
----------------
See the readme in the webfact-make repo.

