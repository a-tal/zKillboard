<?php

require_once '../init.php';

global $baseAddr;

$crestmails = $mdb->getCollection('crestmails');
$rawmails = $mdb->getCollection('rawmails');
$queueProcess = new RedisQueue('queueProcess');
$queueShare = new RedisQueue('queueShare');
$killsLastHour = new RedisTtlCounter('killsLastHour');

$counter = 0;
$timer = new Timer();
while (!Util::exitNow() && $timer->stop() < 115000) {
	$unprocessed = $crestmails->find(array('processed' => false))->sort(['killID' => -1])->limit(10);

	if (!$unprocessed->hasNext()) {
		sleep(1);
	}
	foreach ($unprocessed as $crestmail) {
		if (Util::exitNow()) {
			break;
		}
		$id = $crestmail['killID'];
		$hash = $crestmail['hash'];

		if ($mdb->exists('killmails', ['killID' => $id])) {
			$crestmails->update($crestmail, array('$set' => array('processed' => true)));
			continue;
		}

		$killmail = CrestTools::fetch($id, $hash);
		if (is_integer($killmail)) Util::out("$id $killmail");
		// The following if statements used to be a switch statement, but for some reason it didn't always process correctly
		if ($killmail == 403) {
			$mdb->getCollection("crestmails")->remove(['_id' => $crestmail["_id"]]);
			continue;
		}
		if ($killmail == 503) {
				$crestmails->update($crestmail, array('$set' => array('processed' => false, 'errorCode' => $killmail)));
				continue;
		}
		if ($killmail == 0) {
				$crestmails->update($crestmail, array('$set' => array('processed' => false)));
				continue;
		}
		if (in_array($killmail, [415, 500, '', null])) {
				$crestmails->update($crestmail, array('$set' => array('processed' => null, 'error' => 'Error 415, 500, or null')));
				continue;
		}
		if (is_integer($killmail)) Util::out("after $id $killmail");
		if (is_integer($killmail)) var_dump($killmail);

		unset($crestmail['npcOnly']);
		unset($killmail['zkb']);
		unset($killmail['_id']);

		if (!$mdb->exists('rawmails', ['killID' => (int) $id])) {
			$killsLastHour->add($id);
			if ($killmail == null) {
				Util::out("saving null killmail? id is $id");
			}
			$rawmails->save($killmail);
		}

		if (!validKill($killmail)) {
			$crestmail['npcOnly'] = true;
			$crestmail['processed'] = true;
			$crestmails->save($crestmail);
			continue;
		}

		$killID = @$killmail['killID'];
		if ($killID != 0) {
			$crestmail['processed'] = true;
			$crestmails->save($crestmail);
			$queueProcess->push($killID);
			++$counter;

			$queueShare->push($killID);
		} else {
			$crestmails->update($crestmail, array('$set' => array('processed' => false)));
		}
	}
}
if ($debug && $counter > 0) {
	Util::out('Added '.number_format($counter, 0).' Kills.');
}

function validKill(&$kill)
{
	// Show all pod kills
	$victimShipID = $kill['victim']['shipType']['id'];
	if ($victimShipID == 670 || $victimShipID == 33328) {
		return true;
	}

	foreach ($kill['attackers'] as $attacker) {
		if (@$attacker['character']['id'] > 0) return true;
		if (@$attacker['corporation']['id'] > 1999999) return true;
		if (@$attacker['alliance']['id'] > 0) return true;

		$attackerGroupID = Info::getGroupID(@$attacker['shipType']['id']);
		if ($attackerGroupID == 365 || $attackerGroupID == 99) return true; // Tower or Sentry gun

		if (@$attacker['shipType']['id'] == 34495) return true; // Drifters
		if (@$attacker['corporation']['id'] == 1000125) return true; // Drifters
	}

	return false;
}
