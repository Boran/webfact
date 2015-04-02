<?php

/**
 * @file
 * batch operations for webfact
 *
 * Batchapi doc:
 * https://api.drupal.org/api/drupal/includes%21form.inc/group/batch/7
 * https://www.drupal.org/node/180528
 * http://drupal.stackexchange.com/questions/50996/how-does-the-batch-api-work-internally
 */

/*
 * operations
 */
function batchSaveCont($nid, $title, &$context) {
  $context['finished'] = 0;
  watchdog('webfact', "batchSaveCont $nid, $title");
  $w= new WebfactController;
  $w->arguments('backup', $nid, 0);  // verbose=0
  $context['message'] = "saved image of $title";
  $context['results']['log'][] = 'saved';
  $context['finished'] = 1;
}

function batchRemoveCont($nid, $title, $log=1, &$context) {
  $context['finished'] = 0;
  watchdog('webfact', "batchRemoveCont $nid, $title");
  $w= new WebfactController;
  $w->deleteContainer($title);
  if ($log==1) {
    $context['results']['log'][] = 'deleted ' . check_plain($title);
  }
  $context['message'] = 'deleted ' . check_plain($title);
  $context['finished'] = 1;
}

/* delete node */
function batchRemoveNode($nid, $title, $log=1, &$context) {
  $context['finished'] = 0;
  watchdog('webfact', "batchRemoveNode $nid, $title");
  node_delete($nid);   // this will trigger deleteContainer() too
  $context['message'] = "delete meta data for $title";
  if ($log==1) {
    $context['results']['log'][] = "delete meta data for $title, node $nid";
  }
  $context['finished'] = 1;
}


function batchStopCont($nid, $title, &$context) {
  $context['finished'] = 0;
  watchdog('webfact', "batchStopCont nid=$nid, $title");
  $w= new WebfactController;
  $w->stopContainer($title);
  $context['message'] = "stop $title";
  $context['results']['log'][] = 'stop';
  $context['finished'] = 1;
}

function batchRestartCont($nid, $title, &$context) {
  $context['finished'] = 0;
  watchdog('webfact', "batchRestartCont nid=$nid, $title");
  $w= new WebfactController;
  $w->arguments('restart', $nid, 0);  // verbose=0
  $context['message'] = "restart $title";
  $context['results']['log'][] = 'restart';
  $context['finished'] = 1;
}

function batchCreateCont($nid, $title, &$context) {
  $context['finished'] = 0;
  #watchdog('webfact', "batchCreateCont nid=$nid, $title");
  $w= new WebfactController;
  $w->arguments('create', $nid, 0);  // verbose=0
  $context['message'] = "create $title";
  $context['results']['log'][] = 'created ' . $title;
  $context['sandbox']['progress'] = 30;
  $context['finished'] = 1;
}

function batchRenameCont($oldname, $newname, &$context) {
  $context['finished'] = 0;
  #watchdog('webfact', "batchRenameCont $oldname to $newname");
  $w= new WebfactController;
  $w->renameContainer($oldname, $newname);
  $context['message'] = "batchRenameCont $oldname to $newname";
  $context['results']['log'][] = "$oldname to $newname";
  $context['finished'] = 1;
}

function batchCommandCont($cmd, $id, &$context) {
  $context['finished'] = 0;
  watchdog('webfact', "batchCommandCont " . check_plain($cmd));
  $w= new WebfactController;
  $log = $w->runCommand($cmd, $id);
  $context['message'] = "Run command on $id: " . check_plain($cmd);
  $context['results']['log'][] = $log;
  $context['finished'] = 1;
}


function batchTrack($nid, $title, $timer, &$context) {
  $context['finished'] = 0;
  $w= new WebfactController(0, $nid);
  $result = $w->getContainerBuildStatus($nid);
  watchdog('webfact', "batchTrack nid=$nid, $title, build status=$result");
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

function batchWaitInstalled($nid, $title, &$context) {
  $timer=10;
  $context['finished'] = 0;
  $w= new WebfactController(0, $nid);
  for ($i = 0; $i < 10; $i++) {   // max 100 secs, to avoid browser timeout
    $result = $w->getContainerBuildStatus($nid);
    watchdog('webfact', "batchWaitInstalled $i nid=$nid, $title, build status=$result");
    if ( ($result==100) || ($result==200) ) {
      // done, jump back asap
      break;
    }
    $context['results']['buildstatus'] = $result;
    if ($result<100) {   
      $context['results']['log'][] = "building: installing Drupal ($result)";
      $context['message'] = "waiting for Drupal installation ($result)";
    } else {  // post install, e.g. puppet
      $context['results']['log'][] = "building: tuning container ($result)";
      $context['message'] = "waiting for container tuning ($result)";
    }
    sleep($timer);           // wait
  }
  $context['results']['log'][] = "built $result";
  $context['finished'] = 1;
}


function batchUpdateCont($nid, $title, &$context) {
  $context['finished'] = 0;
  #$cmd='ps';
  $cmd = "cd /var/www/html && ./webfact_update.sh |tee -a /tmp/webfact.log "; // todo: parameter
  $w= new WebfactController(0, $nid);
  $logs = $w->runCommand($cmd);
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
  if (isset($results['log'])) {
    $_SESSION['batch_results'] = $results['log'];
  }
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

function batchDone($success, $results, $operations) {
  if ($success) {
    $message = t('Done.');
  }
  else {
    $message = t('The operation had issues.');
  }
  drupal_set_message($message);
  if (isset($results['log'])) {
    $_SESSION['batch_results'] = $results['log'];
  }
}

