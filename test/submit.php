<?php

use Drupal\Core\State\State;

//build command
$drush_path = "/var/www/archipelago/vendor/drush/drush/";
$mainLoop_path = "/var/www/archipelago/web/modules/contrib/strawberryfield/src/Scripts";
$cmd = $drush_path . 'drush scr test --script-path=' . $mainLoop_path;
$outputfile = "/tmp/runners.log";


//check state
$data = \Drupal::state()->get('strawberryfield_mainLoop');
$data = explode(',', $data);
$data = array_pad($data, 2, 0);
list($processId, $lastRunTime) = $data;

//$lastRunTime = 0 means state no exist
$delta = \Drupal::time()->getCurrentTime() - $lastRunTime;

if ($delta < 10) {
	echo 'mainLoop running' . PHP_EOL;
}
else {
	echo 'mainLoop to start' . PHP_EOL;
  $pid = shell_exec(sprintf("%s > %s 2>&1 & echo $!", $cmd, $outputfile));
  $data = [
		'processId' => $pid,
    'lastRunTime' => \Drupal::time()->getCurrentTime(),
  ];
  \Drupal::state()->set('strawberryfield_mainLoop', implode(',', $data));
}

?>
