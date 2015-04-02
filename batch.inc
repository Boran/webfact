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
 * batch commands/operations
 */
function batchSaveCont($nid, $title, &$context) {
  $context['finished'] = 0;
  watchdog('webfact', "batchSaveCont $nid, $title");
  $w= new WebfactController;
  $w->arguments('backup', $nid, 0);  // verbose=0
  $context['message'] = "saved image of " . check_plain($title);
  $context['results']['log'][] =  date("H:i:s") . ' saved';
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
  $context['message'] = date("H:i:s"). ' deleted ' . check_plain($title);
  $context['finished'] = 1;
}

/* delete node */
function batchRemoveNode($nid, $title, $log=1, &$context) {
  $context['finished'] = 0;
  watchdog('webfact', "batchRemoveNode $nid, $title");
  node_delete($nid);   // this will trigger deleteContainer() too
  $context['message'] = "delete meta data for " . check_plain($title);
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
  $context['message'] = "stop " . check_plain($title);
  $context['results']['log'][] = date("H:i:s") . ' stop ' . check_plain($title);
  $context['finished'] = 1;
}

function batchRestartCont($nid, $title, &$context) {
  $context['finished'] = 0;
  watchdog('webfact', "batchRestartCont nid=$nid, $title");
  $w= new WebfactController;
  $w->arguments('restart', $nid, 0);  // verbose=0
  $context['message'] = "restart " . check_plain($title);
  $context['results']['log'][] = 'restart ' . check_plain($title);
  $context['finished'] = 1;
}

function batchCreateCont($nid, $title, &$context) {
  $context['finished'] = 0;
  #watchdog('webfact', "batchCreateCont nid=$nid, $title");
  $w= new WebfactController;
  $w->arguments('create', $nid, 0);  // verbose=0
  $context['message'] = "create " . check_plain($title);
  $context['results']['log'][] = date("H:i:s") . ' created ' . check_plain($title).
  $context['sandbox']['progress'] = 30;
  $context['finished'] = 1;
}

function batchRenameCont($oldname, $newname, &$context) {
  $context['finished'] = 0;
  #watchdog('webfact', "batchRenameCont $oldname to $newname");
  $w= new WebfactController;
  $w->renameContainer($oldname, $newname);
  $context['message'] = "batchRenameCont $oldname to $newname";
  $context['results']['log'][] = date("H:i:s") . "rename $oldname to $newname";
  $context['finished'] = 1;
}

function batchCommandCont($cmd, $id, &$context) {
  $context['finished'] = 0;
  watchdog('webfact', "batchCommandCont " . check_plain($cmd));
  $w= new WebfactController;
  $log = $w->runCommand($cmd, $id);
  $context['message'] = "Run command on $id: " . check_plain($cmd);
  $context['results']['log'][] = date("H:i:s") . ' ' . $log;
  $context['finished'] = 1;
}

function batchUpdateCont($nid, $title, &$context) {
  $context['finished'] = 0;
  $logfile = variable_get('webfact_cont_log', '/tmp/webfact.log');
  $cmd = "cd /var/www/html && ./webfact_update.sh |tee -a $logile";
  $w= new WebfactController(0, $nid);
  $logs = $w->runCommand($cmd);
  $context['message'] = "update $title, running ${cmd}";
  $context['results']['log'][] = $logs;
  $context['results']['log'][] = "<br/>See also $logfile inside the container";
  $context['sandbox']['progress'] = 50;
  $context['finished'] = 1;
}


/*
 * logging: append batch log to container log
 */
function batchContLog($nid, $title, $logfile, &$context) {
  $context['finished'] = 0;
  if (isset($context['results']['log'])) {
    $w= new WebfactController(0, $nid);

    foreach ($context['results']['log'] as $line) {
      $cmd = "echo '${line}' >> $logfile";
      $res = $w->runCommand($cmd);
    }
    $context['message'] = "update $logfile";
    $context['results']['log'][] = date("H:i:s") . " update $logfile";
  } else {
    watchdog('webfact', 'batchContLog: no results log');
  }
  $context['finished'] = 1;
}


/*
 * tracking progress
 */
function batchTrack($nid, $title, $timer, &$context) {
  if ( isset($context['results']['done']) ) {
    watchdog('webfact', "batchTrack ignore since results=done");
    $context['finished'] = 1;
    return;
  }
  $context['finished'] = 0;
  $w= new WebfactController(0, $nid);
  $result = $w->getContainerBuildStatus($nid);
  watchdog('webfact', "batchTrack nid=$nid, $title, build status=$result");
  #if ( ($result==100) || ($result==200) ) {
  if ( $result == variable_get('webfact_build_done_value', 200) ) {
    // already done, jump back asap
    $context['results']['buildstatus'] = $result;
    $context['results']['done'] = true;
  } else {
    sleep($timer);
    $result = $w->getContainerBuildStatus($nid);
    if ($result<100) {
      $context['results']['log'][] = "building: installing Drupal ($result)";
      $context['message'] = "waiting on Drupal installation ($result)";
    } else {  // post install, e.g. puppet
      $context['results']['log'][] = "building: tuning container ($result)";
      $context['message'] = "waiting on container tuning ($result)";
    }
    $context['results']['buildstatus'] = $result;
  }
  $context['sandbox']['build'] = $result;
  $context['finished'] = 1;
}

function batchWaitInstalled($nid, $title, $timer, $count, &$context) {
  if ( isset($context['results']['done']) ) {
    watchdog('webfact', "batchWaitInstalled ignore since results=done");
    $context['finished'] = 1;
    return;
  }
  $context['finished'] = 0;
  $w= new WebfactController(0, $nid);
  for ($i = 0; $i < $count; $i++) {   // max 100 secs, to avoid browser timeout
    $result = $w->getContainerBuildStatus($nid);
    watchdog('webfact', "batchWaitInstalled $i nid=$nid, $title, build status=$result");
    if ( $result == variable_get('webfact_build_done_value', 200) ) {
      $context['results']['log'][] = date("H:i:s") . " $result";
      $context['results']['done'] = true;
      // done, jump back asap
      break;
    }
    $context['results']['buildstatus'] = $result;
    if ($result<100) {   
      $context['results']['log'][] = "building: installing Drupal ($result)";
      $context['message'] = "waiting on Drupal installation ($result)";
    } else {  // post install, e.g. puppet
      $context['results']['log'][] = "building: tuning container ($result)";
      $context['message'] = "waiting on container tuning ($result)";
    }
    sleep($timer);           // wait
  }
  $context['results']['log'][] = date("H:i:s") . " building still waiting: $result";
  $context['finished'] = 1;
}



/*
 * status results
 */
function batchRebuildDone($success, $results, $operations) {
  if ($success) {
    if ( $results['buildstatus'] == variable_get('webfact_build_done_value', 200) ) {
    # if ($results['buildstatus']==100 || $results['buildstatus']==200) {
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
  // Provide data for the redirected page via $_SESSION.
  if (isset($results['log'])) {
    $_SESSION['batch_results'] = $results['log'];
  }
  drupal_set_message($message);
}

function batchDone($success, $results, $operations) {
  if ($success) {
    $message = t('Done.');
  }
  else {
    $message = t('The operation had issues.');
  }
  if (isset($results['log'])) {
    $_SESSION['batch_results'] = $results['log'];
  }
  drupal_set_message($message);
}
