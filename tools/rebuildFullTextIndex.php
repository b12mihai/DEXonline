<?
require_once('../phplib/util.php');
ini_set('max_execution_time', '3600');
ini_set('memory_limit', '128000000');
assert_options(ASSERT_BAIL, 1);

log_scriptLog('Running rebuildFullTextIndex.php.');
if (!lock_acquire(LOCK_FULL_TEXT_INDEX)) {
  log_scriptLog('Exiting: lock already exists!');
  exit;
}

log_scriptLog("Clearing table FullTextIndex.");
mysql_query('delete from FullTextIndex');

$ifMap = array();
$dbResult = mysql_query('select id, internalRep from Definition where status = 0');
$numDefs = mysql_num_rows($dbResult);
$defsSeen = 0;
$indexSize = 0;
$fileName = tempnam('/tmp', 'index_');
$handle = fopen($fileName, 'w');
log_scriptLog("Writing index to file $fileName.");
debug_init();
debug_off();

while (($dbRow = mysql_fetch_row($dbResult)) != null) {
  $words = extractWords($dbRow[1]);

  foreach ($words as $position => $word) {
    if (text_isStopWord($word, true)) {
      // Nothing, this word is ignored.
    } else {
      if (!array_key_exists($word, $ifMap)) {
        cacheWordForm($word);
      }
      if (array_key_exists($word, $ifMap)) {
        $lexemList = split(',', $ifMap[$word]);
        for ($i = 0; $i < count($lexemList); $i += 2) {
          fwrite($handle, $lexemList[$i] . "\t" . $lexemList[$i + 1] . "\t" . $dbRow[0] . "\t" . $position . "\n");
          $indexSize++;
        }
      } else {
        // print "Not found: $word\n";
      }
    }
  }

  if (++$defsSeen % 10000 == 0) {
     $runTime = debug_getRunningTimeInMillis() / 1000;
     $speed = round($defsSeen / $runTime);
     log_scriptLog("$defsSeen of $numDefs definitions indexed ($speed defs/sec). " .
                   "Word map has " . count($ifMap) . " entries. " .
                   "Memory used: " . round(memory_get_usage() / 1048576, 1) . " MB.");
  }
}

fclose($handle);
log_scriptLog("$defsSeen of $numDefs definitions indexed.");
log_scriptLog("Index size: $indexSize entries.");

os_executeAndAssert("chmod 666 $fileName");
log_scriptLog("Importing file $fileName into table FullTextIndex");
mysql_query("load data infile '$fileName' into table FullTextIndex");
util_deleteFile($fileName);

if (!lock_release(LOCK_FULL_TEXT_INDEX)) {
  log_scriptLog('WARNING: could not release lock!');
}
log_scriptLog('rebuildFullTextIndex.php completed successfully ' .
              '(against all odds)');

/***************************************************************************/

function extractWords($text) {
  $alphabet = 'abcdefghijklmnopqrstuvwxyzăâîșț';

  $text = text_unicodeToLower($text);
  $text = text_removeAccents($text);
  $result = array();

  $currentWord = '';
  $chars = text_unicodeExplode($text);
  foreach ($chars as $c) {
    if (strpos($alphabet, $c) !== false) {
      $currentWord .= $c;
    } else {
      if ($currentWord) {
        $result[] = $currentWord;
      }
      $currentWord = '';
    }
  }

  if ($currentWord) {
    $result[] = $currentWord;
  }

  return $result;
}

function cacheWordForm($word) {
  global $ifMap;
  $dbResult = mysql_query("select lexemId, inflectionId from InflectedForm where formNoAccent = '$word'");
  $value = '';
  while (($dbRow = mysql_fetch_assoc($dbResult)) != null) {
    $value .= ',' . $dbRow['lexemId'] . ',' . $dbRow['inflectionId'];
  }
  mysql_free_result($dbResult);
  if ($value) {
    $ifMap[$word] = substr($value, 1);
  }
}

?>
