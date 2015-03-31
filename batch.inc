<?php

/**
 * @file
 * batch operations for webfact
 *
 * Batchapi doc:
 * https://api.drupal.org/api/drupal/includes%21form.inc/group/batch/7
 * https://www.drupal.org/node/180528
 */

/*
 * operations
 */
function batchSaveCont($nid, $title, &$context) {
  $context['finished'] = 0;
  $w= new WebfactController;
  $w->arguments('backup', $nid, 0);  // verbose=0
  $context['message'] = "saved image of $title";
  $context['results']['log'][] = 'saved';
  #$context['sandbox']['progress'] = 10;
  $context['finished'] = 1;
}

function batchRemoveCont($nid, $title, &$context) {
  $context['finished'] = 0;
  $w= new WebfactController;
  $w->arguments('delete', $nid, 0);  // verbose=0
  $context['message'] = "delete $title";
  $context['results']['log'][] = 'deleted';
  #$context['sandbox']['progress'] = 20;
  $context['finished'] = 1;
}

function batchRestartCont($nid, $title, &$context) {
  $context['finished'] = 0;
  $w= new WebfactController;
  $w->arguments('restart', $nid, 0);  // verbose=0
  $context['message'] = "restart $title";
  $context['results']['log'][] = 'restart';
  #$context['sandbox']['progress'] = 20;
  $context['finished'] = 1;
}


function batchCreateCont($nid, $title, &$context) {
  $context['finished'] = 0;
  $w= new WebfactController;
  $w->arguments('create', $nid, 0);  // verbose=0
  $context['message'] = "create $title";
  $context['results']['log'][] = 'created';
  $context['sandbox']['progress'] = 30;
  $context['finished'] = 1;
}

function batchTrack($nid, $title, $timer, &$context) {
  $context['finished'] = 0;
  $w= new WebfactController(0, $nid);
  $result = $w->getContainerBuildStatus($nid);
  if ( ($result==100) || ($result==200) ) {
    // already done, jump back asap
    $context['results']['buildstatus'] = $result;
  } else {
    sleep($timer);
    $result = $w->getContainerBuildStatus($nid);
    $context['message'] = "building " . $result/2;
    $context['results']['log'][] = "building $result";
    $context['results']['buildstatus'] = $result;
  }
  $context['sandbox']['build'] = $result;
  $context['finished'] = 1;
}

function batchUpdateCont($nid, $title, &$context) {
  $context['finished'] = 0;
  #$cmd='ps';
  $cmd = "cd /var/www/html && ./webfact_update.sh |tee -a /tmp/webfact.log "; // todo: parameter
  $w= new WebfactController(0, $nid);
  $logs = $w->runCommand($cmd);
  #$w->arguments('create', $nid, 0);  // verbose=0
  $context['message'] = "update $title, running ${cmd}";
  $context['results']['log'][] = $logs;
  $context['results']['log'][] = "<br/>See also /tmp/webfact_update.log inside the container";
  $context['sandbox']['progress'] = 50;
  $context['finished'] = 1;
}


/*
 * status results
 */
function batchRebuildDone($success, $results, $operations) {
  if ($success) {
    if ($results['buildstatus']==100 || $results['buildstatus']==200) {
      $message = t('Container created, you can visit the new website');
    } else {
      $message = t('Container created, installation is going on in the background. Follow the Build Status below to completion, or visit the logs page for details.');
    }
  }
  else {
    $message = t('Rebuild had issues.');
  }
  drupal_set_message($message);
  // Provide data for the redirected page via $_SESSION.
  $_SESSION['batch_results'] = $results['log'];
}

function batchUpdateDone($success, $results, $operations) {
  if ($success) {
    $message = t('Update done, see the logs below for details.');
  }
  else {
    $message = t('Update had issues.');
  }
  drupal_set_message($message);
  // Provide data for the redirected page via $_SESSION.
  if (isset($results['log'])) {
    $_SESSION['batch_results'] = $results['log'];
  }
}


