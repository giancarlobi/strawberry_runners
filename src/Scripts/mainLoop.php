<?php

use React\EventLoop\Factory;
use React\EventLoop\LoopInterface;
use React\EventLoop\TimerInterface;
use React\ChildProcess\Process;
use Drupal\Core\State\State;

require __DIR__ . '/../../../../../../vendor/autoload.php';

echo 'MAIN LOOP getmypid: ' . getmypid() . PHP_EOL;

//ToDO: add to main configuration
$alivePeriod = 5;
$queuecheckPeriod = 3;
$idleCycle_timeout = 5;
$max_childProcess = 2;

$loop = Factory::create();
$queue = \Drupal::queue('strawberry_runners');
$cycleBefore_timeout = $idleCycle_timeout;


//Timer to update lastRunTime
$loop->addPeriodicTimer($alivePeriod, function () use ($loop) {
  //update lastRunTime
  $data = unserialize(\Drupal::state()->get('strawberryfield_mainLoop'));
  $data['lastRunTime'] = \Drupal::time()->getCurrentTime();
  \Drupal::state()->set('strawberryfield_mainLoop', serialize($data));
});

//Timer to check queue
$loop->addPeriodicTimer($queuecheckPeriod, function () use ($loop, &$cycleBefore_timeout, $queue, $idleCycle_timeout, $max_childProcess) {

  //decrement idle timeout counter
  --$cycleBefore_timeout;

  //Count queue element
  $totalItems = $queue->numberOfItems();
  echo 'totalItemsinLoop ' . $totalItems . PHP_EOL;

  //Queue empty and timeout then stop
  if (($totalItems < 1) && ($cycleBefore_timeout < 1 )){
    echo 'Idle timeout reached' . PHP_EOL;
    \Drupal::state()->delete('strawberryfield_mainLoop');
    $loop->stop();
  }

  //Queue no empty
  if ($totalItems > 0) {

    //reset idle timeout
    $cycleBefore_timeout = $idleCycle_timeout;

    //process item
    //item status = initialized(1)/running(2)/allDone(3)/allDone with errors(4)
    //
    $item = $queue->claimItem();
    //if not released then next claim will be of next item in queue
    $queue->releaseItem($item);
    $item_id = $item->item_id;
    echo 'Process element:' . $item_id . PHP_EOL;

    //check current item
    $item_state_data_ser = \Drupal::state()->get('strawberryfield_runningItem');

    //no running item
    if (is_null($item_state_data_ser)) {

      //set item status to init (1)
      //item status = initialized(1)/running(2)/allDone(3)/allDone with errors(4)
      $item_state_data = [
        'itemId' => $item_id,
        'itemStatus' => 1,
      ];
      \Drupal::state()->set('strawberryfield_runningItem', serialize($item_state_data));
      echo 'Item ' . $item_id . ' set status ' . $item_state_data['itemStatus'] . PHP_EOL;

      //extract childs to process from item
      //
      //child_uuid includes item_id
      //
      //child_uuid used as key for child_state_data
      //
      //child_ref used to multiple get/set child state
      //
      //child_data[child_uuid][status] = 0:to process 1:processing 2:OK processed 3:error processing
      //child_data[child_uuid][pid] = child pid process
      //
      //
      //ToDO: add something about type of process to run ???!!!

      //TEST. build child_data and child_ref
      $child_number = 3;
      for ($x = 1; $x <= $child_number; $x++) {
        $child_uuid = $item_id . "_Child_" . $x;
        $child_data[$child_uuid]['status'] = 0;
        $child_data[$child_uuid]['pid'] = 0;
        $child_ref[] = $child_uuid;
      }
      //TEST

      if ($child_number > 0) {
        //Item has child to process: push ref on state and push each child data on state by setMultiple
        \Drupal::state()->set('strawberryfield_child_ref', serialize($child_ref));
        foreach ($child_data as $child_uuid => $child_data_value){
          $child_data_ser[$child_uuid] = serialize($child_data_value);
        }
        \Drupal::state()->setMultiple($child_data_ser);
      }
      else {
        //No child to process, set item status allDone (3)
        //item status = initialized(1)/running(2)/allDone(3)/allDone with errors(4)
      $item_state_data = [
          'itemId' => $item_id,
          'itemStatus' => 3,
        ];
        \Drupal::state()->set('strawberryfield_runningItem', serialize($item_state_data));
        echo 'Item ' . $item_id . ' set status ' . $item_state_data['itemStatus'] . PHP_EOL;
      }
    }
    //running item
    else {
      //item status = initialized(1)/running(2)/allDone(3)/allDone with errors(4)
      $item_state_data = unserialize($item_state_data_ser);
      $itemId = $item_state_data['itemId'];
      $itemStatus = $item_state_data['itemStatus'];
      echo 'Item ' . $itemId . ' status ' . $itemStatus . PHP_EOL;

      //initialized, switch to running, child data and ref already on state
      if ($itemStatus == 1) {
        //Set item status running(2)
        //item status = initialized(1)/running(2)/allDone(3)/allDone with errors(4)
        $item_state_data['itemStatus'] = 2;
        \Drupal::state()->set('strawberryfield_runningItem', serialize($item_state_data));
      }

      //running (processing child)
      //item status = initialized(1)/running(2)/allDone(3)/allDone with errors(4)
      if ($itemStatus == 2) {

        //child status = 0:to process 1:processing 2:OK processed 3:error processing
        //check child status then ...
        $totalChild = 0;
        $totalChild_status = array_fill(0, 4, 0);
        $child_ref = unserialize(\Drupal::state()->get('strawberryfield_child_ref'));
        $child_data_ser = \Drupal::state()->getMultiple($child_ref);
        foreach ($child_data_ser as $child_uuid => $child_data_value_ser){
          $child_data[$child_uuid] = unserialize($child_data_value_ser);

          $totalChild++;
          $totalChild_status[$child_data[$child_uuid]['status']]++;
        }

//TEST
        print_r($child_data);
        print_r($totalChild_status);
//TEST

        //... child allDONE OK
        if ($totalChild_status[2] == $totalChild){
          //Set item status allDone(3) without errors
          //item status = initialized(1)/running(2)/allDone(3)/allDone with errors(4)
          $item_state_data['itemStatus'] = 3;
          \Drupal::state()->set('strawberryfield_runningItem', serialize($item_state_data));
          echo 'Item ' . $item_id . ' set status ' . $item_state_data['itemStatus'] . PHP_EOL;
        }

        //... child allDONE with errors
        if (($totalChild_status[2] + $totalChild_status[3]) == $totalChild){
          //Set item status allDone(4) with errors
          //item status = initialized(1)/running(2)/allDone(3)/allDone with errors(4)
          $item_state_data['itemStatus'] = 4;
          \Drupal::state()->set('strawberryfield_runningItem', serialize($item_state_data));
          echo 'Item ' . $item_id . ' set status ' . $item_state_data['itemStatus'] . PHP_EOL;
        }

        //... child processing = max
        if ($totalChild_status[1] == $max_childProcess){
          //ToDO: check running process alive
          //wait next cycle
        }

        //... some child to process
        elseif ($totalChild_status[0] > 0){

          //search first child to process => $child_uuid
          foreach ($child_data as $c_uuid => $c_stat) {
            if ($c_stat['status'] == 0) {
              $child_uuid = $c_uuid;
              break;
            }
          }

          //start process $child_uuid
          echo '***** start process: ' . $child_uuid . PHP_EOL;

          $drush_path = "/var/www/archipelago/vendor/drush/drush/";
          $childProcess_path = "/var/www/archipelago/web/modules/contrib/strawberry_runners/src/Scripts";
          //added child_uuid as variable to child process call
          $cmd = $drush_path . 'drush scr --script-path=' . $childProcess_path . ' childTestProcess -- ' . $child_uuid;

          $process = new Process($cmd, null, null, null);
          $process->start($loop);

          $process->stdout->on('data', function ($chunk) use (&$child_data, $child_uuid){
            //read pid from child then set data on state
            $child_data[$child_uuid]['pid'] = (unserialize($chunk))['pid'];
            $child_data[$child_uuid]['status'] = 1;
            \Drupal::state()->set($child_uuid, serialize($child_data[$child_uuid]));
          });

          $process->on('exit', function ($exit, $term) use (&$child_data, $child_uuid){

            //set child done ok
            //ToDO: check if really ok
            $child_data[$child_uuid]['status'] = 2;
            \Drupal::state()->set($child_uuid, serialize($child_data[$child_uuid]));

            if ($term === null) {
              echo '*****exit with code ' . $code . ' process: ' . $child_uuid . PHP_EOL;
            } else {
              echo '*****terminated with signal ' . $term . ' process: ' . $child_uuid . PHP_EOL;
            }

          });
          //ToDO: do we have to add process timeout???
          //$loop_child->addTimer(2.0, function () use ($process) {
          //    foreach ($process->pipes as $pipe) {
          //        $pipe->close();
          //    }
          //    $process->terminate();
          //});
          //TEST
        }
      }

      //allDone without errors
      //item status = initialized(1)/running(2)/allDone(3)/allDone with errors(4)
      if ($itemStatus == 3) {
        //delete runningItem and child ref and data from state
        \Drupal::state()->delete('strawberryfield_runningItem');
        $c_ref = unserialize(\Drupal::state()->get('strawberryfield_child_ref'));
        \Drupal::state()->deleteMultiple($c_ref);
        \Drupal::state()->delete('strawberryfield_child_ref');
        //remove item from queue
        $queue->deleteItem($item);
      }

      //allDone with errors
      //item status = initialized(1)/running(2)/allDone(3)/allDone with errors(4)
      if ($itemStatus == 4) {
        //
        //ToDO!!
        //
        //remove runningItem and child ref and data
        \Drupal::state()->delete('strawberryfield_runningItem');
        $c_ref = unserialize(\Drupal::state()->get('strawberryfield_child_ref'));
        \Drupal::state()->deleteMultiple($c_ref);
        \Drupal::state()->delete('strawberryfield_child_ref');
        //remove item from queue
        $queue->deleteItem($item);
      }
    }
  }
});

//Force mainLoop stop after 120s for test purpose
$loop->addTimer(120, function () use ($loop) {
  echo 'before stop' . PHP_EOL;
  $loop->stop();
});
//

$loop->run();

?>
