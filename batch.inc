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
function batchSaveCont($nid, $name, $force=0, &$context) {
  $context['finished'] = 0;
  if ( (variable_get('webfact_rebuild_backups', 1) == 1 ) 
    || ($force==1) ) {
    watchdog('webfact', "batchSaveCont $name");
    $w= new WebfactController;
    $result = $w->backupContainer($name);
    $context['message'] = "saved image of $name to $result";
    $context['results']['log'][] =  date("H:i:s") . " saved image to $result";
  } else {
    $context['message'] = "no backup needed";
    $context['results']['log'][] =  date("H:i:s") . " No backup done: rebuild backups disabled.";
  }
  $context['finished'] = 1;
}

function batchRemoveCont($nid, $title, $log=1, &$context) {
  $context['finished'] = 0;
  watchdog('webfact', "batchRemoveCont $nid, $title");
  $w= new WebfactController;
  $w->deleteContainer($nid, $title, $log);
  if ($log>0) {
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

function batchRemoveContDBData($nid, $title, $log=1, &$context) {
  $context['finished'] = 0;
  watchdog('webfact', "batchRemoveContData $nid, $title");
  $w= new WebfactController;
  $w->deleteContainerDB($nid, $title);
  $w->deleteContainerData($nid, $title);
  if ($log==1) {
    $context['results']['log'][] = 'deleted DB/data for ' . check_plain($title);
  }
  $context['message'] = date("H:i:s"). ' deleted DB/data for ' . check_plain($title);
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
  $context['results']['log'][] = date("H:i:s") . " rename $oldname to $newname";
  $context['finished'] = 1;
}

function batchCommandCont($cmd, $id, &$context) {
  $context['finished'] = 0;
  watchdog('webfact', "batchCommandCont $id cmd=$cmd.");
  $w= new WebfactController;
  $log = $w->runCommand($cmd, $id);
  $context['message'] = "Run command on $id: " . check_plain($cmd);
  $context['results']['log'][] = date("H:i:s") . ' ' . $log;
  #watchdog('webfact', "batchCommandCont done");
  $context['finished'] = 1;
}


/*
 * delete all "backup" images commintted for a container
 */
function batchDeleteContImages($id, &$context) {
  $context['finished'] = 0;
  watchdog('webfact', "batchDeleteContImages $id");
  $log='';
  $w= new WebfactController;
        $imagemgr = $w->getImageManager();
        #$this->message("Finding images ..", 'status', 3);
        $images = $imagemgr->findAll();
        foreach ($images as $image) {
          if (! strcmp($id, $image->getRepository())) {  // look for names
            $data = $imagemgr->inspect($image);
            #dpm($data);
            $imagename = $image->__toString();
            #$this->message("Deleting $imagename", 'status', 3);
            watchdog('webfact', "deleting $imagename");
            try {
              $imagemgr->remove($image);
            } catch (Exception $e) {   // ignore 409s, seems to work anyway
              if ($e->getResponse()->getStatusCode() == 409) {
                ; // ignore conflicts
                #dpm($e->getResponse());
              } else {
                throw($e);
              }
            }
            $log .= "$imagename, ";
          }
        }
        #$this->message("Deleting done");
  $context['message'] = "Deleted: " . check_plain($log);
  $context['results']['log'][] = date("H:i:s") . ' ' . $log;
  #watchdog('webfact', "batchCommandCont done");
  $context['finished'] = 1;
}


/*
 * logging: append batch log to container log
 */
function batchContLog($nid, $id, $logfile, &$context) {
  $context['finished'] = 0;
  #watchdog('webfact', "batchContLog $logfile");
  if (is_array($context['results']['log'])) {
    $w= new WebfactController(0, $nid);

    foreach ($context['results']['log'] as $line) {
      if (is_array($line) ) {
        $line = implode("|", $line);
      }
      $line = preg_replace('/\s+/', ' ', $line);  // remove new lines
      # Todo: cannot get it to write to the log file:
      $res = $w->runCommand("echo '${line}' >> $logfile");
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
function batchTrack($nid, $id, $timer, $done, &$context) {
  if ( isset($context['results']['done']) ) {
    #watchdog('webfact', "batchTrack ignore since results=done");
    $context['finished'] = 1;
    return;
  }
  $context['finished'] = 0;
  $w= new WebfactController(0, $nid);
  $result = $w->getContainerBuildStatus($nid);
  watchdog('webfact', "batchTrack nid=$nid, $id, build status=$result, done=$done");
  #if ( ($result==100) || ($result==200) ) {
  //if ( $result == variable_get('webfact_build_done_value', 200) ) {
  if ( $result == '' ) {   // something is wrong, abort
    $context['results']['buildstatus'] = "-1";
    $context['results']['log'][] = date("H:i:s") . " , error no build status";
    $context['results']['done'] = true; // flag: no further procesing
  }
  else if ( $result == $done ) {
    // already done, jump back asap
    $context['results']['buildstatus'] = $result;
    $context['results']['done'] = true; // flag: no further processing
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

function batchWaitInstalled($nid, $title, $timer, $count, $done, &$context) {
  if ( isset($context['results']['done']) ) {
    #watchdog('webfact', "batchWaitInstalled ignore since results=done");
    $context['finished'] = 1;
    return;
  }
  $context['finished'] = 0;
  $w= new WebfactController(0, $nid);
  for ($i = 0; $i < $count; $i++) {   // max 100 secs, to avoid browser timeout
    $result = $w->getContainerBuildStatus($nid);
    watchdog('webfact', "batchWaitInstalled $i nid=$nid, $title, build status=$result");
    //if ( $result == variable_get('webfact_build_done_value', 200) ) {
    if ( $result == '' ) {   // something is wrong, abort
      $context['results']['log'][] = date("H:i:s") . " , error no build status";
      $context['results']['done'] = true; // flag: no further procesing
      // done, jump back asap
      break;
    }
    if ( $result == $done ) {
      $context['results']['log'][] = date("H:i:s") . " $result";
      $context['results']['done'] = true; // flag: no further procesing
      // done, jump back asap
      break;
    }
    $context['results']['buildstatus'] = $result;
    if ($result<100) {   
      $context['results']['log'][] = "building: installing Drupal ($result)";
      $context['message'] = "waiting on Drupal installation ($result)";
    } else {  // post install, e.g. puppet
      $context['results']['log'][] = "building: tuning container ($result) done=$done";
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
      $message = t('Container created, installation may still be running in the background. Follow the Build Status below, or visit the logs page.');
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

